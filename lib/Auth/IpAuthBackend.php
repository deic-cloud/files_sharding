<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Auth;

use OCP\Authentication\IApacheBackend;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use Psr\Log\LoggerInterface;

/**
 * IP-based trusted data access (the second trusted-access mechanism alongside
 * the X.509 relay in X509Backend). For requests originating on the trusted user
 * VLAN (system config 'trustednet', e.g. "10.2."), the source IP is mapped to
 * the owner of the container running there, and the request is authenticated as
 * that owner — so a user's own pod reaches that user's files without a password.
 *
 * If the owning container belongs to the configured trusted user
 * ('files_sharding_trusted_user', e.g. "cloud"), the Basic-auth username is
 * honoured instead (impersonation) — this is how cloud-owned service pods act on
 * behalf of any user. Empty/any password.
 *
 * The IP→owner map comes from an external container service (system config
 * 'files_sharding_pod_list_url', gated by 'files_sharding_pod_list_password')
 * that shares this instance's user IDs. It is cached briefly.
 *
 * Precedence (enforced by yielding): X.509 relay (SSL-CLIENT-S-DN present) wins,
 * then this IP mechanism, then normal password/session auth. A request carrying
 * a real Basic-auth password is treated as a normal login and yielded.
 */
class IpAuthBackend extends ABackend implements IUserBackend, IApacheBackend {
	private const CACHE_TTL = 60; // seconds

	public function __construct(
		private IRequest        $request,
		private IConfig         $config,
		private IUserManager    $userManager,
		private IClientService  $clientService,
		private ICacheFactory   $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	// ── IApacheBackend ────────────────────────────────────────────────────────

	public function isSessionActive(): bool {
		return $this->resolveUser() !== '';
	}

	public function getCurrentUserId(): string {
		return $this->resolveUser();
	}

	public function getLogoutUrl(): string {
		return '';
	}

	// ── UserInterface stubs (users already exist; we never create/own them) ────

	public function getBackendName(): string {
		return 'FilesShardingIpAuth';
	}
	public function userExists($uid): bool {
		return false;
	}
	public function deleteUser($uid): bool {
		return false;
	}
	public function getUsers($search = '', $limit = null, $offset = null): array {
		return [];
	}
	public function getDisplayName($uid): string {
		return (string)$uid;
	}
	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		return [];
	}
	public function hasUserListings(): bool {
		return false;
	}

	// ── Resolution ─────────────────────────────────────────────────────────────

	/**
	 * The user this request should authenticate as, or '' if the IP mechanism
	 * does not apply / does not resolve to an existing account.
	 */
	private function resolveUser(): string {
		// 1. Must originate on the trusted VLAN (or loopback). getRemoteAddress()
		//    honours trusted_proxies if one is ever put in front; today it is the
		//    raw peer address.
		$net = trim($this->config->getSystemValue('trustednet', ''));
		$ip = $this->request->getRemoteAddress();
		if ($ip === '') {
			return '';
		}
		$onTrustedNet = ($net !== '' && str_starts_with($ip, $net)) || $ip === '127.0.0.1' || $ip === '::1';
		if (!$onTrustedNet) {
			return '';
		}

		// 2. Yield to the X.509 relay: if a client-cert DN is present, X509Backend
		//    handles this request.
		if (trim($this->request->getHeader('SSL-CLIENT-S-DN')) !== ''
			|| trim($this->request->getHeader('X-Ssl-Client-S-Dn')) !== '') {
			return '';
		}

		// 3. Basic-auth creds (impersonation uses the username with an empty/any
		//    password). A request carrying BOTH a username and a password is a
		//    normal login attempt — yield so password auth handles it.
		[$authUser, $authPw] = $this->basicAuth();
		if ($authUser !== '' && $authPw !== '') {
			return '';
		}

		// 4. Map the source IP to the owner of the container running there.
		$owner = $this->ownerForIp($ip);
		if ($owner === '') {
			return '';
		}

		$trustedUser = trim($this->config->getSystemValue('files_sharding_trusted_user', ''));

		// Container owned by the trusted user → honour the Basic-auth username
		// (impersonation on behalf of any user).
		if ($trustedUser !== '' && $owner === $trustedUser) {
			if ($authUser === '') {
				return '';
			}
			return $this->userManager->userExists($authUser) ? $authUser : '';
		}

		// Ordinary pod: authenticate as its owner. A username may be supplied but
		// must match the owner (you cannot claim to be someone else).
		if ($authUser !== '' && $authUser !== $owner) {
			return '';
		}
		return $this->userManager->userExists($owner) ? $owner : '';
	}

	/** @return array{0:string,1:string} [username, password] from a Basic Authorization header. */
	private function basicAuth(): array {
		$auth = trim($this->request->getHeader('Authorization'));
		if (stripos($auth, 'Basic ') !== 0) {
			return ['', ''];
		}
		$decoded = base64_decode(substr($auth, 6), true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return ['', ''];
		}
		[$u, $p] = explode(':', $decoded, 2);
		return [trim($u), $p];
	}

	/** Owner (NC uid) of the container whose pod IP matches $ip, or ''. */
	private function ownerForIp(string $ip): string {
		foreach ($this->containerList() as $line) {
			// pod_name|container|image|pod_ip|node_ip|owner|age|status|ssh_port|ssh_user|https_port|uri
			$cols = explode('|', $line);
			if (count($cols) < 6) {
				continue;
			}
			if (trim($cols[3]) === $ip && trim($cols[5]) !== '') {
				return trim($cols[5]);
			}
		}
		return '';
	}

	/**
	 * Container table lines from the external container service, cached briefly.
	 * @return string[]
	 */
	private function containerList(): array {
		$url = trim($this->config->getSystemValue('files_sharding_pod_list_url', ''));
		if ($url === '') {
			return [];
		}
		$cache = $this->cacheFactory->isAvailable() ? $this->cacheFactory->createLocal('files_sharding_podips') : null;
		if ($cache !== null) {
			$cached = $cache->get('list');
			if (is_array($cached)) {
				return $cached;
			}
		}
		$password = trim($this->config->getSystemValue('files_sharding_pod_list_password', ''));
		$full = $url . (str_contains($url, '?') ? '&' : '?') . 'fields=no'
			. ($password !== '' ? '&password=' . rawurlencode($password) : '');
		try {
			$body = (string)$this->clientService->newClient()->get($full, [
				'verify' => false,
				'timeout' => 10,
				// The service is on a private management IP; opt past NC's SSRF block.
				'nextcloud' => ['allow_local_address' => true],
			])->getBody();
		} catch (\Throwable $e) {
			$this->logger->error('files_sharding: pod list fetch failed: ' . $e->getMessage(),
				['app' => 'files_sharding', 'exception' => $e]);
			return [];
		}
		$lines = array_values(array_filter(explode("\n", trim($body)), static fn ($l) => $l !== ''));
		if ($cache !== null) {
			$cache->set('list', $lines, self::CACHE_TTL);
		}
		return $lines;
	}
}
