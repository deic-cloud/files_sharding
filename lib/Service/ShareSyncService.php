<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\InvalidateMountCacheEvent;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Fetches pending federated shares from the master and mirrors them into the
 * local oc_share_external table, creating a notification bell entry for each
 * new share so the user can accept without logging out.
 *
 * Used by SyncExternalSharesListener (at login) and by
 * InternalController::syncShares (push-triggered by the master).
 */
class ShareSyncService {
	public function __construct(
		private ShardingService      $shardingService,
		private InterServerClient    $client,
		private IDBConnection        $db,
		private INotificationManager $notificationManager,
		private IURLGenerator        $urlGenerator,
		private IUserManager         $userManager,
		private IEventDispatcher     $eventDispatcher,
		private LoggerInterface      $logger,
	) {
	}

	/**
	 * Sync pending shares for $userId from master to local silo DB.
	 * Returns the number of newly inserted shares.
	 */
	public function syncForUser(string $userId): int {
		$masterUrl = $this->shardingService->masterInternalUrl();
		if ($masterUrl === '') {
			return 0;
		}

		$data = $this->client->getDirect($masterUrl, 'internal/users/' . rawurlencode($userId) . '/external-shares');
		if (!is_array($data) || !isset($data['shares'])) {
			$this->logger->warning("files_sharding: ShareSyncService: failed to fetch shares for {$userId} from master");
			return 0;
		}

		// An EMPTY shares list is a valid authoritative state (the user has no
		// federated shares on the master); we must still reconcile (prune stale
		// local mirrors), so do NOT early-return. We only bail on a FETCH FAILURE
		// (handled above), so a transient master outage never prunes.

		// Authoritative set from the master, keyed remote(sans trailing slash)+remote_id.
		$wanted = [];
		foreach ($data['shares'] as $share) {
			$r  = rtrim((string)($share['remote'] ?? ''), '/');
			$ri = (string)($share['remote_id'] ?? '');
			if ($r !== '' && $ri !== '') {
				$wanted[$r . "\0" . $ri] = true;
			}
		}

		// Existing local mirrors for this user (id needed for pruning).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'remote', 'remote_id')
		   ->from('share_external')
		   ->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		$cursor = $qb->executeQuery();
		$existing  = [];
		$localRows = [];
		while ($row = $cursor->fetch()) {
			$key = rtrim((string)$row['remote'], '/') . "\0" . (string)$row['remote_id'];
			$existing[$key] = true;
			$localRows[]    = ['id' => (int)$row['id'], 'key' => $key];
		}
		$cursor->closeCursor();

		$inserted = 0;
		foreach ($data['shares'] as $share) {
			$remote   = (string)($share['remote']      ?? '');
			$remoteId = (string)($share['remote_id']   ?? '');
			$token    = (string)($share['share_token'] ?? '');
			$name     = '/' . ltrim((string)($share['name'] ?? ''), '/');
			$owner    = (string)($share['owner']       ?? '');

			if ($remote === '' || $remoteId === '' || $token === '') {
				continue;
			}

			if (isset($existing[rtrim($remote, '/') . "\0" . $remoteId])) {
				continue;
			}

			// Intra-cluster origin (a trusted silo) → AUTO-ACCEPT + informational notice;
			// external/untrusted origin → stay PENDING with accept/decline
			// (Frederik 2026-08-16 share-consistency compromise).
			$isCluster  = $this->shardingService->isClusterServer($remote);
			$mountpoint = $isCluster
				? $this->uniqueMountpoint($userId, $name)
				: '{{TemporaryMountPointName#' . trim($name, '/') . '}}';
			$accepted   = $isCluster ? IShare::STATUS_ACCEPTED : IShare::STATUS_PENDING;

			try {
				// Explicit JS-safe id. This table's id has no cross-backend autoincrement
				// (the sqlite schema is BIGINT NOT NULL, not AUTOINCREMENT), and the old
				// snowflake ids exceed 2^53 — the browser rounds them on the accept action,
				// so the share can't be found. Microseconds-since-epoch stays well under
				// 2^53 (until ~year 2255) and is unique for sequential inserts.
				$id = (int) floor(microtime(true) * 1000000);
				$iq = $this->db->getQueryBuilder();
				$iq->insert('share_external')
				   ->setValue('id',              $iq->createNamedParameter($id, IQueryBuilder::PARAM_INT))
				   ->setValue('parent',          $iq->createNamedParameter('-1'))
				   ->setValue('share_type',      $iq->createNamedParameter((int)($share['share_type'] ?? IShare::TYPE_USER), IQueryBuilder::PARAM_INT))
				   ->setValue('remote',          $iq->createNamedParameter($remote))
				   ->setValue('remote_id',       $iq->createNamedParameter($remoteId))
				   ->setValue('share_token',     $iq->createNamedParameter($token))
				   ->setValue('password',        $iq->createNamedParameter((string)($share['password'] ?? '')))
				   ->setValue('name',            $iq->createNamedParameter($name))
				   ->setValue('owner',           $iq->createNamedParameter($owner))
				   ->setValue('user',            $iq->createNamedParameter($userId))
				   ->setValue('mountpoint',      $iq->createNamedParameter($mountpoint))
				   ->setValue('mountpoint_hash', $iq->createNamedParameter(md5($mountpoint)))
				   ->setValue('accepted',        $iq->createNamedParameter($accepted, IQueryBuilder::PARAM_INT));
				$iq->executeStatement();
				$inserted++;

				// Build cloud ID: strip protocol+trailing slash so resolveCloudId() works in NC34
				$remoteHost   = preg_replace('#^https?://#', '', rtrim($remote, '/'));
				$ownerCloudId = $owner . '@' . $remoteHost;

				if ($isCluster) {
					// Auto-accept: fire the same event External\Manager::acceptShare would,
					// so ExternalShareScanWarmer warms the mount (no null-perms) and
					// ProxyShareAcceptanceListener tells the owner it was accepted; then an
					// informational, action-less notice.
					// Dispatch with the exact stored remote (trailing slash and all) so the
					// warmer's `WHERE remote = …` matches this row and scans it.
					$this->eventDispatcher->dispatchTyped(new FederatedShareAddedEvent($remote));
					$this->notifyShareReceived($userId, (string)$id, $owner, trim($name, '/'));
				} else {
					$this->notifyPendingShare($userId, (string)$id, $ownerCloudId, trim($name, '/'));
				}
			} catch (\Throwable $e) {
				$this->logger->warning("files_sharding: ShareSyncService: failed to insert share for {$userId}: " . $e->getMessage());
			}
		}

		// Prune local mirrors the master no longer has = the owner unshared them.
		// This is the delete-propagation the add-only mirror never did: core OCM
		// removes the master's oc_share_external on unshare; the silo catches up here
		// on the next sync. Assumes every federated share the user has is
		// master-mediated (true in the current model; revisit if silos ever receive
		// direct external OCM shares the master doesn't know about).
		$pruned = 0;
		foreach ($localRows as $lr) {
			if (isset($wanted[$lr['key']])) {
				continue;
			}
			try {
				$dq = $this->db->getQueryBuilder();
				$dq->delete('share_external')
				   ->where($dq->expr()->eq('id', $dq->createNamedParameter($lr['id'], IQueryBuilder::PARAM_INT)));
				$dq->executeStatement();
				$pruned++;
			} catch (\Throwable $e) {
				$this->logger->warning("files_sharding: ShareSyncService: failed to prune stale share {$lr['id']} for {$userId}: " . $e->getMessage());
			}
		}

		if ($inserted > 0 || $pruned > 0) {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$this->eventDispatcher->dispatchTyped(new InvalidateMountCacheEvent($user));
			}
			if ($pruned > 0) {
				$this->logger->info("files_sharding: ShareSyncService: pruned {$pruned} stale mirror(s) for {$userId}");
			}
		}

