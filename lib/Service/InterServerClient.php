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
 * For container/dev testing set 'files_sharding_verify_ssl' => false.
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
			'verify'  => $this->verifySsl,
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
			'verify'  => $this->verifySsl,
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
			'verify'  => $this->verifySsl,
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
