<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Searches users via the master directory and returns anyone whose home silo is
 * NOT this node as a federated (TYPE_REMOTE) share target pointed at the master
 * (the canonical @master identity). Runs on every node — including the master
 * itself, which is a silo like any other: there it turns the master's local
 * directory accounts for OTHER silos' users into federated targets, while the
 * ResidentUserFilter strips the dead local duplicate. Net effect: one working
 * entry per cluster peer, in the "Internal shares" box, identical everywhere.
 *
 * Residency is decided from the master's per-user silo_url (silos carry no
 * user→silo map of their own), so this works uniformly on master and silos.
 *
 * Cluster peers belong in the "Internal shares" box ONLY. The share dialog runs
 * this plugin for BOTH boxes (both request TYPE_REMOTE), so we tell them apart by
 * the requested share types: the Internal box always asks for TYPE_USER too, the
 * External box (out-of-cluster federation) never does. We skip the External box
 * so a cluster peer isn't offered a second, redundant way to be shared with.
 */
class MasterUserSearch implements ISearchPlugin {
	private string $currentUserId;

	public function __construct(
		private ShardingService  $shardingService,
		private InterServerClient $client,
		private LoggerInterface  $logger,
		private IRequest         $request,
		IUserSession             $userSession,
	) {
		$this->currentUserId = $userSession->getUser()?->getUID() ?? '';
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		// Avoid duplicate work for offsets > 0 — master results are not paginated here.
		if ($offset > 0) {
			return false;
		}

		// Only feed the "Internal shares" box. It always requests TYPE_USER
		// alongside the remote types; the "External shares" box (out-of-cluster
		// federation) never does — and cluster peers must not appear there too.
		$types = $this->request->getParam('shareType');
		if (is_array($types)) {
			$hasUser = false;
			foreach ($types as $t) {
				if ((int)$t === IShare::TYPE_USER) {
					$hasUser = true;
					break;
				}
			}
			if (!$hasUser) {
				return false;
			}
		}

		$masterInternalUrl = $this->shardingService->masterInternalUrl();
		if ($masterInternalUrl === '') {
			return false;
		}

		$data = $this->client->getDirect($masterInternalUrl, 'internal/users/search', [
			'q'     => $search,
			'limit' => $limit,
		]);

		if (!is_array($data) || !isset($data['users'])) {
			return false;
		}

		$resultType = new SearchResultType('remotes');
		$wide  = [];
		$exact = [];

		$masterUrl  = $this->shardingService->masterUrl();
		$masterHost = preg_replace('#^https?://#', '', rtrim($masterUrl, '/'));

		foreach ($data['users'] as $u) {
			$userId      = (string)($u['user_id'] ?? '');
			$displayName = (string)($u['display_name'] ?? $userId);

			if ($userId === '') {
				continue;
			}
			// Skip the current user — they are on this node already.
			if ($userId === $this->currentUserId) {
				continue;
			}
			// Skip users whose home silo IS this node — the local user backend
			// already returns them as a real local target; emitting a federated
			// duplicate is exactly the double-listing we're removing. (On the
			// master this is what keeps master-resident users local.)
			if ($this->shardingService->isThisNode((string)($u['silo_url'] ?? ''))) {
				continue;
			}

			// Use master host as the stable canonical identity so shares survive
			// silo reassignments without any share_with updates.
			$cloudId   = $userId . '@' . $masterHost;
			$shareWith = $cloudId;

			// Present cluster peers WITHOUT exposing the silo host (silo-invisibility):
			// label with the user's own id, not user@master-host, and omit `server`
			// so the share dialog doesn't render an "on {host}" subname. The share
			// still routes correctly — shareWith carries the canonical @master identity
			// and the OCS API derives the remote from it (server was display-only).
			$entry = [
				'label' => $displayName . ' (' . $userId . ')',
				'uuid'  => $userId,
				'name'  => $displayName,
				'value' => [
					'shareType'       => IShare::TYPE_REMOTE,
					'shareWith'       => $shareWith,
					'isTrustedServer' => true,
				],
			];

			$lowerSearch = strtolower($search);
			if (strtolower($userId) === $lowerSearch || strtolower($cloudId) === $lowerSearch) {
				$searchResult->markExactIdMatch($resultType);
				$exact[] = $entry;
			} else {
				$wide[] = $entry;
			}
		}

		$searchResult->addResultSet($resultType, $wide, $exact);
		return false;
	}
}
