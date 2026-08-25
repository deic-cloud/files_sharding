<?php

declare(strict_types=1);

/**
 * Anonymous public-share WebDAV: /remote.php/public/<token>/<path…>
 *
 * The old-service contract (sites/developer ManagingFiles): scripts GET/PUT
 * against HOME_URL/public/<token>/… with NO credentials for open shares, and
 * Basic credentials (any username, password = the share password) for
 * password-protected ones. The legacy group shape /public/<group>/<token>/… is
 * accepted too (the group segment is ignored; tokens are unique).
 *
 * NC's native /public.php/webdav needs the TOKEN as the Basic username, which
 * an anonymous request cannot express through any rewrite — hence this small
 * endpoint. Operations are gated by the share's permission mask (read-only,
 * file-drop, or writable shares all behave per their settings).
 *
 * Cross-silo: a token resolves only on the owner's node. The MASTER probes its
 * silos on a local miss and 302-redirects to the owning one — the documented
 * client contract has always been "follow redirects". On production the pretty
 * URL /public/… is an Apache rewrite onto this endpoint.
 */

use OCA\FilesSharding\DAV\PublicDirectory;
use OCA\FilesSharding\DAV\PublicFile;
use OCA\FilesSharding\Service\ShardingService;
use OCP\Files\Folder;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

$prefix  = '/remote.php/public/';
$uriPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
$subPath = str_starts_with($uriPath, $prefix) ? substr($uriPath, strlen($prefix)) : '';
$segs    = array_values(array_filter(explode('/', $subPath), static fn ($s) => $s !== ''));

if ($segs === []) {
	http_response_code(404);
	exit;
}

$shareManager = \OC::$server->get(IShareManager::class);

// Token = first segment; legacy /public/<group>/<token>/… → second segment.
$share = null;
$consumed = 1;
foreach ([1, 2] as $i) {
	if (!isset($segs[$i - 1])) {
		break;
	}
	try {
		$share = $shareManager->getShareByToken(urldecode($segs[$i - 1]));
		$consumed = $i;
		break;
	} catch (ShareNotFound) {
	}
}

if ($share === null) {
	// Not on this node. The master knows every registered silo — probe them and
	// redirect to the one that answers (anything but 404 = it has the token,
	// including 401 on a password-protected share).
	$sharding = \OC::$server->get(ShardingService::class);
	if ($sharding->isMaster()) {
		foreach ($sharding->getAllServers() as $server) {
			if ($sharding->isSelf($server)) {
				continue;
			}
			$target = rtrim($server->getUrl(), '/') . $uriPath;
			$ch = curl_init($target);
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
				CURLOPT_HTTPHEADER     => ['Depth: 0'],
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_TIMEOUT        => 5,
			]);
			curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($code !== 0 && $code !== 404) {
				$qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
				header('Location: ' . $target . $qs, true, 302);
				exit;
			}
		}
	}
	http_response_code(404);
	exit;
}

// Password-protected shares: Basic password (username is ignored).
if ($share->getPassword() !== null && $share->getPassword() !== '') {
	$pw = $_SERVER['PHP_AUTH_PW'] ?? '';
	if ($pw === '' || !$shareManager->checkPassword($share, $pw)) {
		header('WWW-Authenticate: Basic realm="Password-protected share"');
		http_response_code(401);
		exit;
	}
}

try {
	$node = $share->getNode();
} catch (\Throwable) {
	http_response_code(404);
	exit;
}

$mask     = (int)$share->getPermissions();
$baseSegs = array_slice($segs, 0, $consumed);
$baseUri  = $prefix . implode('/', $baseSegs) . '/';

if ($node instanceof Folder) {
	$root = PublicDirectory::wrap($node, implode('/', $baseSegs), $mask);
} else {
	// A single shared FILE is served as /public/<token>/<filename>.
	$root = new SimpleCollection(implode('/', $baseSegs), [
		new PublicFile($node, $node->getName(), $mask),
	]);
}

$server = new Server($root);
$server->setBaseUri($baseUri);
$server->addPlugin(new \Sabre\DAV\Locks\Plugin(
	new \Sabre\DAV\Locks\Backend\File(sys_get_temp_dir() . '/nc_fsh_dav_locks')
));
$server->exec();
