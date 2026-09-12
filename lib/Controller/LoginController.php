<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCA\FilesSharding\Service\SsoCookie;
use OCA\FilesSharding\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Handles the silo side of the master→silo login redirect, and provides a
 * master-side logout endpoint used by the silo's "Back to login" link.
 */
class LoginController extends Controller {
	public function __construct(
		string                    $appName,
		IRequest                  $request,
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private TokenService      $tokenService,
		private IUserManager      $userManager,
		private IUserSession      $userSession,
		private ISession          $session,
		private IURLGenerator     $urlGenerator,
		private IConfig           $config,
		private ICrypto           $crypto,
		private LoggerInterface   $logger,
		private SsoCookie         $ssoCookie,
		private \OCA\FilesSharding\Service\LinkPolicy $linkPolicy,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Redirects the browser to the master's login page so the user can
	 * authenticate via SSO when they don't know their silo password.
	 * Preserves the deep-link so exchange() can land them on the right page.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function masterLogin(string $return = ''): RedirectResponse {
		$masterUrl = $this->shardingService->masterUrl();
		if ($masterUrl === '') {
			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm')
			);
		}
		// Point the master's post-login redirect_url at the master's dispatch
		// endpoint rather than the raw return path. redirect_url survives the
		// SAML/WAYF round-trip, so once the master has authenticated the user it
		// lands them on dispatch(), which issues a fresh token and bounces to the
		// home silo. This does NOT depend on RedirectState surviving the separate
		// SAML ACS request (it doesn't — it's request-scoped). The deep link rides
		// along as ?target= so the silo can still land the user on the right page.
		$dispatchPath = '/index.php/apps/files_sharding/dispatch';
		if ($return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
			$dispatchPath .= '?target=' . urlencode($return);
		}
		$loginUrl = rtrim($masterUrl, '/') . '/index.php/login'
			. '?redirect_url=' . urlencode($dispatchPath);
		return new RedirectResponse($loginUrl);
	}

