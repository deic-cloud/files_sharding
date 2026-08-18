<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;

/**
 * Removes the duplicate `user@silo` remote sharee entries that core surfaces from
 * the Federation account-directory (populated by the SyncJob once servers trust
 * each other). Cluster peers are presented ONLY via their canonical `user@master`
 * identity by MasterUserSearch; the extra `user@silo` variant leaks the silo host
 * and points at the wrong (non-master) target, whose mirror the reconcile would
 * prune. Registered for SHARE_TYPE_REMOTE so it runs alongside core's remote
 * search and prunes its results in place (ISearchResult::removeCollaboratorResult).
 *
 * Only strips remotes on a NON-MASTER cluster silo — the `@master` entry and any
 * genuine external-partner entry are left untouched.
 */
class RemoteClusterDedupeFilter implements ISearchPlugin {
	public function __construct(
		private ShardingService $shardingService,
	) {
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$type    = new SearchResultType('remotes');
		$current = $searchResult->asArray();

		$buckets = [];
		if (!empty($current['remotes'])) {
			$buckets[] = $current['remotes'];
		}
		if (!empty($current['exact']['remotes'])) {
			$buckets[] = $current['exact']['remotes'];
		}

		$checked = [];
		foreach ($buckets as $bucket) {
			foreach ($bucket as $entry) {
				$shareWith = (string)($entry['value']['shareWith'] ?? '');
				if ($shareWith === '' || isset($checked[$shareWith])) {
					continue;
				}
				$checked[$shareWith] = true;
				$at = strrpos($shareWith, '@');
				if ($at === false) {
					continue;
				}
				$host = substr($shareWith, $at + 1);
				if ($this->shardingService->isNonMasterClusterServer($host)) {
					$searchResult->removeCollaboratorResult($type, $shareWith);
				}
			}
		}

		return false;
	}
}
