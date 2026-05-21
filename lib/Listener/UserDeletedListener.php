<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $interServerClient,
		private LoggerInterface   $logger,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) return;
		if (!$this->shardingService->isMaster()) return;

		$userId = $event->getUser()->getUID();
		$server = $this->shardingService->getUserServer($userId);
		if ($server === null) return;

		$siloUrl = $server->getUrl();
		$result  = $this->interServerClient->postDirect(
			$siloUrl,
			'internal/users/' . urlencode($userId) . '/delete',
		);

		if ($result === null) {
			$this->logger->error("files_sharding: failed to delete user {$userId} on silo {$siloUrl}");
		} else {
			$this->logger->info("files_sharding: deleted user {$userId} on silo {$siloUrl}");
		}

		// Remove the master-side silo assignment regardless of silo outcome.
		$this->shardingService->unassignUser($userId);
	}
}
