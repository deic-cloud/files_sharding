<?php

declare(strict_types=1);

/**
 * DAV handler for:
 *   /remote.php/sharingin/   — files shared WITH the current user
 *   /remote.php/sharingout/  — files the current user has shared
 *
 * NC's main remote.php bootstraps the full stack before calling this file,
 * so the user session and DI container are already available.
 *
 * Sync clients authenticate via HTTP Basic (username + app-password) without
 * an existing cookie session.  We ask NC's auth manager to try credential-
 * based auth if the session user is not yet set.
 */

use OCA\FilesSharding\DAV\SharesRootCollection;
use OCP\Share\IManager as IShareManager;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

$userSession = \OC::$server->get(\OCP\IUserSession::class);

// Attempt credential-based auth when no cookie session is active.
// NC's IUserSession::tryAuthLogin() runs all registered auth backends
// (password, app-passwords, Bearer tokens, etc.) against the current request.
if ($userSession->getUser() === null) {
	$request = \OC::$server->get(\OCP\IRequest::class);
	// NC 28+ exposes tryBasicAuthLogin on the concrete session class.
	/** @var \OC\User\Session $concreteSession */
	$concreteSession = $userSession;
	if (method_exists($concreteSession, 'tryBasicAuthLogin')) {
		$concreteSession->tryBasicAuthLogin($request, \OC::$server->get(\OCP\Security\Bruteforce\IThrottler::class));
	}
}

$user = $userSession->getUser();
if ($user === null) {
	header('WWW-Authenticate: Basic realm="Nextcloud"');
	http_response_code(401);
	exit;
}
$userId = $user->getUID();

// Detect which endpoint was requested
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$incoming   = !str_contains($requestUri, 'sharingout');

$shareManager = \OC::$server->get(IShareManager::class);
$root = new SharesRootCollection($userId, $incoming, $shareManager);

// Wrap in a SimpleCollection so the server has a named root that matches the
// base URI path segment (/remote.php/sharingin/ or /remote.php/sharingout/).
$tree = new SimpleCollection('root', [$root]);

$server = new Server($tree);

// Set base URI: strip the part NC has already consumed
$baseUri = \OC::$server->getURLGenerator()->getAbsoluteURL(
	'/remote.php/' . ($incoming ? 'sharingin' : 'sharingout') . '/'
);
// Sabre wants just the path portion
$server->setBaseUri(parse_url($baseUri, PHP_URL_PATH));

// Lock plugin not needed for read-only, but required by some clients
$server->addPlugin(new \Sabre\DAV\Locks\Plugin(new \Sabre\DAV\Locks\Backend\File(
	sys_get_temp_dir() . '/nc_fsh_dav_locks'
)));

$server->addPlugin(new \Sabre\DAVACL\Plugin());

// Enable browser plugin only in debug mode
if (\OC::$server->getConfig()->getSystemValue('debug', false)) {
	$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
}

$server->exec();
