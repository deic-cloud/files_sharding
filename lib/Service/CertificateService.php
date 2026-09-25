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

	/**
	 * The subject DN this service issues for a user, whether or not a
	 * certificate currently exists. Used to tell one of OUR certificates apart
	 * from a DN the user registered for a certificate issued elsewhere, which we
	 * cannot say anything about.
	 */
	public function issuedDn(string $userId): string {
		$org = $this->config->getSystemValueString('files_sharding_cert_org', 'sciencedata.dk');
		return '/CN=' . $userId . '/O=' . $org;
	}

	/**
	 * Serial of the certificate the user currently holds, normalised to
	 * uppercase hex without leading zeros; '' when there is none.
	 *
	 * This is the revocation handle. Regenerating mints a new serial and
	 * deleting leaves none, so a certificate that is no longer the current one
	 * can be told apart from the one that is — see X509Backend.
	 */
	public function currentSerial(string $userId): string {
		$certFile = $this->certDir($userId) . '/usercert.pem';
		if (!file_exists($certFile)) {
			return '';
		}
		$cert = openssl_x509_read((string)file_get_contents($certFile));
		if ($cert === false) {
			return '';
		}
		$info = openssl_x509_parse($cert);
		if (!is_array($info)) {
			return '';
		}
		return self::normalizeSerial((string)($info['serialNumberHex'] ?? ''));
	}

	/**
	 * SHA-256 of the certificate the user currently holds, uppercase hex; ''
	 * when there is none.
	 *
	 * Stronger than the serial, and the difference matters in exactly one case:
	 * someone holding the CA key can mint a certificate with any subject and any
	 * serial they like, but not one with the user's public key, because they do
	 * not have the user's private key. A fingerprint therefore survives a CA
	 * compromise; a serial does not.
	 */
	public function currentFingerprint(string $userId): string {
		$certFile = $this->certDir($userId) . '/usercert.pem';
		if (!file_exists($certFile)) {
			return '';
		}
		$digest = openssl_x509_fingerprint((string)file_get_contents($certFile), 'sha256');
		return $digest === false ? '' : strtoupper($digest);
	}

	/** SHA-256 of a PEM certificate as presented, uppercase hex; '' if unreadable. */
	public static function fingerprintOf(string $pem): string {
		$pem = trim($pem);
		if ($pem === '') {
			return '';
		}
		// Apache folds the PEM it exports; unfold before parsing.
		if (!str_contains($pem, "\n")) {
			$pem = str_replace(['-----BEGIN CERTIFICATE----- ', ' -----END CERTIFICATE-----'],
				["-----BEGIN CERTIFICATE-----\n", "\n-----END CERTIFICATE-----"], $pem);
			$pem = (string)preg_replace('/(?<!-)\s+(?!-)/', "\n", $pem);
		}
		$digest = openssl_x509_fingerprint($pem, 'sha256');
		return $digest === false ? '' : strtoupper($digest);
	}

	/**
	 * Serials are written differently by everyone who writes them: Apache's
	 * SSL_CLIENT_M_SERIAL, openssl's `-serial`, and PHP's parser differ in case,
	 * separators and leading zeros. Compare the number, not the spelling.
	 *
	 * Zero is a number here, not an absence. Certificates issued before this app
	 * gave each one a random serial all carry serial 0, and several accounts on
	 * the running service still hold one; treating that as "no serial" would shut
	 * them out. It does mean such a certificate cannot be told apart from an
	 * older copy of itself — the cure is to regenerate it, and
	 * X509Backend::certificateIsCurrent() says so in the log.
	 */
	public static function normalizeSerial(string $serial): string {
		$hex = strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '', $serial));
		if ($hex === '') {
			return '';
		}
		$hex = ltrim($hex, '0');
		return $hex === '' ? '0' : $hex;
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
			// The key is encrypted with the instance 'secret'; a changed secret
			// leaves it unopenable. Regenerating the certificate creates a new key.
			$this->logger->warning("files_sharding: CertificateService: cannot open {$keyFile} with the current secret — the certificate must be regenerated");
			return '';
		}
		$pem = '';
		openssl_pkey_export($privKey, $pem);
		return $pem;
	}

	/**
	 * The certificate's RSA key as an OpenSSH public key line ("ssh-rsa AAAA… <comment>"),
	 * or '' — the id_rsa.pub of the old service: the same key serves SSH (pods,
	 * SFTP) as the X.509 certificate, so there is nothing extra to generate.
	 */
	public function getSshPublicKey(string $userId, string $comment = ''): string {
		$pem = $this->getKeyPem($userId);
		$key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
		$d = $key !== false ? openssl_pkey_get_details($key) : false;
		if (!is_array($d) || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
			return '';
		}
		$str = static fn (string $s): string => pack('N', strlen($s)) . $s;
		// SSH mpint: big-endian, with a leading zero byte when the top bit is set.
		$mpint = static fn (string $b): string => $str((ord($b[0]) & 0x80) ? "\0" . $b : $b);
		$blob = $str('ssh-rsa') . $mpint($d['rsa']['e']) . $mpint($d['rsa']['n']);
		return 'ssh-rsa ' . base64_encode($blob) . ($comment !== '' ? ' ' . $comment : '') . "\n";
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
		// A unique serial per certificate. openssl_csr_sign() defaults to 0, so
		// every certificate this CA issued shared issuer+serial "00" — and CMS
		// signatures (PDF signing) identify the signer by exactly that pair, so
		// a verifier handed two such certificates can match the wrong one.
		$serial = random_int(1, PHP_INT_MAX);

		if ($caCertPath !== '' && $caKeyPath !== '' && file_exists($caCertPath) && file_exists($caKeyPath)) {
			$caCert = openssl_x509_read((string)file_get_contents($caCertPath));
			$caKey  = openssl_pkey_get_private((string)file_get_contents($caKeyPath));
			if ($caCert !== false && $caKey !== false) {
				return openssl_csr_sign($csr, $caCert, $caKey, $days, ['digest_alg' => 'sha256'], $serial);
			}
			$this->logger->warning('files_sharding: CertificateService: could not read CA cert/key, falling back to self-signed');
		}

		// Self-sign
		return openssl_csr_sign($csr, null, $privKey, $days, ['digest_alg' => 'sha256'], $serial);
	}
}
