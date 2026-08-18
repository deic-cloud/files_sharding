<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCA\FilesSharding\Db\Server;
use OCA\FilesSharding\Db\ServerMapper;
use OCA\FilesSharding\Db\DataFolder;
use OCA\FilesSharding\Db\DataFolderMapper;
use OCA\FilesSharding\Db\UserServer;
use OCA\FilesSharding\Db\UserServerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

class ShardingService {
	public function __construct(
		private ServerMapper      $serverMapper,
		private UserServerMapper  $userServerMapper,
		private DataFolderMapper $folderMapper,
		private IConfig           $config,
		private IGroupManager     $groupManager,
		private IServerContainer  $container,
		private LoggerInterface   $logger,
	) {
	}

	// ── Server helpers ────────────────────────────────────────────────────────

	/**
	 * Returns true if this NC instance is the master.
	 *
	 * If 'files_sharding_master' is explicitly set in config it wins. Otherwise
	 * the node identifies itself by matching its own canonical host
	 * (overwrite.cli.url) against the configured master URL's host — so every
	 * node can ship an identical config (up to hostname/IP) and the master
	 * recognises itself.
	 */
	public function isMaster(): bool {
		$val = $this->config->getSystemValue('files_sharding_master', null);
		if ($val !== null) {
			return $val === true || $val === 1 || $val === '1' || $val === 'true';
		}
		$masterUrl = $this->masterUrl();
		$ownUrl = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($masterUrl === '' || $ownUrl === '') {
			return false;
		}
		// Compare host AND port (so instances sharing a hostname but differing by
		// port — e.g. the test containers — are told apart).
		$authority = static function (string $url): string {
			$p = parse_url($url);
			return strtolower(($p['host'] ?? '') . ':' . ($p['port'] ?? ''));
		};
		$masterAuth = $authority($masterUrl);
		return ($masterAuth !== ':') && $masterAuth === $authority($ownUrl);
	}

	/** External/public URL of the master — used in browser redirects and federation identities. */
	public function masterUrl(): string {
		return rtrim((string)$this->config->getSystemValue('files_sharding_master_url', ''), '/');
	}

	/**
	 * Internal URL of the master — used for silo→master API calls.
	 * Falls back to masterUrl() if not separately configured.
	 */
	public function masterInternalUrl(): string {
		$internal = rtrim((string)$this->config->getSystemValue('files_sharding_master_internal_url', ''), '/');
		return $internal !== '' ? $internal : $this->masterUrl();
	}

	/**
	 * Best URL to use when making an API call TO $server from this instance.
	 * Uses internal_url when set, otherwise falls back to the public url.
	 */
	public function apiUrlForServer(Server $server): string {
		$internal = trim($server->getInternalUrl());
		return $internal !== '' ? $internal : $server->getUrl();
	}

	/**
	 * True if $server is THIS node's own registry row — compares the row's public
	 * URL authority (host:port) to overwrite.cli.url, the same match rule isMaster()
	 * uses. Lets all-servers push/fan-out loops skip self: the local node already has
	 * the authoritative copy, so calling itself is a redundant (and slower) round-trip.
	 */
	public function isSelf(Server $server): bool {
		$ownUrl = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($ownUrl === '') {
			return false;
		}
		$authority = static function (string $url): string {
			$p = parse_url($url);
			return strtolower(($p['host'] ?? '') . ':' . ($p['port'] ?? ''));
		};
		$own = $authority($ownUrl);
		return $own !== ':' && $own === $authority($server->getUrl());
	}

	/** host:port of a URL, lowercased ('' host+port → ':'). */
	private function authority(string $url): string {
		$p = parse_url($url);
		return strtolower(($p['host'] ?? '') . ':' . ($p['port'] ?? ''));
	}

	/**
	 * True when two URLs point at the same node (host[:port]), scheme/path ignored.
	 * Used by the group-share fan-out to decide whether a member is co-resident on
	 * the owner's node (native local child) or remote (needs a federated child).
	 */
	public function sameNode(string $a, string $b): bool {
		$aa = $this->authority($a);
		$bb = $this->authority($b);
		return $aa !== ':' && $aa === $bb;
	}

