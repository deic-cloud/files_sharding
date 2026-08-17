<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesSharding\Service\ShareSyncService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use Psr\Log\LoggerInterface;

/**
 * Runs on silos to mirror any federated shares delivered to the master
 * (alice@master-host) into the local oc_share_external, so a silo user sees them
 * without visiting the master. Fires on TWO occasions:
 *   - at login (UserLoggedIn*) — the initial catch-up;
 *   - on every Files page load (LoadAdditionalScriptsEvent) — so a plain reload
 *     ALWAYS reflects the user's current shares.
 *
 * The master already PUSHES to the silo the instant an OCM share lands
 * (OcmShareReceivedMiddleware), but that races a user who reloads in the ~1s
 * before the push completes — making "does it show?" non-deterministic. Syncing
 * on Files load closes that race: a reload is a guaranteed refresh, whether or
 * not the push has arrived yet.
 *
 * Also makes silo migrations transparent: after a move, the next load pulls all
 * the user's shares.
 *
 * @implements IEventListener<UserLoggedInEvent|UserLoggedInWithCookieEvent|LoadAdditionalScriptsEvent>
 */
class SyncExternalSharesListener implements IEventListener {
	public function __construct(
		private ShardingService  $shardingService,
		private ShareSyncService $syncService,
		private IUserSession     $userSession,
		private LoggerInterface  $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserLoggedInEvent || $event instanceof UserLoggedInWithCookieEvent) {
			$userId = $event->getUser()->getUID();
		} elseif ($event instanceof LoadAdditionalScriptsEvent) {
			$userId = $this->userSession->getUser()?->getUID() ?? '';
		} else {
			return;
		}
		if ($userId === '') {
			return;
		}
		if ($this->shardingService->isMaster()) {
			return;
		}
		if ($this->shardingService->masterInternalUrl() === '') {
			return;
		}

		$inserted = $this->syncService->syncForUser($userId);

		if ($inserted > 0) {
			$this->logger->info("files_sharding: SyncExternalSharesListener: synced {$inserted} pending share(s) for {$userId} from master");
		}
	}
}