	/**
	 * Master-side post-login dispatcher. The browser lands here once authenticated
	 * on the master — reached via redirect_url, which (unlike the request-scoped
	 * RedirectState) survives the SAML/WAYF round-trip. Issues a one-time token for
	 * the current user and bounces to their home silo's exchange endpoint; if the
	 * user's home is the master, redirects to the deep link or default page.
	 *
	 * Being a LoginController method, RedirectMiddleware leaves it untouched
	 * (both before/afterController early-return for this controller), so it can't
	 * be caught in the very redirect loop it exists to resolve.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function dispatch(string $target = ''): RedirectResponse {
		$safeTarget = ($target !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//'))
			? $target : '';

		if (!$this->userSession->isLoggedIn()) {
			// Not authenticated yet — send to the master login, threading this
			// dispatch back as redirect_url so we return here after login.
			$here = '/index.php/apps/files_sharding/dispatch'
				. ($safeTarget !== '' ? '?target=' . urlencode($safeTarget) : '');
			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm')
				. '?redirect_url=' . urlencode($here)
			);
		}
		if (!$this->shardingService->isMaster()) {
			// dispatch only makes sense on the master; elsewhere just go home.
			return new RedirectResponse($safeTarget !== '' ? $safeTarget : $this->urlGenerator->linkToDefaultPageUrl());
		}

		$userId = $this->userSession->getUser()->getUID();
		// Assign a silo if the user has none yet (mirrors PostLoginListener; needed
		// on the SAML path, where the login event may not have produced a redirect).
		if ($this->shardingService->getUserServer($userId) === null) {
			$this->shardingService->autoAssign($userId);
		}
		$silo = $this->shardingService->getRedirectUrl($userId);
		if ($silo === null) {
			// Home is the master.
			return new RedirectResponse($safeTarget !== '' ? $safeTarget : $this->urlGenerator->linkToDefaultPageUrl());
		}

		$token = $this->tokenService->issue($userId);
		// /index.php/ form on purpose — see PostLoginListener: some silo web
		// servers drop the query string on the clean /apps/… URL.
		$url   = rtrim($silo, '/') . '/index.php/apps/files_sharding/login'
			. '?token=' . urlencode($token)
			. '&user='  . urlencode($userId);
		if ($safeTarget !== '') {
			$url .= '&return=' . urlencode($safeTarget);
		}
		$this->logger->warning("files_sharding: dispatch → {$url}");
		return new RedirectResponse($url);
	}

	/**
	 * Ends the current (master) session and lands the user on the public front
	 * page — the target of the silo→master single sign-out handoff
	 * (LogoutRedirectMiddleware) and of the "Back to login" link on the silo error
	 * page. Clearing the master session here is what stops the master re-issuing a
	 * login token to a just-logged-out browser; the front page then offers login
	 * again for anyone who wants back in.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function logout(): RedirectResponse {
		if ($this->userSession->isLoggedIn()) {
			$this->userSession->logout();
		}
		$this->ssoCookie->clear();
		// Post-logout landing. '/' is fine on a plain install, but when a SAML
		// backend with multiple user backends is enabled, an anonymous '/' walks
		// into the backend-select page — so a deployment can point logouts at a
		// public welcome page instead via this system value.
		$landing = trim((string)$this->config->getSystemValue('files_sharding_logout_url', ''));
		return new RedirectResponse($landing !== '' ? $landing : $this->urlGenerator->getAbsoluteURL('/'));
	}

	/**
	 * Silo-side token exchange: validates a master-issued token and logs the
	 * user into this silo.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function exchange(string $token = '', string $user = '', string $return = ''): TemplateResponse|RedirectResponse {
		$masterLogoutUrl = rtrim($this->shardingService->masterUrl(), '/') . '/index.php/apps/files_sharding/logout';

		if ($token === '' || $user === '') {
			return new TemplateResponse('files_sharding', 'login_error', ['message' => 'Missing token or user', 'login_url' => $masterLogoutUrl], 'guest');
		}

		if ($this->shardingService->isMaster()) {
			// The master is a valid exchange target too (cluster SSO hop: a
			// silo-homed user landing on a master-hosted website — see ssoIssue).
			// Validate locally instead of HTTP-ing to ourselves.
			$uid  = $this->tokenService->consume($token);
			$u    = $uid !== null ? $this->userManager->get($uid) : null;
			$data = $u === null ? null : [
				'user_id'      => $u->getUID(),
				'display_name' => $u->getDisplayName(),
				'email'        => $u->getEMailAddress() ?? '',
				'quota'        => $u->getQuota(),
			];
		} else {
			$masterUrl = $this->shardingService->masterInternalUrl();
			if ($masterUrl === '') {
				$this->logger->error('files_sharding: master URL is not configured on this silo');
				return new TemplateResponse('files_sharding', 'login_error', ['message' => 'Silo not configured', 'login_url' => $masterLogoutUrl], 'guest');
			}
			$this->logger->warning("files_sharding: validating token for user={$user} via {$masterUrl}");
			$data = $this->client->postDirect($masterUrl, 'internal/token/validate', ['token' => $token]);
		}
		if ($data === null || empty($data['user_id'])) {
			$this->logger->warning("files_sharding: token validation failed for user={$user}, data=" . json_encode($data));
			return new TemplateResponse('files_sharding', 'login_error', ['message' => 'Invalid or expired login token', 'login_url' => $masterLogoutUrl], 'guest');
		}

		$userId = $data['user_id'];

		$localUser    = $this->userManager->get($userId);
		$sessionPassword = '';           // plaintext to place in session after login
		if ($localUser === null) {
			// New user: generate a password we know and store it encrypted.
			// SudoPasswordMiddleware injects it for the change-password form
			// until the user has set their own password.
			$sessionPassword = bin2hex(random_bytes(32));
			$localUser = $this->userManager->createUser($userId, $sessionPassword);
			$this->config->setUserValue(
				$userId, 'files_sharding', 'session_pw',
				$this->crypto->encrypt($sessionPassword)
			);
		} else {
			// Existing user: decrypt the stored initial password if the user
			// hasn't changed it yet.
			$encryptedPw = $this->config->getUserValue($userId, 'files_sharding', 'session_pw', '');
			if ($encryptedPw !== '') {
				try {
					$sessionPassword = $this->crypto->decrypt($encryptedPw);
				} catch (\Throwable $e) {
					// Also \ValueError: core's decrypt() retries a failed HMAC with an
					// EMPTY secret and hash_hkdf('') throws — happens for rows encrypted
					// under a previous 'secret' (e.g. after unifying the cluster secret).
					// Decryption failure (e.g. instance secret rotated): discard.
					$this->config->deleteUserValue($userId, 'files_sharding', 'session_pw');
				}
			}
		}
		if (!empty($data['display_name'])) {
			$localUser->setDisplayName($data['display_name']);
		}
		if (!empty($data['email'])) {
			$localUser->setEMailAddress($data['email']);
		}
		if (!empty($data['quota'])) {
			$localUser->setQuota($data['quota']);
		}

		// setUser() is in-memory only; completeLogin() + createSessionToken() persist the session.
		/** @var \OC\User\Session $userSession */
		$userSession = $this->userSession;
		$userSession->completeLogin($localUser, ['loginName' => $userId, 'password' => '']);
		$userSession->createSessionToken($this->request, $userId, $userId);

