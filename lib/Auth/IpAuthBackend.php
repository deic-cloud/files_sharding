<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Auth;

use OCP\Authentication\IApacheBackend;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use Psr\Log\LoggerInterface;

/**
 * IP-based trusted data access (the second trusted-access mechanism alongside
 * the X.509 relay in X509Backend). For requests originating on the user-pod
 * VLAN (system config 'uservlannet', e.g. "10.2."), the source IP is mapped to
 * the owner of the container running there, and the request is authenticated as
 * that owner — so a user's own pod reaches that user's files without a password.
 *
 * If the owning container belongs to the configured trusted user
 * ('files_sharding_trusted_user', e.g. "cloud"), the Basic-auth username is
 * honoured instead (impersonation) — this is how cloud-owned service pods act on
 * behalf of any user. Empty/any password.
 *
 * The IP→owner map comes from an external container service that shares this
 * instance's user IDs; it is cached briefly. Its host, access password and the
 * trusted-user name live in the DB (user_pods appconfig: podManagementIP,
 * getContainersPassword, trustedUser — the historical location, kept there to
 * avoid bloating config.php). We read those appconfig rows directly — a data
 * read, not a code dependency on the user_pods app (absent rows → inactive).
 * Only 'uservlannet' (the pod VLAN prefix) stays a config.php system value.
 * (Distinct from 'trustednet' = the trusted *infra* net, whose inter-server
 * trust is the shared secret on this system, not IP — see X509Controller.)
 *
 * Precedence (enforced by yielding): X.509 relay (SSL-CLIENT-S-DN present) wins,
 * then this IP mechanism, then normal password/session auth. A request carrying
 * a real Basic-auth password is treated as a normal login and yielded.
 */
class IpAuthBackend extends ABackend implements IUserBackend, IApacheBackend {
	private const CACHE_TTL     = 60; // seconds (successful container-list fetch)
	private const NEG_CACHE_TTL = 20; // seconds (fetch failed → don't hammer a dead service)

