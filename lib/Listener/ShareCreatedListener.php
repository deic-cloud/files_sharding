<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Part 1 of the share-consistency compromise (Frederik 2026-08-16): a plain
 * local user share auto-accepts (NC default) but posts NO notification, whereas
 * federated shares do. Post an informational, action-less "X shared Y with you"
 * notice for local user shares so the recipient is told either way.
 *
 * Federated (TYPE_REMOTE) shares are handled by ShareSyncService on the
 * recipient's silo — this listener deliberately only fires for local shares.
 *
 * @implements IEventListener<ShareCreatedEvent>
 */
class ShareCreatedListener implements IEventListener {
	public function __construct(
		private INotificationManager $notificationManager,
		private IUserManager         $userManager,
		private IConfig              $config,
		private ShardingService      $shardingService,
		private IShareManager        $shareManager,
		private LoggerInterface      $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof ShareCreatedEvent)) {
			return;
		}
		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_USER) {
			return; // only plain local user shares; federated ones are ShareSyncService's job
		}

		$recipient = (string)$share->getSharedWith();
		if ($recipient === '') {
			return;
		}

		// Convert safety net: a local share to a user whose home silo is NOT this
		// node is a dead row — their storage lives elsewhere, so nothing arrives.
		// Re-route it as a federated @master share, which actually delivers. This
		// backs up ResidentUserFilter (which normally stops the dead local target
		// from being offered at all) and covers non-search paths — exact-id picks
		// and direct API calls. Only fires where residency is knowable (the master);
		// on a silo isResidentHere() is always true, so this never triggers.
		if (!$this->shardingService->isResidentHere($recipient)) {
			$this->convertToFederated($share, $recipient);
			return;
		}

		// Only when shares auto-accept (default). If manual accept is required
		// (shareapi_auto_accept_share=no), leave core's accept/decline notification
		// intact so the recipient can still accept — don't suppress or replace it.
		if ($this->config->getAppValue('core', 'shareapi_auto_accept_share', 'yes') !== 'yes') {
			return;
		}

		try {
			$sharerUid  = $share->getSharedBy() ?: $share->getShareOwner();
			$sharerUser = $this->userManager->get($sharerUid);
			$sharerName = $sharerUser !== null ? $sharerUser->getDisplayName() : $sharerUid;
			$name       = $share->getNode()->getName();

			$notification = $this->notificationManager->createNotification();
			$notification->setApp('files_sharding')
				->setUser($recipient)
				->setDateTime(new \DateTime())
				->setObject('local_share', (string)$share->getId())
				->setSubject('share_received', [$sharerName, $name]);
			$this->notificationManager->notify($notification);

			// Suppress core's "incoming_user_share" notification (moot Accept/Reject
			// actions on an auto-accepted share) so the recipient sees only our clean
			// informational one. files_sharding loads after files_sharing, so core's
			// has already been created by the time this listener runs.
			$dismiss = $this->notificationManager->createNotification();
			$dismiss->setApp('files_sharing')
				->setUser($recipient)
				->setObject('share', $share->getFullId());
			$this->notificationManager->markProcessed($dismiss);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: ShareCreatedListener: could not notify ' . $recipient . ': ' . $e->getMessage());
		}
	}

	/**
	 * Replace a dead local share to a non-resident user with a federated share to
	 * their canonical @master identity. The master relays it (loopback OCM into its
	 * own oc_share_external) and the recipient's silo mirrors + auto-accepts it via
	 * ShareSyncService — the same path a normal cross-silo share takes.
	 *
	 * Re-entrancy: createShare() fires ShareCreatedEvent for a TYPE_REMOTE share,
	 * which handle() ignores; deleteShare() fires ShareDeletedEvent, unhandled here.
	 * So this converts exactly once.
	 */
	private function convertToFederated(IShare $share, string $recipient): void {
		try {
			$masterHost = preg_replace('#^https?://#', '', rtrim($this->shardingService->masterUrl(), '/'));
			if ($masterHost === '') {
				return; // no master configured — leave the (dead) local share rather than lose it
			}
			$node      = $share->getNode();
			$sharedBy  = $share->getSharedBy();
			$perms     = $share->getPermissions();

			// Core already posted an incoming_user_share notice for the (about-to-be-
			// deleted) local share; clear it so the recipient only gets the federated
			// path's clean notice. (The OcmShareReceivedMiddleware also sweeps master-
			// side notices for silo-homed users, but dismiss here too for immediacy.)
			try {
				$dismiss = $this->notificationManager->createNotification();
				$dismiss->setApp('files_sharing')->setUser($recipient)->setObject('share', $share->getFullId());
				$this->notificationManager->markProcessed($dismiss);
			} catch (\Throwable) {
			}

			// Drop the dead local share first so its mount can't shadow the federated one.
			$this->shareManager->deleteShare($share);

			$new = $this->shareManager->newShare();
			$new->setNode($node)
				->setShareType(IShare::TYPE_REMOTE)
				->setSharedWith($recipient . '@' . $masterHost)
				->setSharedBy($sharedBy)
				->setPermissions($perms);
			$this->shareManager->createShare($new);

			$this->logger->info('files_sharding: ShareCreatedListener: re-routed dead local share to non-resident '
				. $recipient . ' as federated ' . $recipient . '@' . $masterHost);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: ShareCreatedListener: convertToFederated failed for '
				. $recipient . ': ' . $e->getMessage());
		}
	}
}
