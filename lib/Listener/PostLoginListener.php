<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\RedirectState;
use OCA\FilesSharding\Service\ShardingService;
use OCA\FilesSharding\Service\TokenService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<UserLoggedInEvent|UserLoggedInWithCookieEvent> */
class PostLoginListener implements IEventListener {
	public function __construct(
		private ShardingService $shardingService,
		private TokenService    $tokenService,
		private RedirectState   $redirectState,
		private IRequest        $request,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent) && !($event instanceof UserLoggedInWithCookieEvent)) {
			return;
		}
		if (!$this->shardingService->isMaster()) {
			$this->logger->debug('files_sharding: PostLoginListener: not master, skipping redirect');
			return;
		}

		$userId = $event->getUser()->getUID();

		// On a fresh interactive login, auto-assign the user to a silo if they
		// have no assignment yet.  Cookie-based re-logins skip this so that an
		// unassigned user browsing the master doesn't get pushed out unexpectedly.
		if ($event instanceof UserLoggedInEvent && $this->shardingService->getUserServer($userId) === null) {
			$assigned = $this->shardingService->autoAssign($userId);
			if ($assigned === null) {
				$this->logger->debug("files_sharding: PostLoginListener: no silos registered, leaving {$userId} on master");
				return;
			}
		}

		$redirectTo = $this->shardingService->getRedirectUrl($userId);
		if ($redirectTo === null) {
			$this->logger->debug("files_sharding: PostLoginListener: no redirect for {$userId} (no silo assigned or already on home silo)");
			return;
		}

		$token = $this->tokenService->issue($userId);
		// Use the explicit /index.php/ front-controller form, not the clean-URL
		// form: some silo web servers (Apache with NC's clean-URL rewrite) drop
		// the query string on /apps/… but preserve it on /index.php/apps/… — and
		// without token+user the silo exchange fails with "Missing token or user".
		$url   = rtrim($redirectTo, '/') . '/index.php/apps/files_sharding/login'
			. '?token=' . urlencode($token)
			. '&user='  . urlencode($userId);

		// Thread the original deep link through to the silo exchange endpoint.
		// NC's login form preserves redirect_url as a POST field, so we read it
		// here and append it as &return= so exchange() can redirect there.
		$redirectUrl = (string)($this->request->getParam('redirect_url') ?? '');
		if ($redirectUrl !== '' && str_starts_with($redirectUrl, '/') && !str_starts_with($redirectUrl, '//')) {
			$url .= '&return=' . urlencode($redirectUrl);
		}

		$this->logger->warning("files_sharding: redirecting {$userId} → {$url}");
		$this->redirectState->set($url);
	}
}