	public function __construct(
		private IRequest        $request,
		private IConfig         $config,
		private IAppConfig      $appConfig,
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
		// 1. Must originate on the user-pod VLAN (or loopback). getRemoteAddress()
		//    honours trusted_proxies if one is ever put in front; today it is the
		//    raw peer address. This is 'uservlannet' (the pod net, e.g. 10.2.), NOT
		//    'trustednet' (the trusted *infra* net, e.g. 10.0. — inter-server and
		//    service traffic, whose trust is the shared secret, not IP). Pod
		//    IP→owner resolution must only fire for genuine pod source IPs.
		$net = trim($this->config->getSystemValue('uservlannet', ''));
		$ip = $this->request->getRemoteAddress();
		if ($ip === '') {
			return '';
		}
		$onTrustedNet = ($net !== '' && str_starts_with($ip, $net)) || $ip === '127.0.0.1' || $ip === '::1';
		if (!$onTrustedNet) {
			return '';
		}

		// 1b. Belt-and-suspenders: Bearer-authenticated requests are inter-server /
		//     API calls (they carry the files_sharding shared secret), never IP-based
		//     pod access — yield. With the gate above correctly on the pod VLAN
		//     (uservlannet), 10.0. inter-server traffic no longer reaches here; this
		//     just ensures a pod that ever presented a Bearer token can't get IP
		//     auto-auth either.
		if (stripos(trim($this->request->getHeader('Authorization')), 'Bearer ') === 0) {
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

		$trustedUser = trim($this->appConfig->getValueString('user_pods', 'trustedUser', ''))
			?: trim($this->config->getSystemValue('vlantrusteduser', ''));

		// 4. Map the source IP to the owner of the container running there. The
		//    only owners that can lead anywhere are the Basic-auth username (an
		//    ordinary pod fetching its owner's files) and the trusted service
		//    user (impersonation) — passed as candidates for the fast filtered
		//    lookup; the resolved owner is still taken from the service's answer.
		$owner = $this->ownerForIp($ip, [$authUser, $trustedUser]);
		if ($owner === '') {
			return '';
		}

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

	/** Owner (NC uid) of the container whose pod IP matches $ip, or ''. Cached per IP. */
	/** @param string[] $candidateOwners owners worth a filtered lookup (deduped, ''-skipped) */
	private function ownerForIp(string $ip, array $candidateOwners = []): string {
		$cache = $this->cacheFactory->isAvailable() ? $this->cacheFactory->createLocal('files_sharding_podips') : null;
		$cached = $cache?->get('owner:' . $ip);
		if (is_string($cached)) {
			return $cached;
		}

		// 1) Fast path: get_containers.php supports ?pod_ip= — but only together
		//    with a user_id, so it can't answer "who owns this IP?" directly.
		//    There are exactly two owners that matter (the Basic-auth username
		//    for an ordinary pod, the trusted service user for impersonation), so
		//    probe those. The owner is still read from the service's answer.
		$owner = '';
		foreach (array_unique(array_filter($candidateOwners)) as $cand) {
			$owner = $this->scanForIp($this->fetchContainers(['user_id' => $cand, 'pod_ip' => $ip]), $ip);
			if ($owner !== '') {
				break;
			}
		}

		// 2) Fallback: the FULL list, cached whole (the old chooser checkIP
		//    pattern) — covers a host script without the pod_ip filter. The
		//    unfiltered listing is slow (~15s), so it is hit at most once per
		//    TTL; requests in between scan the cached copy.
		if ($owner === '') {
			$list = $cache?->get('list');
			if (!is_array($list)) {
				$list = $this->fetchContainers();
				// An empty/failed fetch is cached too (shorter TTL) so a dead or
				// slow container service is not hammered on every request.
				$cache?->set('list', $list, $list !== [] ? self::CACHE_TTL : self::NEG_CACHE_TTL);
			}
			$owner = $this->scanForIp($list, $ip);
		}

		$cache?->set('owner:' . $ip, $owner, $owner !== '' ? self::CACHE_TTL : self::NEG_CACHE_TTL);
		return $owner;
	}

	/** @param string[] $lines */
	private function scanForIp(array $lines, string $ip): string {
		foreach ($lines as $line) {
			// pod_name|container|image|pod_ip|node_ip|owner|age|status|ssh_port|ssh_user|https_port|uri
			// (a header line parses harmlessly: its pod_ip column is the literal "pod_ip")
			$cols = explode('|', $line);
			if (count($cols) >= 6 && trim($cols[3]) === $ip && trim($cols[5]) !== '') {
				return trim($cols[5]);
			}
		}
		return '';
	}

	/**
	 * Container table lines from the external container service, scoped to a single
	 * pod IP via '&pod_ip='. When the service honours that filter it returns just
	 * the matching row(s) in ~2s instead of enumerating every container (~15s); if
	 * it ignores the filter it returns the whole table and ownerForIp() still matches
	 * $ip in it. 'fields=no' suppresses the header row.
	 * @return string[]
	 */
	/** @param array<string,string> $extraParams e.g. ['user_id'=>..,'pod_ip'=>..] for the filtered fast path */
	private function fetchContainers(array $extraParams = []): array {
		// Host + password live in user_pods appconfig (podManagementIP with the
		// legacy 'privateIP' fallback; getContainersPassword).
		$host = trim($this->appConfig->getValueString('user_pods', 'podManagementIP', ''))
			?: trim($this->appConfig->getValueString('user_pods', 'privateIP', ''));
		if ($host === '') {
			return [];
		}
		$password = trim($this->appConfig->getValueString('user_pods', 'getContainersPassword', ''));
		$url = 'http://' . $host . '/get_containers.php?fields=no'
			. ($password !== '' ? '&password=' . rawurlencode($password) : '');
		foreach ($extraParams as $k => $v) {
			$url .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
		}
		try {
			$body = (string)$this->clientService->newClient()->get($url, [
				'verify' => false,
				// A filtered (user_id+pod_ip) lookup is fast; the unfiltered
				// fallback listing takes ~15s on the real cluster and is hit at
				// most once per TTL thanks to the whole-list cache above.
				'timeout' => $extraParams === [] ? 45 : 10,
				// The service is on a private management IP; opt past NC's SSRF block.
				'nextcloud' => ['allow_local_address' => true],
			])->getBody();
		} catch (\Throwable $e) {
			$this->logger->error('files_sharding: pod lookup failed: ' . $e->getMessage(),
				['app' => 'files_sharding', 'exception' => $e]);
			return [];
		}
		return array_values(array_filter(explode("\n", trim($body)), static fn ($l) => $l !== ''));
	}
}
