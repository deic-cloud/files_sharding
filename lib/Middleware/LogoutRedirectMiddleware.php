<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Single sign-out across nodes.
 *
 * Logging out anywhere must end this browser's whole cross-node session set,
 * not just the local one: a user accumulates sessions on sibling silos and
 * the master through the SSO exchanges (federated-share browsing, picocms
 * cross-silo). Core only clears the local session; without this, the master
 * session survives and any hop back to it silently re-issues a login token.
 *
 * On logout we expire every cookie the browser presented — this user's
 * session/passphrase/remember-me cookies (each node's session cookie is named
 * after its instanceid). Cookies are not port-scoped, so on a shared hostname
 * this reaches every node; with per-node hostnames it only reaches siblings on
 * the shared domain if 'cookie_domain' is set (see README) — so we do NOT rely
 * on it for the master. Instead the silo hands off to the master's own logout
 * endpoint, which ends the master session server-side. Setting 'cookie_domain'
 * additionally clears any sibling-silo sessions in one shot.
 */
class LogoutRedirectMiddleware extends Middleware {
	public function __construct(
		private ShardingService $shardingService,
		private IConfig         $config,
		private IRequest        $request,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof \OC\Core\Controller\LoginController) || $methodName !== 'logout') {
			return $response;
		}

		$this->clearSessionCookies();

		if ($this->shardingService->isMaster()) {
			// Core redirects to its own login — fine on a plain install, but with
			// user_saml + multiple user backends the login route walks the
			// logged-out browser into the backend-select page. Land on the
			// configured public page instead (same value the master-side
			// files_sharding logout endpoint honours for silo hand-offs).
			$landing = trim($this->config->getSystemValueString('files_sharding_logout_url', ''));
			if ($landing !== '') {
				return new RedirectResponse($landing);
			}
			return $response;
		}
		$master = $this->shardingService->masterUrl();
		if ($master === '') {
			return $response;
		}
		// Hand off to the master's LOGOUT (not its login): the master session must
		// be ended SERVER-SIDE. The cookie-clearing above only reaches the master
		// when session cookies live on the shared domain (cookie_domain set); with
		// per-node hostnames (lab.sciencedata.dk vs siloN.sciencedata.dk) it does
		// NOT, so the master session would survive and the master would re-issue a
		// login token on arrival — a logout→login→exchange RE-LOGIN LOOP. The
		// master-side files_sharding logout (LoginController::logout) calls
		// userSession->logout() then lands on the master login, robust regardless
		// of cookie scoping. (files_sharding's own LoginController is not
		// \OC\Core\Controller\LoginController, so this middleware won't re-fire.)
		return new RedirectResponse($master . '/index.php/apps/files_sharding/logout');
	}

	/**
	 * Expire every cookie the browser sent, plus the well-known NC auth
	 * cookies, on both the bare webroot path and webroot + '/'. setcookie()
	 * writes headers directly, so this survives any later response rewrite.
	 */
	private function clearSessionCookies(): void {
		$domain = $this->config->getSystemValueString('cookie_domain', '');
		$secure = $this->request->getServerProtocol() === 'https';
		$paths  = array_unique([\OC::$WEBROOT ?: '/', (\OC::$WEBROOT ?: '') . '/']);

		$names = array_keys($_COOKIE);
		foreach (['nc_username', 'nc_token', 'nc_session_id', 'oc_sessionPassphrase'] as $known) {
			if (!in_array($known, $names, true)) {
				$names[] = $known;
			}
		}

		foreach ($names as $name) {
			// Keep the CSRF/same-site marker cookies — they carry no session.
			if (str_contains($name, 'sameSiteCookie')) {
				continue;
			}
			unset($_COOKIE[$name]);
			foreach ($paths as $path) {
				setcookie($name, '', [
					'expires'  => time() - 3600,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				]);
			}
		}
	}
}
