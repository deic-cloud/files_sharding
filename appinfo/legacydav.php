<?php

declare(strict_types=1);

/**
 * Legacy pretty WebDAV endpoints — the user's OWN files under short URLs:
 *   HOME/files/…   (password or X.509 auth)
 *   HOME/grid/…    (X.509 auth — same tree; the name is historical)
 *
 * Why this exists: the mfsbsd rewrite sends /files/… here (→ /remote.php/sddav/…)
 * rather than to the stock /remote.php/webdav. If /files were rewritten straight
 * to /remote.php/webdav, Sabre would compute baseUri=/remote.php/webdav while the
 * REQUEST_URI stays /files/… and throw "Requested uri (/files/) is out of base
 * uri" (a LogicException → HTTP 500). The fix — used by the old ScienceData
 * service (owncloud files_sharding/appinfo/remote.php) — is to set Sabre's
 * baseUri to the PRETTY prefix (/files or /grid), read from the original
 * REQUEST_URI (preserved across the internal rewrite). Then REQUEST_URI is under
 * baseUri and Sabre is happy.
 *
 * Authentication is already done by NC's base bootstrap before this file runs
 * (password Basic auth, or the files_sharding X509Backend for /grid) — the user
 * session is set. We only serve the WebDAV tree, mirroring dav/appinfo/v1/webdav.php.
 */

use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\DAV\Connector\Sabre\BearerAuth;
use OCA\DAV\Connector\Sabre\ServerFactory;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Mount\IMountManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ISession;
use OCP\ITagManager;
use OCP\IUserSession;
use OCP\L10N\IFactory as IL10nFactory;
use OCP\SabrePluginEvent;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Server;
use Psr\Log\LoggerInterface;

// ── Self-bootstrap ────────────────────────────────────────────────────────────
// This endpoint is reached DIRECTLY by a mod_rewrite of the pretty /files and
// /grid paths (…RewriteRule ^files/(.*) /apps/files_sharding/appinfo/legacydav.php).
// Pointing the rewrite straight here — rather than at /remote.php/webdav — avoids
// touching core (remote.php derives the DAV service from REQUEST_URI and would
// send /files to the stock webdav whose baseUri then mismatches → 500). So the
// app stays app-store-installable: anyone can add these two rewrite lines to get
// pretty WebDAV URLs, with no core patch.
//
// We replicate what NC's remote.php does before including a DAV handler: bootstrap
// the stack and load the auth/filesystem/logging app groups (X.509 login runs in
// base.php's request handling; Basic auth is done by the Sabre Auth plugin below).
if (!defined('OC_VERSION')) {
	require_once __DIR__ . '/../../../lib/base.php';
	header("Content-Security-Policy: default-src 'none';");
	if (\OCP\Util::needUpgrade()) {
		http_response_code(503);
		exit('Service unavailable (upgrade in progress)');
	}
	\OC::$REQUESTEDAPP = 'files_sharding';
	$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
	$appManager->loadApps(['authentication']);
	$appManager->loadApps(['extended_authentication']);
	$appManager->loadApps(['filesystem', 'logging']);
	$appManager->loadApp('files_sharding');
}

// no php execution timeout for webdav
if (!str_contains(@ini_get('disable_functions'), 'set_time_limit')) {
	@set_time_limit(0);
}
ignore_user_abort(true);
while (ob_get_level()) {
	ob_end_clean();
}

// ── baseUri = the pretty prefix, from the preserved original request URI ──────
$reqPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
$prefix  = '/files';
foreach (['/grid', '/files'] as $p) {
	$full = \OC::$WEBROOT . $p;
	if ($reqPath === $full || str_starts_with($reqPath, $full . '/')) {
		$prefix = $p;
		break;
	}
}
$baseuri = \OC::$WEBROOT . $prefix . '/';

$dispatcher = Server::get(IEventDispatcher::class);

$serverFactory = new ServerFactory(
	Server::get(IConfig::class),
	Server::get(LoggerInterface::class),
	Server::get(IDBConnection::class),
	Server::get(IUserSession::class),
	Server::get(IMountManager::class),
	Server::get(ITagManager::class),
	Server::get(IRequest::class),
	Server::get(IPreview::class),
	$dispatcher,
	Server::get(IL10nFactory::class)->get('dav')
);

$authBackend = new Auth(
	Server::get(ISession::class),
	Server::get(IUserSession::class),
	Server::get(IRequest::class),
	Server::get(\OC\Authentication\TwoFactorAuth\Manager::class),
	Server::get(IThrottler::class),
	'principals/'
);
$authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
$bearerAuthPlugin = new BearerAuth(
	Server::get(IUserSession::class),
	Server::get(ISession::class),
	Server::get(IRequest::class),
	Server::get(IConfig::class),
);
$authPlugin->addBackend($bearerAuthPlugin);

$requestUri = Server::get(IRequest::class)->getRequestUri();

/** @var string $baseuri */
$server = $serverFactory->createServer(false, $baseuri, $requestUri, $authPlugin, function () {
	return Filesystem::getView();
});

$event = new SabrePluginEvent($server);
$dispatcher->dispatch('OCA\DAV\Connector\Sabre::addPlugin', $event);
$event = new SabrePluginAddEvent($server);
$dispatcher->dispatchTyped($event);

$server->start();
