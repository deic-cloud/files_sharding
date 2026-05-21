<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\ShareSyncService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use Psr\Log\LoggerInterface;

/**
 * Runs on silos at login. Mirrors any pending federated shares that were
 * delivered to the master (alice@master-host) into the local oc_share_external
 * so Alice can accept them here without needing to visit the master.
 *
 * This also makes silo migrations transparent: after a move, Alice's next login
 * to the new silo pulls all her shares as pending and she re-accepts with one click.
 *
 * @implements IEventListener<UserLoggedInEvent|UserLoggedInWithCookieEvent>
 */
class SyncExternalSharesListener implements IEventListener {
	public function __construct(
		private ShardingService  $shardingService,
		private ShareSyncService $syncService,
		private LoggerInterface  $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent) && !($event instanceof UserLoggedInWithCookieEvent)) {
			return;
		}
		if ($this->shardingService->isMaster()) {
			return;
		}
		if ($this->shardingService->masterInternalUrl() === '') {
			return;
		}

		$userId   = $event->getUser()->getUID();
		$inserted = $this->syncService->syncForUser($userId);

		if ($inserted > 0) {
			$this->logger->info("files_sharding: SyncExternalSharesListener: synced {$inserted} pending share(s) for {$userId} from master");
		}
	}
}
