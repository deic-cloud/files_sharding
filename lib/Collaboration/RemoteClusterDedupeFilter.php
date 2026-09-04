<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IRequest;
use OCP\IUserManager;
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
		private IUserManager    $userManager,
	) {
	}

	/**
	 * Silo-aware residency. ShardingService::isResidentHere() answers from the
	 * master-authoritative user→silo map and calls EVERYONE resident when the map
	 * is silent — correct for ResidentUserFilter's master-only pruning, wrong
	 * here: on a silo the map is always silent, and treating cross-silo peers as
	 * resident would suppress their canonical entry. When the map is silent, fall
	 * back to "does a local account exist" — true for a silo's own residents
	 * (skip: core lists them locally), false for cross-silo peers (re-add).
	 */
	private function residentHere(string $userId): bool {
		try {
			$server = $this->shardingService->getUserServer($userId);
			if ($server !== null) {
				return $this->shardingService->isSelf($server);
			}
		} catch (\Throwable) {
		}
		return $this->userManager->userExists($userId);
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
		$deadLocals = [];
		foreach (['remotes' => false, 'exact' => true] as $key => $isExact) {
			$bucket = $isExact ? ($current['exact']['remotes'] ?? []) : ($current['remotes'] ?? []);
			foreach ($bucket as $entry) {
				$shareWith = (string)($entry['value']['shareWith'] ?? '');
				// Core's addressbook plugins (system addressbook = the federation
				// SyncJob's cluster contacts) can emit a TYPE_USER entry into the
				// REMOTES bucket when a contact's cloud id points at this host. For
				// a non-resident directory account that is a dead local target —
				// the remotes-bucket sibling of what ResidentUserFilter strips.
				if ((int)($entry['value']['shareType'] ?? IShare::TYPE_REMOTE) === IShare::TYPE_USER) {
					if ($shareWith !== '' && !$this->residentHere($shareWith)) {
						$deadLocals[$shareWith] = true;
					}
					continue;
				}
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

		// Purge the dead-local artifacts (shareWith is the bare uid, so this
		// cannot touch a genuine federated entry — those carry @host).
		foreach (array_keys($deadLocals) as $uid) {
			$searchResult->removeCollaboratorResult($type, $uid);
		}

		$hasClusterExact = false;
		foreach ($peers as $userId => $info) {
			// Drop every variant of this cluster peer (all hosts, both buckets).
			foreach (array_keys($info['shareWiths']) as $sw) {
				$searchResult->removeCollaboratorResult($type, $sw);
			}
			// A peer RESIDENT ON THIS NODE is already offered as a live local
			// (TYPE_USER) target by core — re-adding a federated variant here is
			// exactly the "shows twice" duplicate. Variants removed, nothing added.
			if ($this->residentHere($userId)) {
				continue;
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
				if ($info['exact']) {
					$hasClusterExact = true;
				}
			}
		}

		// Core's final sanitising step drops ALL remote results when an exact
		// local users match exists for an email-alike query. With two distinct
		// accounts — one matching a LOCAL user by email, one a CROSS-SILO user by
		// uid (e.g. fror@deic.dk with email fror@dtu.dk, searched as fror@dtu.dk)
		// — both hits are legitimate and the searcher picks; without this, the
		// cross-silo account is unfindable by its exact uid from that silo. Only
		// disarm when we actually hold an exact canonical cluster entry, so stock
		// behaviour for genuinely local/external matches is untouched.
		if ($hasClusterExact) {
			$this->clearExactUserFlag($searchResult);
		}

		return false;
	}

	/** Clear core's 'exact users id match' flag (protected property → reflection, best-effort). */
	private function clearExactUserFlag(ISearchResult $searchResult): void {
		if (!($searchResult instanceof \OC\Collaboration\Collaborators\SearchResult)) {
			return;
		}
		try {
			if (!$searchResult->hasExactIdMatch(new SearchResultType('users'))) {
				return;
			}
			$ref = new \ReflectionProperty($searchResult, 'exactIdMatches');
			$matches = $ref->getValue($searchResult);
			unset($matches['users']);
			$ref->setValue($searchResult, $matches);
		} catch (\Throwable) {
		}
	}
}
