<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Generates and manages per-user RSA-4096 X.509 certificates signed by the
 * deployment CA (or self-signed if no CA is configured).
 *
 * Files are stored under <datadirectory>/<userId>/files_sharding_ssl/:
 *   userkey.pem  — passphrase-protected private key
 *   usercert.pem — signed certificate
 *
 * Config keys (in config.php):
 *   my_ca_certificate   — path to CA certificate file (optional)
 *   my_ca_privatekey    — path to CA private key file (optional, unencrypted)
 *   secret              — passphrase used to encrypt the user private key
 *   files_sharding_cert_org — organization name in certificate subject (default: sciencedata.dk)
 */
class CertificateService {
	public function __construct(
		private IConfig       $config,
		private LoggerInterface $logger,
	) {
	}

	public function generateCertificate(string $userId, int $days = 365): array|false {
		$dir = $this->ensureCertDir($userId);
		if ($dir === null) {
			return false;
		}

		$secret  = $this->config->getSystemValueString('secret', '');
		$org     = $this->config->getSystemValueString('files_sharding_cert_org', 'sciencedata.dk');
		$keyFile = $dir . '/userkey.pem';

		// Reuse existing key if readable, otherwise generate a new RSA-4096 key.
		$privKey = false;
		if (file_exists($keyFile)) {
			$privKey = openssl_pkey_get_private('file://' . $keyFile, $secret ?: null);
		}
		if ($privKey === false) {
			$privKey = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			if ($privKey === false) {
				$this->logger->error("files_sharding: CertificateService: openssl_pkey_new failed for {$userId}");
				return false;
			}
			$keyPem = '';
			if (!openssl_pkey_export($privKey, $keyPem, $secret ?: null)) {
				$this->logger->error("files_sharding: CertificateService: openssl_pkey_export failed for {$userId}");
				return false;
			}
			file_put_contents($keyFile, $keyPem);
			chmod($keyFile, 0600);
		}

		$dn  = ['commonName' => $userId, 'organizationName' => $org];
		// Use a minimal OpenSSL config (prompt = no, empty [dn]) so the system
		// openssl.cnf's [req_distinguished_name] demo defaults (C=AU, ST=Some-State, …)
		// don't leak into the subject. The batch service authorises by exact DN string,
		// so it must be precisely /CN=<user>/O=<org> — matching the original chooser
		// app's `openssl req -subj "/CN=$user/O=sciencedata.dk"` behaviour.
		$confFile = $dir . '/openssl-req.cnf';
		file_put_contents($confFile, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
		chmod($confFile, 0600);
		$csr = openssl_csr_new($dn, $privKey, ['digest_alg' => 'sha256', 'config' => $confFile]);
		@unlink($confFile);
		if ($csr === false) {
			$this->logger->error("files_sharding: CertificateService: openssl_csr_new failed for {$userId}");
			return false;
		}

		$signed = $this->signCsr($csr, $privKey, $days);
		if ($signed === false) {
			$this->logger->error("files_sharding: CertificateService: CSR signing failed for {$userId}");
			return false;
		}

		$certPem = '';
		if (!openssl_x509_export($signed, $certPem)) {
			$this->logger->error("files_sharding: CertificateService: openssl_x509_export failed for {$userId}");
			return false;
		}
		file_put_contents($dir . '/usercert.pem', $certPem);

		$dn = "/CN={$userId}/O={$org}";
		// Auto-register the certificate subject for X.509 authentication so the
		// user (and trusted daemons acting on their behalf) can be resolved by
		// cert DN without anyone re-typing it — generating the certificate is
		// the deliberate opt-in, and this avoids typos.
		$this->registerDn($userId, $dn);

		$info    = openssl_x509_parse($signed);
		$validTo = $info['validTo_time_t'] ?? 0;

		return [
			'dn'      => $dn,
			'expires' => date('Y-m-d', (int)$validTo),
		];
	}

	/**
	 * Register a certificate subject DN in the user's x509_dn_{0..9} preferences
	 * (where X509Backend looks it up). Idempotent: skips if already present,
	 * otherwise fills the first free slot.
	 */
	private function registerDn(string $userId, string $dn): void {
		$firstFree = null;
		for ($i = 0; $i < 10; $i++) {
			$existing = $this->config->getUserValue($userId, 'files_sharding', "x509_dn_{$i}", '');
			if ($existing === $dn) {
				return;
			}
			if ($existing === '' && $firstFree === null) {
				$firstFree = $i;
			}
		}
		if ($firstFree !== null) {
			$this->config->setUserValue($userId, 'files_sharding', "x509_dn_{$firstFree}", $dn);
		}
	}

	public function getCertInfo(string $userId): ?array {
		$certFile = $this->certDir($userId) . '/usercert.pem';
		if (!file_exists($certFile)) {
			return null;
		}
		$certPem = file_get_contents($certFile);
		$cert    = openssl_x509_read($certPem);
		if ($cert === false) {
			return null;
		}
		$info = openssl_x509_parse($cert);
		if ($info === false) {
			return null;
		}

		$subject = '';
		if (isset($info['subject']) && is_array($info['subject'])) {
			$parts = [];
			foreach ($info['subject'] as $k => $v) {
				$parts[] = "{$k}={$v}";
			}
			$subject = implode(', ', $parts);
		}

		return [
			'dn'      => $subject,
			'expires' => date('Y-m-d', (int)($info['validTo_time_t'] ?? 0)),
		];
	}

	/** Returns the decrypted private key as PEM, or empty string if none exists. */
	public function getKeyPem(string $userId): string {
		$keyFile = $this->certDir($userId) . '/userkey.pem';
		if (!file_exists($keyFile)) {
			return '';
		}
		$secret  = $this->config->getSystemValueString('secret', '');
		$privKey = openssl_pkey_get_private('file://' . $keyFile, $secret ?: null);
		if ($privKey === false) {
			return '';
		}
		$pem = '';
		openssl_pkey_export($privKey, $pem);
		return $pem;
	}

	/** Returns the certificate as PEM, or empty string if none exists. */
	public function getCertPem(string $userId): string {
		$certFile = $this->certDir($userId) . '/usercert.pem';
		return file_exists($certFile) ? (string)file_get_contents($certFile) : '';
	}

	/** Returns a PKCS#12 bundle (with empty export passphrase), or empty string. */
	public function getPkcs12(string $userId): string {
		$certPem = $this->getCertPem($userId);
		$keyPem  = $this->getKeyPem($userId);
		if ($certPem === '' || $keyPem === '') {
			return '';
		}
		$pkcs12 = '';
		openssl_pkcs12_export($certPem, $pkcs12, $keyPem, '');
		return $pkcs12;
	}

	/** Deletes all certificate and key files for $userId. Returns false if nothing existed. */
	public function deleteCertificate(string $userId): bool {
		$dir   = $this->certDir($userId);
		$files = ['userkey.pem', 'usercert.pem'];
		$found = false;
		foreach ($files as $f) {
			$path = $dir . '/' . $f;
			if (file_exists($path)) {
				unlink($path);
				$found = true;
			}
		}
		return $found;
	}

	private function certDir(string $userId): string {
		$dataDir = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
		return $dataDir . '/' . $userId . '/files_sharding_ssl';
	}

	private function ensureCertDir(string $userId): ?string {
		$dir = $this->certDir($userId);
		if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
			$this->logger->error("files_sharding: CertificateService: cannot create cert dir {$dir}");
			return null;
		}
		return $dir;
	}

	/** @param \OpenSSLCertificateSigningRequest $csr */
	private function signCsr(mixed $csr, mixed $privKey, int $days): mixed {
		$caCertPath = $this->config->getSystemValueString('my_ca_certificate', '');
		$caKeyPath  = $this->config->getSystemValueString('my_ca_privatekey', '');

		if ($caCertPath !== '' && $caKeyPath !== '' && file_exists($caCertPath) && file_exists($caKeyPath)) {
			$caCert = openssl_x509_read((string)file_get_contents($caCertPath));
			$caKey  = openssl_pkey_get_private((string)file_get_contents($caKeyPath));
			if ($caCert !== false && $caKey !== false) {
				return openssl_csr_sign($csr, $caCert, $caKey, $days, ['digest_alg' => 'sha256']);
			}
			$this->logger->warning('files_sharding: CertificateService: could not read CA cert/key, falling back to self-signed');
		}

		// Self-sign
		return openssl_csr_sign($csr, null, $privKey, $days, ['digest_alg' => 'sha256']);
	}
}
