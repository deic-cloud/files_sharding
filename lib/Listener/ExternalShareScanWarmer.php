<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCA\Files_Sharing\External\Manager as ExternalManager;
use OCA\Files_Sharing\External\Storage as ExternalStorage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Federation\ICloudIdManager;
use OCP\Files\Cache\IScanner;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Warm the storage cache for a just-accepted federated (external) share.
 *
 * Stock Nextcloud bug (RemoteController::extendShareInfo): each remote_shares
 * OCS entry is enriched with $mountPointNode->getPermissions() (+ mimetype,
 * mtime, file_id, size). On a freshly-accepted mount whose storage has not been
 * scanned yet, every getter returns null, so the entry ships "permissions":
 * null. The stock @nextcloud/files Node builder rejects null permissions
 * ("Invalid permissions"), the files_sharing SharingService drops the entry,
 * and the share silently vanishes from "Shared with you" until a full page
 * reload finally scans the storage. A "Refresh content" does NOT recover it.
 *
 * Our own silo<->silo shares dodge this because the peer scan completes
 * server-side on accept; only first-contact EXTERNAL OCM shares hit it.
 *
 * External\Manager::acceptShare dispatches FederatedShareAddedEvent right after
 * a share is accepted, so we listen for it and eagerly do a shallow scan of the
 * share's storage root. That populates oc_filecache server-side (keyed by the
 * storage id shared::md5(token@remote), the SAME storage the mount uses), so the
 * user's very next fetch reads real permissions and the share appears at once.
 *
 * This is deliberately app-level: no Nextcloud core patch, works on a vanilla
 * install. If the core External\* classes ever move, the scan is wrapped in a
 * catch and simply falls back to stock behaviour (visible after a reload).
 */
class ExternalShareScanWarmer implements IEventListener {
	public function __construct(
		private IDBConnection   $db,
		private IUserSession    $userSession,
		private ICloudIdManager $cloudIdManager,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof FederatedShareAddedEvent)) {
			return;
		}

		// acceptShare fires this with the accepting user in the session. The
		// auto-accept path (trusted-server / peer) has no session and already
		// scans server-side, so there is nothing to warm here.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$remote = $event->getRemote();
		$userId = $user->getUID();

		// Every accepted external share this user holds from this remote. The
		// event carries only the remote, so we warm all of them (cheap: one
		// shallow PROPFIND each, and accepts are rare).
		$qb = $this->db->getQueryBuilder();
		$qb->select('share_token', 'password', 'mountpoint', 'owner')
			->from('share_external')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('remote', $qb->createNamedParameter($remote)))
			->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(IShare::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		foreach ($rows as $row) {
			try {
				$cloudId = $this->cloudIdManager->getCloudId((string)$row['owner'], $remote);
				$storage = new ExternalStorage([
					'HttpClientService' => Server::get(IClientService::class),
					'manager'           => Server::get(ExternalManager::class),
					'cloudId'           => $cloudId,
					'mountpoint'        => (string)$row['mountpoint'],
					'token'             => (string)$row['share_token'],
					'password'          => (string)($row['password'] ?? ''),
				]);
				// Shallow scan of the root: one PROPFIND, enough to cache the
				// mount root's permissions/mtime/size that RemoteController reads.
				$storage->getScanner()->scan('', IScanner::SCAN_SHALLOW);
			} catch (\Throwable $e) {
				$this->logger->warning('files_sharding: ExternalShareScanWarmer: could not warm '
					. $remote . ': ' . $e->getMessage());
			}
		}
	}
}
