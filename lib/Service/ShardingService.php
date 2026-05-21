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

	/** Returns true if this NC instance is the master. */
	public function isMaster(): bool {
		$val = $this->config->getSystemValue('files_sharding_master', false);
		return $val === true || $val === 1 || $val === '1' || $val === 'true';
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
	): Server {
		$s = new Server();
		$s->setUrl(rtrim($url, '/'));
		$s->setInternalUrl(rtrim($internalUrl, '/'));
		$s->setX509Dn($x509Dn);
		$s->setSite($site);
		$s->setDescription($description);
		$s->setUserRegex($userRegex);
		$server = $this->serverMapper->insert($s);
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
			/** @var \OCA\Federation\TrustedServers $ts */
			$ts = $this->container->get(\OCA\Federation\TrustedServers::class);
			if (!$ts->isTrustedServer($url)) {
				$ts->addServer($url);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('files_sharding: could not auto-trust server (federation app missing?): ' . $e->getMessage());
		}
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
