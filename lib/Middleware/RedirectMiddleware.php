<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Controller\LoginController;
use OCA\FilesSharding\Service\RedirectState;
use OCA\FilesSharding\Service\ShardingService;
use OCA\FilesSharding\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Two-phase silo redirect:
 *
 * Phase 1 (beforeController): catches users who already have a live session
 * (no login event ever fired). Checks once per session via a session flag.
 * Skipped for OCS / DAV / remote.php requests.
 *
 * Phase 2 (afterController): intercepts any response when PostLoginListener
 * (or phase 1) has queued a redirect URL in RedirectState.
 */
class RedirectMiddleware extends Middleware {
	private const SESSION_KEY = 'files_sharding_redirect_checked';

	public function __construct(
		private RedirectState   $redirectState,
		private ShardingService $shardingService,
		private TokenService    $tokenService,
		private IUserSession    $userSession,
		private ISession        $session,
		private IRequest        $request,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		// PostLoginListener already queued a redirect for this request.
		if ($this->redirectState->peek() !== null) {
			return;
		}

		// Never redirect from our own login/logout flow — that would loop.
		if ($controller instanceof LoginController) {
			return;
		}

		// External-collaborator invite/accept pages (user_group_admin SignupController)
		// must run on the node the invite link points at — never bounce them to the
		// logged-in user's home silo (the master-held invite can't be handled there).
		if (str_ends_with(get_class($controller), 'SignupController')) {
			return;
		}

		// PUBLIC surfaces render on the node that holds the content, for everyone —
		// anonymous or logged-in. Bouncing a logged-in silo user who clicks a
		// public share link (/s/<token>) or an authless page to their home silo
		// hijacks a page that exists only HERE (observed: a catalog record on the
		// master SSO-bounced alice to her silo's Files app instead of showing the
		// share; on the shared-hostname test setup the hop also rotates cookies and
		// wrecks the silo session). Share pages, previews and other public
		// controllers identify themselves with #[PublicPage].
		if ($this->isPublicPage($controller, $methodName)) {
			return;
		}

		// Skip non-page requests — OCS APIs, DAV, and remote.php never need the redirect UI.
		$uri = $this->request->getRequestUri();
		if (
			str_starts_with($uri, '/ocs/')
			|| str_starts_with($uri, '/remote.php/')
			|| $this->request->getHeader('OCS-APIREQUEST') === 'true'
			// External-collaborator invite/accept pages must run on the node that holds
			// the invite (where the link points), not bounce to the user's home silo.
			|| str_contains($uri, '/apps/user_group_admin/signup')
		) {
			return;
		}

		if (!$this->shardingService->isMaster()) {
			// Silos authenticate users either via direct NC login (when the user has
			// a silo password) or via the master SSO flow.  We no longer redirect the
			// login page — the "Login via master server" link in login.js handles that
			// path.  Unauthenticated users just see NC's own login form.
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		// Never redirect a disabled account. A user disabled mid-session (e.g. an external
		// collaborator removed from their creating group) can still momentarily resolve
		// here before NC tears the session down; bouncing them to their home silo just
		// lands them somewhere they equally can't log in — limbo/loop. Let NC's own
		// disabled-account handling (log out + "account disabled") take over instead.
		if (!$user->isEnabled()) {
			return;
		}

		$userId  = $user->getUID();
		$version = $this->shardingService->getAssignmentVersion($userId);

		// Skip the DB check if the session already confirmed correct placement for
		// the current assignment_version.  Storing the version (not just true) means
		// a reassignment automatically invalidates the cached result.
		if ($this->session->get(self::SESSION_KEY) === $version) {
			return;
		}

		$url = $this->shardingService->getRedirectUrl($userId);

		if ($url === null) {
			// User is correctly on this server — cache the current version.
			$this->session->set(self::SESSION_KEY, $version);
			return;
		}

		// User needs to be on a different silo. Don't set the session flag so that
		// if this redirect fails the next request will try again.
		$token       = $this->tokenService->issue($userId);
		// /index.php/ form on purpose — see PostLoginListener: some silo web
		// servers drop the query string on the clean /apps/… URL.
		$redirectUrl = rtrim($url, '/') . '/index.php/apps/files_sharding/login'
			. '?token=' . urlencode($token)
			. '&user='  . urlencode($userId);

		$this->logger->warning("files_sharding: RedirectMiddleware: redirecting {$userId} → {$redirectUrl}");
		$this->redirectState->set($redirectUrl);
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		$url = $this->redirectState->consume();
		if ($url === null) {
			return $response;
		}
		// LoginController manages its own redirects (login exchange, sudo flow).
		// Applying the silo-assignment redirect here would break those flows.
		if ($controller instanceof LoginController) {
			return $response;
		}
		// External-collaborator invite/accept pages must render on the invite-holding
		// node — never redirect them to the user's home silo.
		if (str_ends_with(get_class($controller), 'SignupController')) {
			return $response;
		}
		return new RedirectResponse($url);
	}
	/**
	 * True when the target method (or controller class) is declared public —
	 * the #[PublicPage] attribute or the legacy @PublicPage annotation.
	 */
	private function isPublicPage(Controller $controller, string $methodName): bool {
		try {
			$ref = new \ReflectionMethod($controller, $methodName);
			if ($ref->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class) !== []) {
				return true;
			}
			$doc = $ref->getDocComment();
			if ($doc !== false && str_contains($doc, '@PublicPage')) {
				return true;
			}
			$cref = new \ReflectionClass($controller);
			if ($cref->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class) !== []) {
				return true;
			}
		} catch (\Throwable) {
		}
		return false;
	}

}
