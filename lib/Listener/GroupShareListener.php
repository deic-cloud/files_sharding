<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\GroupShareFanoutService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Cross-silo group sharing — delivery model A (per-member federated fan-out).
 *
 * On a TYPE_GROUP share, reconcile the group's fan-out on THIS node: mint one real
 * federated child `owner→member@master` per remote member (see
 * GroupShareFanoutService). On unshare, prune this group share's children. The
 * children ride the user-federated path (native mount, proper token) — no public
 * link. Membership changes are handled by GroupMembershipListener (master
 * broadcasts a reconcile), so this listener only covers create/delete of the
 * group share itself.
 *
 * @implements IEventListener<ShareCreatedEvent|ShareDeletedEvent>
 */
class GroupShareListener implements IEventListener {
	public function __construct(
		private GroupShareFanoutService $fanout,
		private LoggerInterface         $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof ShareCreatedEvent) {
				$share = $event->getShare();
				if ($share->getShareType() !== IShare::TYPE_GROUP) {
					return;
				}
				$this->fanout->reconcileGid((string)$share->getSharedWith());
			} elseif ($event instanceof ShareDeletedEvent) {
				$share = $event->getShare();
				if ($share->getShareType() !== IShare::TYPE_GROUP) {
					return;
				}
				$this->fanout->pruneForGroupShare((int)$share->getId());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: GroupShareListener: ' . $e->getMessage());
		}
	}
}
