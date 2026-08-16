<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\Events\ShareCreatedEvent;
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
		// Only when shares auto-accept (default). If manual accept is required
		// (shareapi_auto_accept_share=no), leave core's accept/decline notification
		// intact so the recipient can still accept — don't suppress or replace it.
		if ($this->config->getAppValue('core', 'shareapi_auto_accept_share', 'yes') !== 'yes') {
			return;
		}
		$recipient = (string)$share->getSharedWith();
		if ($recipient === '') {
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
}
