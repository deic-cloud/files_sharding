<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Collaboration;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Surfaces users elsewhere in the same master/silo cluster as federated
 * (TYPE_REMOTE) share targets, so a silo user can find and share with a user
 * hosted on another silo.
 *
 * Box-tied identity: each user is presented at their ACTUAL home silo
 * (user@theirSilo), so the share is delivered straight to where their files
 * live via native OCM — no hop through the master. (An earlier version used the
 * master host as a "portable" identity; that routed every share through the
 * master and fought OCM, so it was dropped.)
 *
 * Only active on silos (isMaster() === false); on the master, cluster users are
 * found by Nextcloud's native local search. Users hosted on THIS silo are also
 * skipped here — native local search already surfaces them.
 */
class MasterUserSearch implements ISearchPlugin {
	private string $currentUserId;

	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private IConfig           $config,
		private LoggerInterface   $logger,
		IUserSession              $userSession,
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

		// This silo's own host, so we can skip users who live here (native search
		// already returns them as local TYPE_USER collaborators).
		$selfHost = preg_replace('#^https?://#', '', rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/'));
		$lowerSearch = strtolower($search);

		foreach ($data['users'] as $u) {
			$userId      = (string)($u['user_id'] ?? '');
			$displayName = (string)($u['display_name'] ?? $userId);
			$siloUrl     = rtrim((string)($u['silo_url'] ?? ''), '/');

			if ($userId === '' || $siloUrl === '') {
				continue;
			}
			if ($userId === $this->currentUserId) {
				continue;
			}
			$siloHost = preg_replace('#^https?://#', '', $siloUrl);
			if ($siloHost !== '' && $siloHost === $selfHost) {
				continue;
			}

			// Box-tied: canonical identity is user@theirSilo; the share goes
			// directly to that silo via native OCM.
			$cloudId = $userId . '@' . $siloHost;

			$entry = [
				'label' => $displayName . ' (' . $cloudId . ')',
				'uuid'  => $userId,
				'name'  => $displayName,
				'value' => [
					'shareType'       => IShare::TYPE_REMOTE,
					'shareWith'       => $cloudId,
					'server'          => $siloUrl,
					'isTrustedServer' => true,
				],
			];

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