	/**
	 * True if $hostOrUrl is a cluster silo OTHER than the master. Used to suppress
	 * the duplicate `user@silo` sharee entries the Federation account-directory
	 * SyncJob injects for cluster peers — we present those peers only via their
	 * canonical `user@master` identity (MasterUserSearch). External partners
	 * (not in the cluster registry) and the master itself are NOT matched.
	 */
	public function isNonMasterClusterServer(string $hostOrUrl): bool {
		$url = str_contains($hostOrUrl, '://') ? $hostOrUrl : 'https://' . $hostOrUrl;
		if (!$this->isClusterServer($url)) {
			return false;
		}
		return $this->authority($url) !== $this->authority($this->masterUrl());
	}

	/** True if $url's authority (host:port) is THIS node's own (overwrite.cli.url). */
	public function isThisNode(string $url): bool {
		$ownUrl = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($ownUrl === '' || $url === '') {
			return false;
		}
		$own = $this->authority($ownUrl);
		return $own !== ':' && $own === $this->authority($url);
	}

	/**
	 * True if $userId's home silo is THIS node. An unknown assignment (no registry
	 * row — always the case on a silo, which doesn't carry the user→silo map) is
	 * treated as resident so we never strip a user the local backend legitimately
	 * returned. Authoritative only where the map lives: the master.
	 */
	public function isResidentHere(string $userId): bool {
		$s = $this->getUserServer($userId);
		if ($s === null) {
			return true;
		}
		return $this->isSelf($s);
	}

	/** @return Server[] */
	public function getAllServers(): array {
		return $this->serverMapper->findAll();
	}

	/** @throws DoesNotExistException */
	public function getServer(int $id): Server {
		return $this->serverMapper->findById($id);
	}

	public function addServer(
		string $url,
		string $internalUrl = '',
		string $x509Dn = '',
		string $site = '',
		string $description = '',
		string $userRegex = '',
		?int   $id = null,
	): Server {
		// With an explicit ID (a stable per-silo id kept in the silo's TFTP rc.conf),
		// upsert: re-registering the same silo after a reinstall updates its existing
		// row rather than minting a duplicate. Reported free_gb/total_gb are left
		// untouched (only the descriptive fields are (re)set).
		$existing = false;
		if ($id !== null) {
			try {
				$s = $this->serverMapper->findById($id);
				$existing = true;
			} catch (DoesNotExistException) {
				$s = new Server();
				$s->setId($id);
			}
		} else {
			$s = new Server();
		}
		$s->setUrl(rtrim($url, '/'));
		$s->setInternalUrl(rtrim($internalUrl, '/'));
		$s->setX509Dn($x509Dn);
		$s->setSite($site);
		$s->setDescription($description);
		$s->setUserRegex($userRegex);
		$server = $existing ? $this->serverMapper->update($s) : $this->serverMapper->insert($s);
		$this->trustServer($url);
		return $server;
	}

	public function updateServer(int $id, array $fields): bool {
		try {
			$s = $this->serverMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}
		if (isset($fields['url']))          $s->setUrl(rtrim($fields['url'], '/'));
		if (isset($fields['internal_url'])) $s->setInternalUrl(rtrim($fields['internal_url'], '/'));
		if (isset($fields['x509_dn']))      $s->setX509Dn($fields['x509_dn']);
		if (isset($fields['site']))         $s->setSite($fields['site']);
		if (isset($fields['description']))  $s->setDescription($fields['description']);
		if (array_key_exists('user_regex', $fields)) $s->setUserRegex($fields['user_regex']);
		$this->serverMapper->update($s);
		return true;
	}

	public function deleteServer(int $id): bool {
		try {
			$s = $this->serverMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}
		$this->untrustServer($s->getUrl());
		$this->serverMapper->delete($s);
		return true;
	}

	public function updateFree(int $id, int $freeGb): bool {
		try {
			$this->serverMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}
		$this->serverMapper->updateFree($id, $freeGb);
		return true;
	}

	// ── User→silo assignment ──────────────────────────────────────────────────