		return $inserted;
	}

	private function notifyPendingShare(string $userId, string $shareId, string $owner, string $name): void {
		$pendingUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkTo('', 'ocs/v2.php/apps/files_sharing/api/v1/remote_shares/pending/' . $shareId)
		);

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('files_sharing')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('remote_share', $shareId)
			->setSubject('remote_share', [$owner, $owner, $name, $owner]);

		$decline = $notification->createAction();
		$decline->setLabel('decline')->setLink($pendingUrl, 'DELETE');
		$notification->addAction($decline);

		$accept = $notification->createAction();
		$accept->setLabel('accept')->setLink($pendingUrl, 'POST');
		$notification->addAction($accept);

		$this->notificationManager->notify($notification);
	}

	/** Informational, action-less "X shared Y with you" notice for an auto-accepted share. */
	private function notifyShareReceived(string $userId, string $shareId, string $sharer, string $name): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('files_sharding')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('remote_share', $shareId)
			->setSubject('share_received', [$sharer, $name]);
		$this->notificationManager->notify($notification);
	}

	/** A mountpoint ("/media") not already used by another of this user's mirrors; appends " (N)" on collision. */
	private function uniqueMountpoint(string $userId, string $name): string {
		$candidate = $name;
		$i = 2;
		while ($this->mountpointTaken($userId, $candidate)) {
			$candidate = rtrim($name, '/') . ' (' . $i . ')';
			$i++;
		}
		return $candidate;
	}

	private function mountpointTaken(string $userId, string $mp): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('share_external')
		   ->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
		   ->andWhere($qb->expr()->eq('mountpoint_hash', $qb->createNamedParameter(md5($mp))))
		   ->setMaxResults(1);
		$c = $qb->executeQuery();
		$taken = $c->fetch() !== false;
		$c->closeCursor();
		return $taken;
	}
}
