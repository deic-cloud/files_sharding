<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\CloudFederationAPI\Controller\RequestHandlerController;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Runs on the master after every OCM addShare request.
 *
 * When Bob shares a file with alice@master-host, the master stores the share
 * and creates a bell notification — but Alice's silo knows nothing until her
 * next login. This middleware detects the successful OCM reception and
 * immediately calls the silo's sync endpoint so Alice sees the pending share
 * (and its notification) without having to log out and back in.
 */
class OcmShareReceivedMiddleware extends Middleware {
	public function __construct(
		private ShardingService $shardingService,
		private InterServerClient $client,
		private INotificationManager $notificationManager,
		private IDBConnection $db,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	/** Recipient uid of an in-flight OCM unshare, captured before core deletes the row. */
	private ?string $pendingUnshareUser = null;

	/**
	 * On the master, capture the recipient of an incoming OCM unshare BEFORE core's
	 * handler deletes the oc_share_external row — so afterController can push a reconcile
	 * to that recipient's silo and prune the stale mirror immediately (rather than
	 * waiting for the recipient's next login/sync; matters for WebDAV access, which
	 * never triggers a login-gated sync).
	 */
	public function beforeController(Controller $controller, string $methodName): void {
		$this->pendingUnshareUser = null;
		if (!$this->shardingService->isMaster()) {
			return;
		}
		if (!($controller instanceof RequestHandlerController) || $methodName !== 'receiveNotification') {
			return;
		}
		if ((string)$this->request->getParam('notificationType') !== 'SHARE_UNSHARED') {
			return;
		}
		$providerId   = (string)$this->request->getParam('providerId');
		$notification  = $this->request->getParam('notification');
		$token = is_array($notification) ? (string)($notification['sharedSecret'] ?? '') : '';
		if ($providerId === '' || $token === '') {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user')->from('share_external')
				->where($qb->expr()->eq('remote_id', $qb->createNamedParameter($providerId)))
				->andWhere($qb->expr()->eq('share_token', $qb->createNamedParameter($token)))
				->setMaxResults(1);
			$cur = $qb->executeQuery();
			$row = $cur->fetch();
			$cur->closeCursor();
			if ($row !== false && !empty($row['user'])) {
				$this->pendingUnshareUser = (string)$row['user'];
			}
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: OcmShareReceivedMiddleware: unshare recipient lookup failed: ' . $e->getMessage());
		}
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!$this->shardingService->isMaster()) {
			return $response;
		}

		// OCM unshare (recipient captured in beforeController): push a reconcile to the
		// recipient's silo so the now-removed mirror is pruned at once — not gated on
		// their next login/sync, so WebDAV access sees correct state too.
		if ($this->pendingUnshareUser !== null) {
			$this->pushShareSync($this->pendingUnshareUser);
			$this->pendingUnshareUser = null;
		}

		if (!($controller instanceof RequestHandlerController) || $methodName !== 'addShare') {
			return $response;
		}

		if ($response->getStatus() !== Http::STATUS_CREATED) {
			return $response;
		}

		if (!($response instanceof JSONResponse)) {
			return $response;
		}

		$data   = $response->getData();
		$userId = (string)($data['recipientUserId'] ?? '');
		if ($userId === '') {
			return $response; // group share — skip for now
		}

		// Idempotent receive: if the sender retried (e.g. it hit its own OCM client
		// timeout while we were still processing), core will have inserted a SECOND
		// oc_share_external row for the same (remote_id, share_token). Core's own
		// lookups then do findOneQuery() on that pair and throw
		// MultipleObjectsReturnedException — which is exactly the 400 that broke the
		// /ocm/notifications (unshare/permission) path. Collapse duplicates now,
		// keeping the earliest row, so a redelivery is a no-op rather than a landmine.
		$this->collapseDuplicateExternalShares($userId);

		$server = $this->shardingService->getUserServer($userId);
		if ($server === null) {
			return $response; // no silo assignment on record — leave as-is
		}
		if ($this->shardingService->isSelf($server)) {
			// Recipient lives on THIS (master) node. Core auto-accepts the trusted
			// share silently — no notification — whereas silo recipients get our clean
			// "X shared Y with you" via ShareSyncService. Post the same notice here so
			// master-resident recipients have parity. (No cross-node push needed.)
			$this->notifyMasterResidentRecipient($userId);
			return $response;
		}

		$siloUrl = $this->shardingService->apiUrlForServer($server);
		$result  = $this->client->postDirect($siloUrl, 'internal/users/' . rawurlencode($userId) . '/sync-shares', []);

		if ($result === null) {
			$this->logger->warning("files_sharding: OcmShareReceivedMiddleware: failed to push sync for {$userId} to {$siloUrl}");
		} else {
			$inserted = (int)($result['inserted'] ?? 0);
			$this->logger->info("files_sharding: OcmShareReceivedMiddleware: pushed sync for {$userId} to {$siloUrl} ({$inserted} inserted)");
		}

		// The recipient lives on a silo and only ever acts on their silo, where the
		// share is mirrored (auto-accepted for cluster origins, with our own clean
		// notice; pending with accept/decline for external partners). The stock
		// files_sharing notification core just created here on the MASTER — a moot
		// remote_share/incoming_user_share the user never sees in the right place —
		// is pure relay noise (and would email them). Dismiss it.
		try {
			$dismiss = $this->notificationManager->createNotification();
			$dismiss->setApp('files_sharing')->setUser($userId);
			$this->notificationManager->markProcessed($dismiss);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: OcmShareReceivedMiddleware: could not clear master-side notice for ' . $userId . ': ' . $e->getMessage());
		}

		return $response;
	}