	/**
	 * Returns paginated user→silo assignments, each enriched with server URL.
	 * @return array{assignments: array, total: int}
	 */
	public function listAssignments(?int $serverId = null, int $limit = 100, int $offset = 0): array {
		$rows  = $this->userServerMapper->findAllAssignments($serverId, $limit, $offset);
		$total = $this->userServerMapper->countAssignments($serverId);

		$serverCache = [];
		$assignments = [];
		foreach ($rows as $row) {
			$sid = $row->getServerId();
			if (!isset($serverCache[$sid])) {
				try {
					$serverCache[$sid] = $this->serverMapper->findById($sid);
				} catch (DoesNotExistException) {
					$serverCache[$sid] = null;
				}
			}
			$s = $serverCache[$sid];
			$assignments[] = [
				'user_id'    => $row->getUserId(),
				'server_id'  => $sid,
				'server_url' => $s?->getUrl() ?? '',
				'site'       => $s?->getSite() ?? '',
				'access'     => $row->getAccess(),
			];
		}

		return ['assignments' => $assignments, 'total' => $total];
	}

	/**
	 * GIDs of every group $userId belongs to, across all backends (including the
	 * user_group_admin cross-silo groups). Used by the share resolver to expand a
	 * user into the group shares that apply to them. Authoritative on the master.
	 *
	 * @return string[]
	 */
	public function getUserGroupIds(string $userId): array {
		try {
			$user = $this->container->get(\OCP\IUserManager::class)->get($userId);
			if ($user === null) {
				return [];
			}
			return array_values(array_map(
				static fn ($g) => $g->getGID(),
				$this->groupManager->getUserGroups($user),
			));
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: getUserGroupIds failed for ' . $userId . ': ' . $e->getMessage());
			return [];
		}
	}

