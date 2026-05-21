<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\RedirectState;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedOutEvent;

/** @implements IEventListener<UserLoggedOutEvent> */
class PostLogoutListener implements IEventListener {
	public function __construct(
		private ShardingService $shardingService,
		private RedirectState   $redirectState,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedOutEvent)) {
			return;
		}

		// Only act on silos — the master keeps its own login page after logout.
		if ($this->shardingService->isMaster()) {
			return;
		}

		$masterUrl = $this->shardingService->masterUrl();
		if ($masterUrl === '') {
			return;
		}

		$this->redirectState->set(rtrim($masterUrl, '/') . '/index.php/login');
	}
}
