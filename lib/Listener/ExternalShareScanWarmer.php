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

		// acceptShare dispatches this for BOTH interactive accepts (a user session
		// is present) AND trusted-server AUTO-accepts, which run server-to-server
		// with NO session. Peer-silo shares auto-accept, so keying off the session
		// (as before) missed exactly the case that needs warming. Key off the
		// event's remote instead: the storage we build below is keyed by
		// shared::md5(token@remote) and needs only the share row, not the
		// recipient user, so no session is required.
		$remote = $event->getRemote();
		if ($remote === '') {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('share_token', 'password', 'mountpoint', 'owner')
			->from('share_external')
			->where($qb->expr()->eq('remote', $qb->createNamedParameter($remote)))
			->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(IShare::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		// If a session IS present (interactive accept), scope to that user.
		$sessionUser = $this->userSession->getUser();
		if ($sessionUser !== null) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($sessionUser->getUID())));
		}
		// Warm ONLY the share that was just added — the newest accepted row for this
		// remote (acceptShare inserts the row, then dispatches this event). Warming
		// EVERY accepted share for the remote made this O(n): on the master (which
		// relays every silo user's federated shares) the set grows without bound, and
		// a single accept then fired a PROPFIND per existing share — including stale
		// ones whose token is dead, which each block ~5s. That pushed the inbound
		// OCM addShare past the sender's 5s client timeout, causing a retry storm
		// (and duplicate mirror rows). One PROPFIND is all the just-added share needs.
		$qb->orderBy('id', 'DESC')->setMaxResults(1);
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
				$t0 = microtime(true);
				$storage->getScanner()->scan('', IScanner::SCAN_SHALLOW);
				$this->logger->info('files_sharding: ExternalShareScanWarmer: warmed ' . $remote
					. ' (' . trim((string)$row['mountpoint'], '/') . ') in '
					. (int)round((microtime(true) - $t0) * 1000) . 'ms');
			} catch (\Throwable $e) {
				$this->logger->warning('files_sharding: ExternalShareScanWarmer: could not warm '
					. $remote . ': ' . $e->getMessage());
			}
		}
	}
}
