<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;

/**
 * Removes local ("users") search results for users whose home silo is NOT this
 * node. Registered for SHARE_TYPE_USER so it runs after core's UserPlugin and
 * prunes its results in place (ISearchResult::removeCollaboratorResult).
 *
 * On the master the local directory holds an account for EVERY cluster user, so
 * core would otherwise offer a non-resident (e.g. alice) as a local target — a
 * dead type-0 share, since her storage lives on another silo. This strips those,
 * leaving only the federated @master entry MasterUserSearch adds. On a silo the
 * user→silo map is absent, so isResidentHere() treats everyone as resident and
 * this is a no-op (silos normally hold only their own residents anyway).
 */
class ResidentUserFilter implements ISearchPlugin {
	public function __construct(
		private ShardingService $shardingService,
	) {
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$type    = new SearchResultType('users');
		$current = $searchResult->asArray();

		$buckets = [];
		if (!empty($current['users'])) {
			$buckets[] = $current['users'];
		}
		if (!empty($current['exact']['users'])) {
			$buckets[] = $current['exact']['users'];
		}

		$checked = [];
		foreach ($buckets as $bucket) {
			foreach ($bucket as $entry) {
				$uid = (string)($entry['value']['shareWith'] ?? '');
				if ($uid === '' || isset($checked[$uid])) {
					continue;
				}
				$checked[$uid] = true;
				if (!$this->shardingService->isResidentHere($uid)) {
					$searchResult->removeCollaboratorResult($type, $uid);
				}
			}
		}

		return false;
	}
}
