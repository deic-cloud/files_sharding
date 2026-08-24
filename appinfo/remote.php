<?php

declare(strict_types=1);

/**
 * DAV handler for:
 *   /remote.php/sharingin/   — files shared WITH the current user
 *   /remote.php/sharingout/  — files the current user has shared
 *
 * Also acts as a minimal sync-client server root when the desktop client is
 * configured with server URL https://host/remote.php/sharingin.  The client
 * then accesses sub-paths such as status.php, ocs/*, index.php/*, and
 * remote.php/dav/files/{uid}/ — all handled here without any web-server
 * rewrite rules.
 *
 * NC's main remote.php bootstraps the full stack before calling this file,
 * so the user session and DI container are already available.
 */

use OCA\FilesSharding\DAV\SharesRootCollection;
use OCP\Share\IManager as IShareManager;
use Sabre\DAV\Server;

// ── Detect service and sub-path ──────────────────────────────────────────────

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$incoming   = !str_contains($requestUri, 'sharingout');
$service    = $incoming ? 'sharingin' : 'sharingout';
$prefix     = '/remote.php/' . $service . '/';

$uriPath = strtok($requestUri, '?') ?: '';   // strip query string for routing
$subPath = str_starts_with($uriPath, $prefix)
	? substr($uriPath, strlen($prefix))
	: '';

// ── Pass-through redirects for sync-client discovery endpoints ──────────────
// The desktop sync client (ownCloud/NC) probes status.php, OCS, and login
// endpoints relative to the configured server root.  We redirect those to the
// real NC paths so no web-server rewrite rules are needed.

if ($subPath === 'status.php') {
	header('Location: /status.php', true, 302);
	exit;
}

if (str_starts_with($subPath, 'ocs/') || $subPath === 'ocs') {
	$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
		? '?' . $_SERVER['QUERY_STRING'] : '';
	header('Location: /' . $subPath . $qs, true, 307);
	exit;
}

if (str_starts_with($subPath, 'index.php/') || $subPath === 'index.php') {
	$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
		? '?' . $_SERVER['QUERY_STRING'] : '';
	header('Location: /' . $subPath . $qs, true, 307);
	exit;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
// Sync clients authenticate via HTTP Basic (username + app-password) without
// an existing cookie session.  Try credential-based auth if no session is set.

$userSession = \OC::$server->get(\OCP\IUserSession::class);

if ($userSession->getUser() === null) {
	$request = \OC::$server->get(\OCP\IRequest::class);
	/** @var \OC\User\Session $concreteSession */
	$concreteSession = $userSession;
	if (method_exists($concreteSession, 'tryBasicAuthLogin')) {
		try {
			$concreteSession->tryBasicAuthLogin(
				$request,
				\OC::$server->get(\OCP\Security\Bruteforce\IThrottler::class)
			);
		} catch (\OC\User\LoginException $e) {
			// Wrong credentials — fall through to 401 below
		}
	}
}

$user = $userSession->getUser();
if ($user === null) {
	header('WWW-Authenticate: Basic realm="Nextcloud"');
	http_response_code(401);
	exit;
}
$userId = $user->getUID();

// ── Determine Sabre base URI ──────────────────────────────────────────────────
// Three access patterns:
//   1. Bare endpoint             /remote.php/sharingin/
//   2. Sync client (modern)      /remote.php/sharingin/remote.php/dav/files/{uid}/
//   3. Sync client (legacy)      /remote.php/sharingin/remote.php/webdav/

if (str_starts_with($subPath, 'remote.php/dav/files/')) {
	$davRest = substr($subPath, strlen('remote.php/dav/files/'));
	$uid     = explode('/', $davRest)[0];
	$davBaseUri = $prefix . 'remote.php/dav/files/' . $uid . '/';
} elseif (str_starts_with($subPath, 'remote.php/webdav/')) {
	$davBaseUri = $prefix . 'remote.php/webdav/';
} else {
	$davBaseUri = $prefix;
}

// ── Build and run Sabre DAV server ───────────────────────────────────────────

// Writes go through the user's filesystem (mounted federated shares proxy to
// the owner's silo with the share token) — set it up before building the tree.
\OC_Util::setupFS($userId);

$root = new SharesRootCollection(
	$userId,
	$incoming,
	\OC::$server->get(IShareManager::class),
	\OC::$server->get(\OCP\Files\IRootFolder::class),
	\OC::$server->get(\OCP\IDBConnection::class),
	\OC::$server->get(\OCA\FilesSharding\Service\ShardingService::class),
	\OC::$server->get(\OCA\FilesSharding\Service\GroupShareFanoutService::class),
);

$server = new Server($root);
$server->setBaseUri($davBaseUri);

$server->addPlugin(new \Sabre\DAV\Locks\Plugin(new \Sabre\DAV\Locks\Backend\File(
	sys_get_temp_dir() . '/nc_fsh_dav_locks'
)));

if (\OC::$server->get(\OCP\IConfig::class)->getSystemValue('debug', false)) {
	$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
}

$server->exec();
