<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\GroupShareFanoutService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * Keep cross-silo group shares in step with group membership.
 *
 * We act ONLY on the master (the authority that knows every user's home silo) and
 * broadcast a reconcile of the affected group to all nodes: owners with a share to
 * that group gain a federated child for a new member, or prune a leaver's child.
 * The reconcile is idempotent, so over-firing is harmless — a group with no shares
 * simply no-ops.
 *
 * TWO signals, because our groups use two backends:
 *   - user_group_admin's GroupMembersChangedEvent — the reliable signal for UGA
 *     groups. Core's UserAddedEvent/UserRemovedEvent do NOT fire for them (the UGA
 *     backend already reports the user in-group, so core's addUser short-circuits).
 *   - core UserAddedEvent/UserRemovedEvent — covers any plain Database-backed group.
 *
 * The UGA event is referenced by name only; if user_group_admin is absent it never
 * fires and this stays a no-op — no hard dependency.
 *
 * @implements IEventListener<UserAddedEvent|UserRemovedEvent|\OCA\UserGroupAdmin\Event\GroupMembersChangedEvent>
 */
class GroupMembershipListener implements IEventListener {
	public function __construct(
		private ShardingService         $shardingService,
		private GroupShareFanoutService $fanout,
		private LoggerInterface         $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->shardingService->isMaster()) {
			return;
		}
		$gid = null;
		if ($event instanceof UserAddedEvent || $event instanceof UserRemovedEvent) {
			$gid = $event->getGroup()->getGID();
		} elseif ($event instanceof \OCA\UserGroupAdmin\Event\GroupMembersChangedEvent) {
			$gid = $event->getGid();
		}
		if ($gid === null || $gid === '') {
			return;
		}
		try {
			$this->fanout->broadcastReconcile($gid);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: GroupMembershipListener: reconcile broadcast failed: ' . $e->getMessage());
		}
	}
}
