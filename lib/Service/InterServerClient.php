<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Authenticated HTTP client for master↔silo calls.
 *
 * Authentication uses a shared secret set in config.php as
 * 'files_sharding_shared_secret'. The secret is sent as the
 * Authorization header: "Bearer <secret>".
 *
 * TLS is verified PER TARGET: a call to a public hostname is verified normally; a
 * call to a private/backend IP (10/8, 172.16/12, 192.168/16) is NOT — those never
 * match a public hostname cert and travel our trusted, firewalled backend network
 * with shared-secret auth. Setting 'files_sharding_verify_ssl' => false forces
 * verification off everywhere (self-signed dev/container/pod certs on hostnames).
 */
class InterServerClient {
	private string $secret;
	private bool   $verifySsl;

	public function __construct(
		private IClientService  $clientService,
		private IConfig         $config,
		private LoggerInterface $logger,
	) {
		$this->secret    = (string)$config->getSystemValue('files_sharding_shared_secret', '');
		$this->verifySsl = (bool)$config->getSystemValue('files_sharding_verify_ssl', true);
	}

	/**
	 * Whether to verify the TLS cert for a call to $baseUrl. Verify by default, but
	 * NOT for a private/backend IP target (10/8, 172.16/12, 192.168/16, other reserved
	 * ranges): those are on the trusted, firewalled backend network, addressed by IP,
	 * so their cert can never match a public hostname — and needn't (shared-secret
	 * authed). A public hostname/IP is always verified. The global
	 * 'files_sharding_verify_ssl' => false forces it off everywhere.
	 */
	private function verifyFor(string $baseUrl): bool {
		if (!$this->verifySsl) {
			return false;
		}
		$host = (string)parse_url($baseUrl, PHP_URL_HOST);
		if ($host !== ''
			&& filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			// Target is a private/reserved IP → trusted backend → skip verification.
			return false;
		}
		return true;
	}

	/**
	 * GET $baseUrl/ocs/v2.php/apps/files_sharding/$path
	 * Returns decoded ocs.data array, or null on failure.
	 */
	public function get(string $baseUrl, string $path, array $query = []): ?array {
		return $this->request('GET', $baseUrl, $path, $query);
	}

	/**
	 * POST $baseUrl/ocs/v2.php/apps/files_sharding/$path
	 */
	public function post(string $baseUrl, string $path, array $body = []): ?array {
		return $this->request('POST', $baseUrl, $path, [], $body);
	}

	/**
	 * GET $baseUrl/index.php/apps/$app/$path (plain, non-OCS endpoint).
	 * Returns the decoded JSON body directly, without unwrapping an OCS envelope.
	 */
	public function getDirect(string $baseUrl, string $path, array $query = [], string $app = 'files_sharding'): ?array {
		if ($this->secret === '') {
			$this->logger->error('files_sharding_shared_secret is not configured');
			return null;
		}

		$url = rtrim($baseUrl, '/') . '/index.php/apps/' . $app . '/' . ltrim($path, '/');
		if ($query) {
			$url .= '?' . http_build_query($query);
		}

		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret,
				'Accept'        => 'application/json',
			],
			'verify'  => $this->verifyFor($baseUrl),
			'timeout' => 10,
		];

		try {
			$client   = $this->clientService->newClient();
			$response = $client->get($url, $options);
			$raw      = (string)$response->getBody();
			$data     = json_decode($raw, true);
			if (!is_array($data)) {
				$this->logger->warning("files_sharding: GET {$url} non-JSON body: " . substr($raw, 0, 500));
				return null;
			}
			return $data;
		} catch (\Throwable $e) {
			$this->logger->warning("files_sharding: GET {$url} failed: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * POST $baseUrl/index.php/apps/$app/$path (plain, non-OCS endpoint).
	 * Returns the decoded JSON body directly, without unwrapping an OCS envelope.
	 */
	public function postDirect(string $baseUrl, string $path, array $body = [], string $app = 'files_sharding'): ?array {
		if ($this->secret === '') {
			$this->logger->error('files_sharding_shared_secret is not configured');
			return null;
		}

		$url = rtrim($baseUrl, '/') . '/index.php/apps/' . $app . '/' . ltrim($path, '/');

		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret,
				'Accept'        => 'application/json',
			],
			'verify'  => $this->verifyFor($baseUrl),
			'timeout' => 10,
		];

		try {
			$client   = $this->clientService->newClient();
			$response = $client->post($url, $options + ['form_params' => $body]);
			$raw      = (string)$response->getBody();
			$data     = json_decode($raw, true);
			if (!is_array($data)) {
				$this->logger->warning("files_sharding: POST {$url} non-JSON body: " . substr($raw, 0, 500));
				return null;
			}
			return $data;
		} catch (\Throwable $e) {
			$this->logger->warning("files_sharding: POST {$url} failed: " . $e->getMessage());
			return null;
		}
	}

	private function request(string $method, string $baseUrl, string $path, array $query = [], array $body = []): ?array {
		if ($this->secret === '') {
			$this->logger->error('files_sharding_shared_secret is not configured');
			return null;
		}

		$url = rtrim($baseUrl, '/') . '/ocs/v2.php/apps/files_sharding/' . ltrim($path, '/');
		if ($query) {
			$url .= '?' . http_build_query($query);
		}

		$options = [
			'headers' => [
				'Authorization'   => 'Bearer ' . $this->secret,
				'OCS-APIREQUEST'  => 'true',
				'Accept'          => 'application/json',
			],
			'verify'  => $this->verifyFor($baseUrl),
			'timeout' => 10,
		];

		try {
			$client   = $this->clientService->newClient();
			$response = $method === 'POST'
				? $client->post($url,  $options + ['form_params' => $body])
				: $client->get($url,   $options);

			$body = (string)$response->getBody();
			$data = json_decode($body, true);
			$result = $data['ocs']['data'] ?? null;
			if ($result === null) {
				$this->logger->warning("files_sharding: {$method} {$url} returned unexpected body: " . substr($body, 0, 500));
			}
			return $result;
		} catch (\Throwable $e) {
			$this->logger->warning("files_sharding: {$method} {$url} failed: " . $e->getMessage());
			return null;
		}
	}
}
