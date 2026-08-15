<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Searches users on the master and returns them as federated (TYPE_REMOTE) share targets.
 * Only active on silos (isMaster() === false). On the master, all users are local.
 */
class MasterUserSearch implements ISearchPlugin {
	private string $currentUserId;

	public function __construct(
		private ShardingService  $shardingService,
		private InterServerClient $client,
		private LoggerInterface  $logger,
		IUserSession             $userSession,
	) {
		$this->currentUserId = $userSession->getUser()?->getUID() ?? '';
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		// Only run on silos; the master already searches local users natively.
		if ($this->shardingService->isMaster()) {
			return false;
		}
		// Avoid duplicate work for offsets > 0 — master results are not paginated here.
		if ($offset > 0) {
			return false;
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
			// Skip the current user — they are on this silo already.
			if ($userId === $this->currentUserId) {
				continue;
			}

			// Use master host as the stable canonical identity so shares survive
			// silo reassignments without any share_with updates.
			$cloudId   = $userId . '@' . $masterHost;
			$shareWith = $cloudId;

			$entry = [
				'label' => $displayName . ' (' . $cloudId . ')',
				'uuid'  => $userId,
				'name'  => $displayName,
				'value' => [
					'shareType'       => IShare::TYPE_REMOTE,
					'shareWith'       => $shareWith,
					'server'          => $masterUrl,
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