	/**
	 * Post the clean "X shared Y with you" notice for a master-resident recipient
	 * of a federated share, matching what ShareSyncService posts for silo recipients
	 * (core auto-accepted it silently, so there's nothing to build on). Reads the
	 * just-created row from the master's own oc_share_external.
	 */
	/** Push a full share reconcile to $userId's home silo (prunes stale mirrors + mounts new). */
	private function pushShareSync(string $userId): void {
		$server = $this->shardingService->getUserServer($userId);
		if ($server === null || $this->shardingService->isSelf($server)) {
			return; // recipient not on a silo (or lives here) — nothing to push
		}
		$siloUrl = $this->shardingService->apiUrlForServer($server);
		$result  = $this->client->postDirect($siloUrl, 'internal/users/' . rawurlencode($userId) . '/sync-shares', []);
		if ($result === null) {
			$this->logger->warning("files_sharding: OcmShareReceivedMiddleware: failed to push reconcile for {$userId} to {$siloUrl}");
		} else {
			$this->logger->info("files_sharding: OcmShareReceivedMiddleware: pushed reconcile for {$userId} to {$siloUrl}");
		}
	}

	/**
	 * Collapse duplicate oc_share_external rows for $userId, keeping the earliest id
	 * of each (remote_id, share_token) pair. Duplicates arise when a sender redelivers
	 * an OCM share (retry after a client-side timeout); core inserts a fresh row each
	 * time, and the pair is what core treats as the unique share identity. Idempotent.
	 */
	private function collapseDuplicateExternalShares(string $userId): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'remote_id', 'share_token')
				->from('share_external')
				->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
				->orderBy('id', 'ASC');
			$cur = $qb->executeQuery();
			$rows = $cur->fetchAll();
			$cur->closeCursor();

			$seen = [];
			$dupIds = [];
			foreach ($rows as $row) {
				$key = (string)$row['remote_id'] . "\0" . (string)$row['share_token'];
				if (isset($seen[$key])) {
					$dupIds[] = (int)$row['id']; // a later row for an identity we already kept
				} else {
					$seen[$key] = true;
				}
			}
			if ($dupIds === []) {
				return;
			}
			$del = $this->db->getQueryBuilder();
			$del->delete('share_external')
				->where($del->expr()->in('id', $del->createParameter('ids')));
			$del->setParameter('ids', $dupIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY);
			$del->executeStatement();
			$this->logger->info('files_sharding: OcmShareReceivedMiddleware: collapsed '
				. count($dupIds) . ' duplicate external-share row(s) for ' . $userId);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: OcmShareReceivedMiddleware: dedupe failed for ' . $userId . ': ' . $e->getMessage());
		}
	}

	private function notifyMasterResidentRecipient(string $userId): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'owner', 'name')
			   ->from('share_external')
			   ->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			   ->orderBy('id', 'DESC')
			   ->setMaxResults(1);
			$cur = $qb->executeQuery();
			$row = $cur->fetch();
			$cur->closeCursor();
			if ($row === false) {
				return;
			}
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('files_sharding')
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('remote_share', (string)$row['id'])
				->setSubject('share_received', [(string)$row['owner'], trim((string)$row['name'], '/')]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: OcmShareReceivedMiddleware: could not notify master-resident ' . $userId . ': ' . $e->getMessage());
		}
	}
}
