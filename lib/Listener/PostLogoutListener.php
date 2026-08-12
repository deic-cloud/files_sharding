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

		// Only act on silos — the master handles its own logout.
		if ($this->shardingService->isMaster()) {
			return;
		}

		$masterUrl = $this->shardingService->masterUrl();
		if ($masterUrl === '') {
			return;
		}

		// Send the browser to the master's LOGOUT endpoint (which ends the master
		// session server-side, then lands on the front page) — NOT its login page.
		// This listener fires on a real logout (UserLoggedOutEvent) and its
		// redirectState is applied by RedirectMiddleware AFTER LogoutRedirectMiddleware,
		// so it is the authoritative target; pointing it at /login left the master
		// session alive and any hop there re-issued a login token → re-login loop.
		$this->redirectState->set(rtrim($masterUrl, '/') . '/index.php/apps/files_sharding/logout');
	}
}
