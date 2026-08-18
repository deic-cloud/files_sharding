<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IRequest;
use OCP\Share\IShare;

/**
 * Keeps cluster peers out of the wrong share-dialog box. Since servers now trust
 * each other, the Federation account-directory SyncJob populates federated address
 * books, so core's remote search offers cluster peers by their `user@silo` (and
 * `user@master`) cloud ids. We present cluster peers ONLY via their canonical
 * `user@master` identity in the "Internal shares" box (MasterUserSearch), so:
 *
 *   - Internal box (request includes TYPE_USER): strip the non-master `user@silo`
 *     duplicates, keep the canonical `@master` entry.
 *   - External box (out-of-cluster federation; no TYPE_USER requested): strip ALL
 *     cluster peers — they belong in Internal, not here.
 *
 * Genuine external-partner remotes (not in the cluster registry) are never touched.
 * Registered for SHARE_TYPE_REMOTE; prunes core's results in place.
 */
class RemoteClusterDedupeFilter implements ISearchPlugin {
	public function __construct(
		private ShardingService $shardingService,
		private IRequest        $request,
	) {
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$type    = new SearchResultType('remotes');
		$current = $searchResult->asArray();

		// The Internal box always requests TYPE_USER alongside the remote types; the
		// External box never does (see SharingInput.vue / MasterUserSearch).
		$types      = $this->request->getParam('shareType');
		$internalBox = false;
		if (is_array($types)) {
			foreach ($types as $t) {
				if ((int)$t === IShare::TYPE_USER) {
					$internalBox = true;
					break;
				}
			}
		}

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
				$url  = 'https://' . $host;

				if (!$this->shardingService->isClusterServer($url)) {
					continue; // genuine external partner — leave it
				}
				// Cluster peer. In the External box strip it entirely; in the Internal
				// box strip only the non-master duplicate (keep the canonical @master).
				if (!$internalBox || $this->shardingService->isNonMasterClusterServer($host)) {
					$searchResult->removeCollaboratorResult($type, $shareWith);
				}
			}
		}

		return false;
	}
}