	public function getUserServer(string $userId): ?Server {
		try {
			$us = $this->userServerMapper->findByUserId($userId);
			return $this->serverMapper->findById($us->getServerId());
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function unassignUser(string $userId): void {
		$this->userServerMapper->deleteByUserId($userId);
		$this->config->setUserValue($userId, 'files_sharding', 'assignment_version', (string)time());
	}

	public function getUserAccess(string $userId): int {
		try {
			return $this->userServerMapper->findByUserId($userId)->getAccess();
		} catch (DoesNotExistException) {
			return UserServer::ACCESS_READWRITE;
		}
	}

	public function setUserServer(string $userId, int $serverId, int $access = UserServer::ACCESS_READWRITE): bool {
		try {
			$this->serverMapper->findById($serverId);
		} catch (DoesNotExistException) {
			return false;
		}
		$this->userServerMapper->upsert($userId, $serverId, $access);
		// Bump the version so any cached "on correct server" session flag is invalidated.
		$this->config->setUserValue($userId, 'files_sharding', 'assignment_version', (string)time());
		return true;
	}

	/**
	 * Returns the version token that changes whenever the user's silo assignment
	 * changes. Used by RedirectMiddleware to detect stale session caches.
	 */
	public function getAssignmentVersion(string $userId): string {
		return $this->config->getUserValue($userId, 'files_sharding', 'assignment_version', '');
	}

	/**
	 * Assigns $userId to the best matching silo using this priority:
	 *   1. Silos whose user_regex matches the userId (sorted by free_gb desc).
	 *   2. All silos sorted by free_gb desc (when no regex matches).
	 *
	 * Returns the chosen server, or null if no silos are registered.
	 */
	public function autoAssign(string $userId): ?Server {
		// Never push NC admins off the master — they need admin UI access.
		if ($this->groupManager->isInGroup($userId, 'admin')) {
			$this->logger->debug("files_sharding: autoAssign: skipping admin user {$userId}");
			return null;
		}

		$servers = $this->serverMapper->findAll();
		if (empty($servers)) {
			return null;
		}

		$byFree = static fn(Server $a, Server $b) => ($b->getFreeGb() ?? 0) <=> ($a->getFreeGb() ?? 0);

		// Collect silos whose regex matches the user ID.
		$matched = [];
		foreach ($servers as $s) {
			$regex = trim($s->getUserRegex());
			if ($regex === '') {
				continue;
			}
			// Silently skip invalid patterns rather than crashing.
			if (@preg_match($regex, $userId) === 1) {
				$matched[] = $s;
			}
		}

		if (!empty($matched)) {
			usort($matched, $byFree);
			$best = $matched[0];
		} else {
			usort($servers, $byFree);
			$best = $servers[0];
		}

		$this->setUserServer($userId, $best->getId());
		$this->logger->info("files_sharding: auto-assigned {$userId} → {$best->getUrl()} (free={$best->getFreeGb()} GB, regex_match=" . (!empty($matched) ? 'yes' : 'no') . ')');
		return $best;
	}

	/**
	 * Returns the home silo URL for $userId, or null if not assigned / this is
	 * already the home silo.
	 */
	public function getRedirectUrl(string $userId): ?string {
		$server = $this->getUserServer($userId);
		if ($server === null) {
			return null;
		}
		$siloUrl   = rtrim($server->getUrl(), '/');
		$masterUrl = $this->masterUrl();
		if ($masterUrl !== '' && $siloUrl === rtrim($masterUrl, '/')) {
			return null;
		}
		return $siloUrl;
	}

	// ── Folder visibility ─────────────────────────────────────────────────────

	/** @return DataFolder[] */
	public function getFolders(string $userId): array {
		return $this->folderMapper->findByUserId($userId);
	}

	public function addFolder(string $userId, string $folder, string $onlyFrom = '', bool $hideFromClients = false): DataFolder {
		$f = new DataFolder();
		$f->setUserId($userId);
		$f->setFolder('/' . ltrim($folder, '/'));
		$f->setOnlyFrom($onlyFrom);
		$f->setHideFromClients($hideFromClients);
		return $this->folderMapper->insert($f);
	}

	public function updateFolder(int $id, ?string $onlyFrom = null, ?bool $hideFromClients = null): bool {
		try {
			$f = $this->folderMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}
		if ($onlyFrom !== null) {
			$f->setOnlyFrom($onlyFrom);
		}
		if ($hideFromClients !== null) {
			$f->setHideFromClients($hideFromClients);
		}
		$this->folderMapper->update($f);
		return true;
	}

	public function deleteFolder(int $id): bool {
		try {
			$f = $this->folderMapper->findById($id);
		} catch (DoesNotExistException) {
			return false;
		}
		$this->folderMapper->delete($f);
		return true;
	}

	/**
	 * Returns true if $folder should be visible to a DAV client coming from
	 * $clientIp with User-Agent $userAgent.
	 * Folders with no matching rule are always visible.
	 */
	public function isFolderVisibleFrom(string $userId, string $folder, string $clientIp, string $userAgent = ''): bool {
		$folder = '/' . ltrim($folder, '/');
		foreach ($this->folderMapper->findByUserId($userId) as $rule) {
			if (rtrim($rule->getFolder(), '/') !== rtrim($folder, '/')) {
				continue;
			}
			if ($rule->getHideFromClients() && $this->isSyncClient($userAgent)) {
				return false;
			}
			$only = trim($rule->getOnlyFrom());
			if ($only === '') {
				return true;
			}
			foreach (array_map('trim', explode(',', $only)) as $cidr) {
				if ($this->ipInCidr($clientIp, $cidr)) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	private function isSyncClient(string $userAgent): bool {
		return (bool) preg_match('/(?:mirall|ownCloudSync)\//i', $userAgent);
	}

	// ── Federation trust ─────────────────────────────────────────────────────

	/**
	 * Add $url to NC's federation trusted-server list so that federated shares
	 * from this silo are auto-accepted. Silently skipped if the federation app
	 * is not enabled.
	 */
	public function trustServer(string $url): void {
		try {
			// Never trust ourselves. SyncSiloTrustJob iterates the master's server
			// registry, which includes THIS node — a self-trust row is a no-op for
			// sharing (self-shares are local) but clutters the trust table.
			if ($this->isThisNode($url)) {
				return;
			}
			/** @var \OCA\Federation\TrustedServers $ts */
			$ts = $this->container->get(\OCA\Federation\TrustedServers::class);
			if (!$ts->isTrustedServer($url)) {
				$ts->addServer($url);
			}
			// We deliberately do NOT force the row to STATUS_OK. Cluster nodes trust
			// each other by MEMBERSHIP (they share the files_sharding secret), and
			// everything we rely on keys off isTrustedServer()/our own registry, not
			// the status: core's trusted-share auto-accept checks isTrustedServer()
			// (CloudFederationProviderFiles), and OCM share/accept/unshare authenticate
			// with the per-share token — never the pairwise shared_secret. So NC's
			// secret handshake never completes here and the row honestly stays
			// STATUS_PENDING with an empty shared_secret. That is EXPECTED and harmless:
			// the only thing the secret feeds is the Federation account-directory
			// SyncJob, which we don't use (MasterUserSearch queries the master live).
			// (We previously forced OK to avoid a "why is this PENDING?" detour — it
			// backfired: OK + empty secret is self-contradicting and invited exactly
			// that detour, and forcing OK doesn't complete the handshake anyway.)
		} catch (\Throwable $e) {
			$this->logger->debug('files_sharding: could not auto-trust server (federation app missing?): ' . $e->getMessage());
		}
	}

	/**
	 * Is $url one of OUR cluster's own servers (master or a sibling silo)? Decides
	 * whether a mirrored federated share AUTO-ACCEPTS (intra-cluster origin →
	 * seamless, shows under "Internal") or stays PENDING for manual accept
	 * (external partner → real federation, shows under "External").
	 *
	 * The authoritative source is the cluster's own registry (files_sharding_servers),
	 * which distinguishes a sibling silo from a merely trusted external partner —
	 * a distinction oc_trusted_servers cannot make once we federate with Nextcloud
	 * installs abroad (a funding requirement). The registry lives on the master.
	 *
	 * A silo carries no registry, so getAllServers() is empty there; every federated
	 * share a silo receives today is master-mediated and thus intra-cluster, so we
	 * fall back to trusted-server membership. REVISIT when external-partner federation
	 * lands: the master must then tag exported shares cluster/external so a silo can
	 * tell a partner's share from a sibling silo's without its own registry.
	 */
	public function isClusterServer(string $url): bool {
		$target = $this->authority($url);
		if ($target === ':') {
			return false;
		}
		// The master is always a cluster node (covers silos, where the only
		// intra-cluster remote a share carries is the master's own URL).
		if ($this->authority($this->masterUrl()) === $target
			|| $this->authority($this->masterInternalUrl()) === $target) {
			return true;
		}
		$servers = $this->getAllServers();
		foreach ($servers as $s) {
			if ($this->authority($s->getUrl()) === $target
				|| ($s->getInternalUrl() !== '' && $this->authority($s->getInternalUrl()) === $target)) {
				return true;
			}
		}
		// No registry (silo) → fall back to trusted-server membership.
		if (count($servers) === 0) {
			try {
				/** @var \OCA\Federation\TrustedServers $ts */
				$ts = $this->container->get(\OCA\Federation\TrustedServers::class);
				return $ts->isTrustedServer(rtrim($url, '/'));
			} catch (\Throwable) {
				return false;
			}
		}
		return false;
	}

	private function untrustServer(string $url): void {
		try {
			/** @var \OCA\Federation\TrustedServers $ts */
			$ts  = $this->container->get(\OCA\Federation\TrustedServers::class);
			$id  = $ts->getServerById($url);
			if ($id !== null) {
				$ts->removeServer($id);
			}
		} catch (\Throwable) {
			// federation app not available — nothing to do
		}
	}

	// ── IP helpers ────────────────────────────────────────────────────────────

	private function ipInCidr(string $ip, string $cidr): bool {
		if (!str_contains($cidr, '/')) {
			return $ip === $cidr;
		}
		[$subnet, $bits] = explode('/', $cidr, 2);
		$bits   = (int)$bits;
		$ipLong = ip2long($ip);
		$subLong = ip2long($subnet);
		if ($ipLong === false || $subLong === false) {
			return false;
		}
		$mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
		return ($ipLong & $mask) === ($subLong & $mask);
	}
}
