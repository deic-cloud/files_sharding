<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCA\FilesSharding\Service\ShardingService;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * MASTER: a WebDAV client pointed at the master for a user who lives on a silo
 * is sent there (307, same path — method and body are kept) instead of being
 * shown the master's own, empty directory copy of that user's home.
 *
 * Runs BEFORE authentication: device passwords exist only on the user's home
 * silo, so the master could not verify them and the client would only ever see
 * a 401. The decision needs just the username — from the path
 * (/remote.php/dav/files/<user>/…) or, for the user-relative endpoints
 * (/files/…, /remote.php/webdav/…), from the Basic-auth header — and the
 * master's user→silo map. No credentials are checked or passed on here; the
 * silo checks them. Requests for another user's files (a shared site's owner,
 * say) and anything without a username are left alone.
 */
class HomeRedirectPlugin extends ServerPlugin {
	private ?Server $server = null;

	public function __construct(
		private ShardingService $sharding,
		private IUserManager    $userManager,
		private IRequest        $ncRequest,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// Before the auth plugin (priority 10).
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 5);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response): ?bool {
		if (!$this->sharding->isMaster()) {
			return null;
		}
		$uid = $this->userOf($request);
		if ($uid === null) {
			return null;
		}
		$user = $this->userManager->get($uid);
		if ($user === null || strcasecmp($user->getUID(), $uid) !== 0) {
			return null;   // strict: not an e-mail match
		}
		$server = $this->sharding->getUserServer($user->getUID());
		if ($server === null || $this->sharding->isSelf($server)) {
			return null;   // lives here
		}
		$location = rtrim($server->getUrl(), '/') . $this->originalUri();
		$response->setStatus(307);
		$response->setHeader('Location', $location);
		$response->setHeader('Content-Length', '0');
		$this->logger->info('files_sharding: WebDAV for ' . $user->getUID() . ' on the master → ' . $location, ['app' => 'files_sharding']);
		// Returning false stops Sabre before the method AND before it sends the
		// response, so send it here.
		$this->server?->sapi->sendResponse($response);
		return false;
	}

	/**
	 * The URI the client asked for. Not IRequest's: legacydav.php (/files/…)
	 * boots with a script-prefixed REQUEST_URI and restores the original only
	 * afterwards, so IRequest still carries the rewritten one.
	 */
	private function originalUri(): string {
		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		return $uri !== '' ? $uri : ($this->ncRequest->getRequestUri() ?: '/');
	}

	/** The user whose home the request is about, or null. */
	private function userOf(RequestInterface $request): ?string {
		$path = ltrim($request->getPath(), '/');
		if (preg_match('#^files/([^/]+)(?:/|$)#', $path, $m)) {
			return rawurldecode($m[1]);   // /remote.php/dav/files/<user>/…
		}
		$uri = $this->originalUri();
		$userRelative = preg_match('#^/(?:index\.php/)?(?:files|grid)(?:/|$)#', parse_url($uri, PHP_URL_PATH) ?: '')
			|| str_starts_with($uri, '/remote.php/webdav');
		if (!$userRelative) {
			return null;
		}
		$auth = $request->getHeader('Authorization') ?? '';
		if (stripos($auth, 'basic ') !== 0) {
			return null;
		}
		$decoded = base64_decode(substr($auth, 6), true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return null;
		}
		$name = substr($decoded, 0, strpos($decoded, ':'));
		return $name !== '' ? $name : null;
	}
}
