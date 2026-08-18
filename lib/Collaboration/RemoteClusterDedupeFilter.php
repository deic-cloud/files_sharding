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
 * Canonicalises cluster peers in the share-dialog "remotes" results.
 *
 * Since cluster nodes trust each other, the Federation account-directory SyncJob
 * populates federated address books, so core's remote search returns cluster peers
 * BOTH as `user@silo` and as `user@master` (the latter colliding, same shareWith,
 * with MasterUserSearch's own entry — and carrying a `server` field that leaks the
 * host as an "on {host}" subname). We present cluster peers ONLY via their canonical
 * `user@master` identity, exactly once, and only in the "Internal shares" box.
 *
 * So for every cluster peer we REMOVE all of core's/MUS's variants (any host, both
 * exact and wide buckets — removeCollaboratorResult can't distinguish same-shareWith
 * duplicates) and, in the Internal box only, re-add a single clean `@master` entry.
 * The External box (out-of-cluster federation) gets none. Genuine external-partner
 * remotes (not in the cluster registry) are left untouched.
 *
 * Registered for SHARE_TYPE_REMOTE; runs after MasterUserSearch and core's search.
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

		// Internal box always requests TYPE_USER alongside remotes; External never does.
		$types       = $this->request->getParam('shareType');
		$internalBox = false;
		if (is_array($types)) {
			foreach ($types as $t) {
				if ((int)$t === IShare::TYPE_USER) {
					$internalBox = true;
					break;
				}
			}
		}

		$masterHost = preg_replace('#^https?://#', '', rtrim($this->shardingService->masterUrl(), '/'));

		// Gather cluster-peer remote entries, grouped by the user part of shareWith.
		// @var array<string, array{name: string, exact: bool, shareWiths: array<string,true>}>
		$peers = [];
		foreach (['remotes' => false, 'exact' => true] as $key => $isExact) {
			$bucket = $isExact ? ($current['exact']['remotes'] ?? []) : ($current['remotes'] ?? []);
			foreach ($bucket as $entry) {
				$shareWith = (string)($entry['value']['shareWith'] ?? '');
				$at = strrpos($shareWith, '@');
				if ($at === false) {
					continue;
				}
				$host = substr($shareWith, $at + 1);
				if (!$this->shardingService->isClusterServer('https://' . $host)) {
					continue; // genuine external partner — leave it
				}
				$userId = substr($shareWith, 0, $at);
				if (!isset($peers[$userId])) {
					$peers[$userId] = ['name' => (string)($entry['name'] ?? $userId), 'exact' => false, 'shareWiths' => []];
				}
				$peers[$userId]['shareWiths'][$shareWith] = true;
				if ($isExact) {
					$peers[$userId]['exact'] = true;
				}
			}
		}

		foreach ($peers as $userId => $info) {
			// Drop every variant of this cluster peer (all hosts, both buckets).
			foreach (array_keys($info['shareWiths']) as $sw) {
				$searchResult->removeCollaboratorResult($type, $sw);
			}
			// Internal box: re-add a single clean canonical @master entry. External: none.
			if ($internalBox && $masterHost !== '') {
				$clean = [[
					'label' => $info['name'] . ' (' . $userId . ')',
					'uuid'  => $userId,
					'name'  => $info['name'],
					'value' => [
						'shareType'       => IShare::TYPE_REMOTE,
						'shareWith'       => $userId . '@' . $masterHost,
						'isTrustedServer' => true,
					],
				]];
				$searchResult->addResultSet($type, $info['exact'] ? [] : $clean, $info['exact'] ? $clean : []);
			}
		}

		return false;
	}
}
