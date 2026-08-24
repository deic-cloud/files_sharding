<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCA\FilesSharding\Service\ShardingService;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Blocks WebDAV access to folders whose only_from CIDR rules do not include
 * the client's IP address.  Applies to all HTTP methods so neither PROPFIND,
 * GET, PUT nor DELETE can reach a restricted folder from a disallowed network.
 *
 * Path format inside Sabre (after base-URI stripping):
 *   files/{userId}/{folder}[/{subpath}]
 *
 * Only the first path component inside the user home is checked against the
 * rules table.  Nested paths inherit the restriction from the top-level folder.
 */
class FolderFilterPlugin extends ServerPlugin {
	public function __construct(
		private ShardingService $shardingService,
		private IRequest        $ncRequest,
		private IUserSession    $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$server->on('beforeMethod:*', [$this, 'beforeMethod']);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response): void {
		$path = $this->stripLeadingSlash($request->getPath());

		// Modern endpoint: files/{userId}/{folder}[/...]
		if (preg_match('#^files/([^/]+)/([^/]+)#u', $path, $m)) {
			$userId = $m[1];
			$folder = '/' . $m[2];
		} elseif (preg_match('#^/remote\.php/webdav(/|$)#', $this->ncRequest->getRequestUri())
			&& preg_match('#^([^/]+)#u', $path, $m)) {
			// LEGACY endpoint (/remote.php/webdav): Sabre paths are RELATIVE to the
			// session user's home ("media/x.txt", no files/{uid} prefix), so the
			// pattern above never matched — only_from rules were silently
			// bypassable by pointing a client at the legacy URL. Resolve the user
			// from the session instead.
			$user = $this->userSession->getUser();
			if ($user === null) {
				return;
			}
			$userId = $user->getUID();
			$folder = '/' . $m[1];
		} else {
			return;
		}

		$clientIp  = $this->ncRequest->getRemoteAddress();
		$userAgent = $this->ncRequest->getHeader('User-Agent');

		if ($this->shardingService->isFolderVisibleFrom($userId, $folder, $clientIp, $userAgent)) {
			return;
		}

		$this->logger->info(
			"files_sharding: FolderFilterPlugin: blocking {$userId}{$folder} for {$clientIp}"
		);
		throw new NotFound('Folder not accessible from your network location');
	}

	private function stripLeadingSlash(string $path): string {
		return ltrim($path, '/');
	}
}
