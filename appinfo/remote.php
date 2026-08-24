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

// ── Proxied sync-client discovery endpoints ─────────────────────────────────
// The desktop sync client, configured with server URL …/remote.php/sharingin,
// probes status.php, OCS and login endpoints RELATIVE to that URL. Newer NC
// clients do NOT follow redirects across endpoints (sites/developer docs,
// Feb-2025 update), so we PROXY these against our own public URL instead —
// the model the old service used, proven to work with current Windows clients.
//
// Two responses are rewritten in flight:
//  * login/v2/poll: its "server" field is the bare server root; a client that
//    adopts it would silently sync the DEFAULT endpoint (own files, shares
//    concealed) instead of sharingin. Rewrite it to the sharingin base.
//  * capabilities: strip dav "chunking"/"bulkupload" so large uploads arrive
//    as plain PUTs into our tree rather than chunked to /remote.php/dav/
//    uploads/, which this endpoint does not serve.

/** Proxy $subPath against this node's own public URL and stream the reply. */
function fsh_selfproxy(string $subPath): never {
	$config  = \OC::$server->get(\OCP\IConfig::class);
	$base    = rtrim((string)$config->getSystemValue('overwrite.cli.url', ''), '/');
	$verify  = (bool)$config->getSystemValue('files_sharding_verify_ssl', true);
	$qs      = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
	$url     = $base . '/' . $subPath . $qs;
	$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

	$headers = [];
	foreach (['Authorization', 'Content-Type', 'Accept', 'OCS-APIREQUEST', 'User-Agent', 'Cookie', 'requesttoken'] as $h) {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
		if ($h === 'Content-Type') {
			$key = 'CONTENT_TYPE';
		}
		if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
			$headers[] = $h . ': ' . $_SERVER[$key];
		}
	}

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => $verify,
		CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
		CURLOPT_TIMEOUT        => 30,
	]);
	if (!in_array($method, ['GET', 'HEAD'], true)) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
	}
	$body  = curl_exec($ch);
	$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);

	if ($body === false) {
		http_response_code(502);
		exit;
	}

	// Rewrite login/v2 poll result: keep the client on the sharingin endpoint.
	if (str_starts_with($subPath, 'index.php/login/v2') && $code === 200 && $body !== '') {
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['server'])) {
			$data['server'] = $base . '/remote.php/' . (str_contains($_SERVER['REQUEST_URI'] ?? '', 'sharingout') ? 'sharingout' : 'sharingin');
			$body = json_encode($data);
		}
	}

	// Strip chunked-upload capabilities (see header comment).
	if (str_contains($subPath, 'cloud/capabilities') && $code === 200 && $body !== '') {
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['ocs']['data']['capabilities']['dav'])) {
			unset(
				$data['ocs']['data']['capabilities']['dav']['chunking'],
				$data['ocs']['data']['capabilities']['dav']['bulkupload'],
			);
			$body = json_encode($data);
		}
	}

	http_response_code($code);
	if ($ctype !== '') {
		header('Content-Type: ' . $ctype);
	}
	echo $body;
	exit;
}

if ($subPath === 'status.php'
	|| str_starts_with($subPath, 'ocs/') || $subPath === 'ocs'
	|| str_starts_with($subPath, 'index.php/') || $subPath === 'index.php') {
	fsh_selfproxy($subPath);
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
