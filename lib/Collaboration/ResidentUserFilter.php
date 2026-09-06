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
 *
 * One subtlety: core's UserPlugin marks "exact users id match" BEFORE this
 * filter removes a non-resident directory account, and core's final sanitising
 * step ("exact local user match on an email-alike query → drop all remote and
 * email results") then wipes the canonical federated entry MasterUserSearch
 * adds — on the master, a full-uid search for any cross-silo user returned
 * NOTHING. When the exact users bucket ends up empty after our removal, the
 * stale flag is cleared (protected core property → reflection, best-effort).
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
				if (!$this->shardingService->isResidentHere($uid)
					|| $this->shardingService->isHiddenUser($uid)) {
					$searchResult->removeCollaboratorResult($type, $uid);
				}
			}
		}

		$this->clearStaleExactUserFlag($searchResult);

		return false;
	}

	/**
	 * If the "exact users id match" flag is set but the exact users bucket is now
	 * empty, the match was a non-resident directory account we just removed:
	 * clear the flag so core's final sanitising step doesn't drop the federated
	 * results. Without the clear nothing else regresses — full-uid cross-silo
	 * search on the master just stays empty.
	 */
	private function clearStaleExactUserFlag(ISearchResult $searchResult): void {
		if (!($searchResult instanceof \OC\Collaboration\Collaborators\SearchResult)) {
			return;
		}
		try {
			if (!$searchResult->hasExactIdMatch(new SearchResultType('users'))) {
				return;
			}
			$current = $searchResult->asArray();
			if (!empty($current['exact']['users'])) {
				return; // a genuine exact match survived — leave the flag alone
			}
			$ref = new \ReflectionProperty($searchResult, 'exactIdMatches');
			$matches = $ref->getValue($searchResult);
			unset($matches['users']);
			$ref->setValue($searchResult, $matches);
		} catch (\Throwable) {
		}
	}
}
