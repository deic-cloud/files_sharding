<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;

/**
 * The master directory's answer to a sharee search (internal/users/search), shared
 * by the collaborator-search plugins of one request: MasterUserSearch builds the
 * federated entries from it, ResidentUserFilter and RemoteClusterDedupeFilter take
 * each user's home node from it.
 *
 * The home node the master reports is authoritative. A local account alone does
 * not make a user resident on a silo: a stray account (a test leftover, an account
 * created on the wrong node) would otherwise capture every share to that user
 * made on this silo - a type-0 share to an account nobody uses.
 *
 * One call per search and request (static: the plugins may come from different
 * DI containers). Null when the master cannot be asked - callers then keep their
 * previous behaviour.
 */
class MasterDirectory {
	/** @var array<string, ?array> search key => users from the master, or null */
	private static array $searches = [];
	/** @var array<string, string> uid (lower case) => silo_url, from this request's searches */
	private static array $homes = [];

	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
	) {
	}

	/** @return ?list<array> the master's users matching $search, null if unavailable */
	public function search(string $search, int $limit): ?array {
		$key = $limit . "\0" . $search;
		if (!array_key_exists($key, self::$searches)) {
			$users = null;
			$master = $this->shardingService->masterInternalUrl();
			if ($master !== '') {
				$data = $this->client->getDirect($master, 'internal/users/search', ['q' => $search, 'limit' => $limit]);
				if (is_array($data) && isset($data['users']) && is_array($data['users'])) {
					$users = $data['users'];
					foreach ($users as $u) {
						$uid = (string)($u['user_id'] ?? '');
						if ($uid !== '' && (string)($u['silo_url'] ?? '') !== '') {
							self::$homes[strtolower($uid)] = (string)$u['silo_url'];
						}
					}
				}
			}
			self::$searches[$key] = $users;
		}
		return self::$searches[$key];
	}

	/**
	 * Whether the master places $uid on another node. Null when this request's
	 * searches did not tell us (unknown user, master unreachable).
	 */
	public function livesElsewhere(string $uid): ?bool {
		$home = self::$homes[strtolower($uid)] ?? null;
		return $home === null ? null : !$this->shardingService->isThisNode($home);
	}
}
