<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\ICacheFactory;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;

/**
 * Where in the cluster a public-link token lives. A token exists only on the node
 * holding the data; the master finds it by asking the other nodes over the
 * authenticated internal API (internal/links/{token}), never by requesting their
 * public link pages: those count each miss as a failed guess in Nextcloud's
 * brute-force protection, so the master's address got throttled on every silo
 * that did not hold the token (and a throttled answer, 429, then read as "found").
 */
class LinkLocator {
	public function __construct(
		private ShardingService   $sharding,
		private InterServerClient $client,
		private IShareManager     $shareManager,
		private ICacheFactory     $cacheFactory,
	) {
	}

	public function isLocal(string $token): bool {
		try {
			$this->shareManager->getShareByToken($token);
			return true;
		} catch (ShareNotFound) {
			return false;
		}
	}

	/**
	 * MASTER: public URL of the other node holding $token, or null. Found nodes are
	 * cached for 5 minutes, misses for 1 (a link may be created meanwhile).
	 */
	public function ownerNode(string $token, bool $fresh = false): ?string {
		$cache = $this->cacheFactory->createLocal('files_sharding_linknode');
		if (!$fresh) {
			$hit = $cache->get($token);
			if (is_string($hit)) {
				return $hit === '' ? null : $hit;
			}
		}
		$found = null;
		foreach ($this->sharding->getAllServers() as $server) {
			if ($this->sharding->isSelf($server)) {
				continue;
			}
			$r = $this->client->getDirect($this->sharding->apiUrlForServer($server), 'internal/links/' . rawurlencode($token));
			if (is_array($r) && !empty($r['exists'])) {
				$found = rtrim($server->getUrl(), '/');
				break;
			}
		}
		$cache->set($token, $found ?? '', $found !== null ? 300 : 60);
		return $found;
	}

	/**
	 * Does $token exist anywhere in the cluster, this node included? On a silo the
	 * master answers for the cluster. Null if that could not be determined.
	 */
	public function existsInCluster(string $token): ?bool {
		if ($this->isLocal($token)) {
			return true;
		}
		if ($this->sharding->isMaster()) {
			return $this->ownerNode($token, true) !== null;
		}
		$master = $this->sharding->masterInternalUrl();
		if ($master === '') {
			return null;
		}
		$r = $this->client->getDirect($master, 'internal/links/' . rawurlencode($token), ['cluster' => '1']);
		return is_array($r) && array_key_exists('exists', $r) ? (bool)$r['exists'] : null;
	}
}