		// Mark password as confirmed so NC's PasswordConfirmationMiddleware
		// (regular mode, 30-min window) passes without requiring a password.
		$this->session->set('last-password-confirm', time());
		// Restore the session password for the change-password middleware (set
		// after login so session regeneration doesn't lose it).
		if ($sessionPassword !== '') {
			$this->session->set('fsh_session_password', $sessionPassword);
		} else {
			$this->session->remove('fsh_session_password');
		}
		// Clear any stale sudo token from a previous session.
		$this->session->remove('fsh_sudo_token');
		$this->session->remove('fsh_sudo_token_at');
		$this->session->remove('fsh_sudo_user');

		// Home node (the map says the user belongs here — on silos the map is
		// silent, which also means "here"): publish the cluster SSO marker. A hop
		// session on the master for a silo-homed user must NOT overwrite it.
		if ($this->shardingService->getRedirectUrl($userId) === null) {
			$this->ssoCookie->markHome();
		}

		// Redirect to the original deep link if one was threaded through from the master.
		// Only relative URLs starting with / are accepted to prevent open redirects.
		if ($return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
			return new RedirectResponse($return);
		}
		return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
	}

	// ── Cluster SSO hop (home node → master) ─────────────────────────────────

	/**
	 * Home node: the browser was sent here by a MASTER-hosted page that found no
	 * session but saw our cluster marker cookie (SsoCookie) pointing at this node.
	 * If the user is logged in here, obtain a one-time master token and bounce
	 * the browser to the master's exchange() so it gets a master session for the
	 * user's directory account, then lands back on $return. If there is no
	 * session here either (stale marker), just send the browser back — the page
	 * renders anonymously, exactly as before the hop; the caller guards against
	 * repeating the hop (short-lived cookie), so this can never loop.
	 *
	 * $target must be the master: the master holds a directory account for every
	 * cluster user, so exchange() there never has to create one. Silo-to-silo
	 * hops are deliberately not offered (they would create stray accounts).
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function ssoIssue(string $target = '', string $return = ''): RedirectResponse|TemplateResponse {
		$master = rtrim($this->shardingService->masterUrl(), '/');
		$target = rtrim($target, '/');
		if ($master === '' || $target === '' || !$this->shardingService->isClusterServer($target)) {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Unknown SSO target', 'login_url' => ''], 'guest');
		}
		$safeReturn = ($return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) ? $return : '/';
		$back = $target . $safeReturn;
		$targetIsMaster = $this->shardingService->authority($target) === $this->shardingService->authority($master);
		$targetIsHere   = $target === $this->ssoCookie->ownUrl();

		if (!$this->userSession->isLoggedIn()) {
			// Stale marker — not logged in here either. Anonymous it is.
			$this->ssoCookie->clear();
			return new RedirectResponse($back);
		}
		$userId = $this->userSession->getUser()->getUID();

		if ($targetIsHere) {
			// The target is this very node and there IS a session: nothing to hop
			// (a login with this endpoint as redirect_url landed here).
			return new RedirectResponse($back);
		}

		// One-time token for the user: the master issues them (locally when we
		// are the master, otherwise over the internal API).
		if ($this->shardingService->isMaster()) {
			$token = $this->tokenService->issue($userId);
		} else {
			$data  = $this->client->postDirect($this->shardingService->masterInternalUrl(), 'internal/token', ['userId' => $userId]);
			$token = (string)($data['token'] ?? '');
		}
		if ($token === '') {
			$this->logger->warning("files_sharding: ssoIssue: no token issued for {$userId}");
			return new RedirectResponse($back);
		}
		// Master target: a full session via exchange() — the master holds a
		// directory account for everyone, so nothing is created. Any other node:
		// a link-visitor identity only (ssoVisit) — never a stray account.
		$endpoint = $targetIsMaster ? 'login' : 'sso/visit';
		$url = $target . '/index.php/apps/files_sharding/' . $endpoint
			. '?token='  . urlencode($token)
			. '&user='   . urlencode($userId)
			. '&return=' . urlencode($safeReturn);
		return new RedirectResponse($url);
	}

	/**
	 * Target node of a hop for a "require login" public link (LinkPolicy): the
	 * visitor is logged in elsewhere in the cluster and arrives with a one-time
	 * master token. Validate it and remember who they are in THIS session — as
	 * a link visitor, not as a local account — then land on the link.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function ssoVisit(string $token = '', string $user = '', string $return = ''): RedirectResponse|TemplateResponse {
		$safeReturn = ($return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) ? $return : '/';
		if ($token === '' || $user === '') {
			return new RedirectResponse($safeReturn);
		}
		if ($this->shardingService->isMaster()) {
			$uid  = $this->tokenService->consume($token);
			$u    = $uid !== null ? $this->userManager->get($uid) : null;
			$data = $u === null ? null : ['user_id' => $u->getUID(), 'display_name' => $u->getDisplayName()];
		} else {
			$data = $this->client->postDirect($this->shardingService->masterInternalUrl(), 'internal/token/validate', ['token' => $token]);
		}
		if ($data === null || empty($data['user_id']) || $data['user_id'] !== $user) {
			$this->logger->warning("files_sharding: ssoVisit: token validation failed for user={$user}");
			return new RedirectResponse($safeReturn);
		}
		$this->linkPolicy->setVisitor((string)$data['user_id'], (string)($data['display_name'] ?? ''));
		return new RedirectResponse($safeReturn);
	}

	// ── Master-login sudo confirmation ────────────────────────────────────────

	/**
	 * Silo: starts a master-login confirmation round-trip.
	 * Stores the return URL in the session and redirects the browser to the
	 * master's sudoConfirm endpoint.
	 *
	 * returnTo is embedded in the callback URL (not only in the session) so it
	 * survives a session loss caused by the master/silo sharing the same cookie
	 * domain — the master may refresh its own session cookie during the round-trip,
	 * overwriting the silo's cookie.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function sudoInitiate(string $returnTo = ''): RedirectResponse {
		$masterUrl = rtrim($this->shardingService->masterUrl(), '/');
		if ($masterUrl === '') {
			return new RedirectResponse(
				($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//'))
					? $returnTo
					: $this->urlGenerator->linkToDefaultPageUrl()
			);
		}
		$safeReturn = ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//'))
			? $returnTo : '';
		if ($safeReturn !== '') {
			$this->session->set('fsh_sudo_return_to', $safeReturn);
		} else {
			$this->session->remove('fsh_sudo_return_to');
		}
		$callbackUrl = $this->urlGenerator->linkToRouteAbsolute('files_sharding.login.sudoCallback');
		if ($safeReturn !== '') {
			$callbackUrl .= '?returnTo=' . urlencode($safeReturn);
		}
		$siloBaseUrl = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		$confirmUrl  = $masterUrl . '/index.php/apps/files_sharding/sudo/confirm'
			. '?silo='     . urlencode($siloBaseUrl)
			. '&callback=' . urlencode($callbackUrl);
		return new RedirectResponse($confirmUrl);
	}

	/**
	 * Master: user is logged in here; issue a one-time token and redirect to
	 * the silo's sudoCallback.
	 *
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function sudoConfirm(string $silo = '', string $callback = ''): RedirectResponse|TemplateResponse {
		if (!$this->shardingService->isMaster()) {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'This endpoint is only available on the master', 'login_url' => ''], 'guest');
		}
		// Must be logged in on the master.
		if (!$this->userSession->isLoggedIn()) {
			$here = $this->urlGenerator->linkToRouteAbsolute('files_sharding.login.sudoConfirm')
				. '?silo=' . urlencode($silo) . '&callback=' . urlencode($callback);
			return new RedirectResponse(
				$this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm')
				. '?redirect_url=' . urlencode($here)
			);
		}
		if ($silo === '' || $callback === '') {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Missing silo or callback parameter', 'login_url' => ''], 'guest');
		}
		// Validate $silo against registered servers (open-redirect guard).
		$siloUrl = rtrim($silo, '/');
		$known   = false;
		foreach ($this->shardingService->getAllServers() as $s) {
			if (rtrim($s->getUrl(), '/') === $siloUrl) {
				$known = true;
				break;
			}
		}
		if (!$known) {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Unknown silo URL', 'login_url' => ''], 'guest');
		}
		// Callback must be on the registered silo (not an arbitrary redirect).
		if (!str_starts_with($callback, $siloUrl . '/') && $callback !== $siloUrl) {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Callback URL does not match silo', 'login_url' => ''], 'guest');
		}
		$userId = $this->userSession->getUser()->getUID();
		$token  = $this->tokenService->issue($userId);
		return new RedirectResponse(
			$callback . (str_contains($callback, '?') ? '&' : '?')
			. 'token=' . urlencode($token) . '&user=' . urlencode($userId)
		);
	}

	/**
	 * Silo: validates the master-issued token, refreshes last-password-confirm,
	 * stores a short-lived local sudo token for strict-mode calls, and redirects
	 * back to the original page.
	 *
	 * This is @PublicPage (no prior silo session required) because the master and
	 * silo share the same cookie domain: the master may overwrite the silo's
	 * session cookie during the round-trip, leaving the callback without a valid
	 * silo session.  We re-establish the session here using the master token as
	 * proof of identity, exactly as exchange() does.
	 *
	 * returnTo is read from the URL parameter first (set by sudoInitiate) and
	 * falls back to the session variable in case the URL param is absent.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function sudoCallback(string $token = '', string $user = '', string $returnTo = ''): RedirectResponse|TemplateResponse {
		$masterLogoutUrl = rtrim($this->shardingService->masterUrl(), '/') . '/index.php/apps/files_sharding/logout';

		// Prefer the URL-embedded returnTo (survives session loss); fall back to session.
		if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
			$returnTo = (string)$this->session->get('fsh_sudo_return_to') ?: $this->urlGenerator->linkToDefaultPageUrl();
		}

		if ($token === '' || $user === '') {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Missing sudo token', 'login_url' => $masterLogoutUrl], 'guest');
		}

		$masterUrl = $this->shardingService->masterInternalUrl();
		$data = $this->client->postDirect($masterUrl, 'internal/token/validate', ['token' => $token]);
		if ($data === null || ($data['user_id'] ?? '') !== $user) {
			return new TemplateResponse('files_sharding', 'login_error',
				['message' => 'Invalid or expired sudo token', 'login_url' => $masterLogoutUrl], 'guest');
		}
		$userId = $data['user_id'];

		// If the silo session was lost (cookie overwritten by master), re-establish it.
		if (!$this->userSession->isLoggedIn() || $this->userSession->getUser()->getUID() !== $userId) {
			$localUser = $this->userManager->get($userId);
			if ($localUser === null) {
				return new TemplateResponse('files_sharding', 'login_error',
					['message' => 'No local account; log in via master first', 'login_url' => $masterLogoutUrl], 'guest');
			}
			/** @var \OC\User\Session $userSession */
			$userSession = $this->userSession;
			$userSession->completeLogin($localUser, ['loginName' => $userId, 'password' => '']);
			$userSession->createSessionToken($this->request, $userId, $userId);
			// Restore session password so the change-password middleware works.
			$encryptedPw = $this->config->getUserValue($userId, 'files_sharding', 'session_pw', '');
			if ($encryptedPw !== '') {
				try {
					$this->session->set('fsh_session_password', $this->crypto->decrypt($encryptedPw));
				} catch (\Throwable) { // incl. \ValueError from core's empty-secret retry
					$this->config->deleteUserValue($userId, 'files_sharding', 'session_pw');
				}
			}
		}

		// Refresh the 30-min regular-mode password-confirmation window.
		$this->session->set('last-password-confirm', time());
		// Store a short-lived local sudo token for strict-mode confirmation.
		$sudoToken = bin2hex(random_bytes(24));
		$this->session->set('fsh_sudo_token', $sudoToken);
		$this->session->set('fsh_sudo_token_at', time());
		$this->session->set('fsh_sudo_user', $userId);
		$this->session->remove('fsh_sudo_return_to');

		return new RedirectResponse($returnTo);
	}
}
