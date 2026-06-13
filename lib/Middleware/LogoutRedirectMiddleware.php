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
 * On logout we expire every cookie the browser presented — these are exactly
 * this user's session/passphrase/remember-me cookies for the shared domain
 * (each node's session cookie is named after its instanceid). Cookies are
 * not port-scoped, so on a shared hostname this reaches every node; with
 * per-node hostnames on a shared domain it needs 'cookie_domain' in
 * config.php (see README). Then we land the user on the master login page.
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
			return $response; // already on the master — core's redirect to its own login is fine
		}
		$master = $this->shardingService->masterUrl();
		if ($master === '') {
			return $response;
		}
		return new RedirectResponse($master . '/index.php/login');
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
