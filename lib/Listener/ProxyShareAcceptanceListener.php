<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Runs on silos when a federated share is accepted.
 *
 * The canonical share recipient is alice@master-host, so when Alice's silo
 * sends the SHARE_ACCEPTED OCM notification to Bob's silo the request is
 * rejected: Bob expects it to originate from the master (the host in
 * alice@master-host), not from Alice's silo.
 *
 * This listener proxies the acceptance through the master immediately after
 * the local acceptance succeeds, so the notification Bob receives comes from
 * the correct origin.
 *
 * @implements IEventListener<FederatedShareAddedEvent>
 */
class ProxyShareAcceptanceListener implements IEventListener {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private IDBConnection     $db,
		private IUserSession      $userSession,
		private LoggerInterface   $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof FederatedShareAddedEvent)) {
			return;
		}
		// Only proxy on silos; the master is already the origin for its own shares.
		if ($this->shardingService->isMaster()) {
			return;
		}

		$masterUrl = $this->shardingService->masterInternalUrl();
		if ($masterUrl === '') {
			return;
		}

		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return;
		}
		$userId = $currentUser->getUID();
		$remote = $event->getRemote();

		// FederatedShareAddedEvent also fires on the SENDER's side when a share
		// is successfully delivered to a remote. On a silo, that means Bob just
		// shared with alice@master-host and $remote == master URL. Nothing to proxy.
		$masterPublicUrl = $this->shardingService->masterUrl();
		if ($masterPublicUrl !== '' && rtrim($remote, '/') === $masterPublicUrl) {
			return;
		}

		// Find the share that was just accepted. It's the most recently accepted
		// share from this remote for this user.
		$qb = $this->db->getQueryBuilder();
		$qb->select('remote_id', 'share_token')
		   ->from('share_external')
		   ->where($qb->expr()->eq('user',     $qb->createNamedParameter($userId)))
		   ->andWhere($qb->expr()->eq('remote', $qb->createNamedParameter($remote)))
		   ->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(IShare::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)))
		   ->orderBy('id', 'DESC')
		   ->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row    = $cursor->fetch();
		$cursor->closeCursor();

		if (!$row) {
			$this->logger->debug("files_sharding: ProxyShareAcceptanceListener: no accepted share found for {$userId} from {$remote}");
			return;
		}

		$remoteId    = (string)$row['remote_id'];
		$sharedSecret = (string)$row['share_token'];

		$result = $this->client->postDirect($masterUrl, 'internal/shares/proxy-accept', [
			'remote'       => $remote,
			'remoteId'     => $remoteId,
			'sharedSecret' => $sharedSecret,
		]);

		if ($result === null || !($result['success'] ?? false)) {
			$this->logger->warning(
				"files_sharding: ProxyShareAcceptanceListener: master failed to proxy SHARE_ACCEPTED for {$userId} to {$remote}/{$remoteId}"
			);
		} else {
			$this->logger->info(
				"files_sharding: ProxyShareAcceptanceListener: master proxied SHARE_ACCEPTED for {$userId} to {$remote}/{$remoteId}"
			);
		}
	}
}
