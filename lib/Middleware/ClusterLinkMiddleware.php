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
use OCP\IUserSession;

/**
 * Nextcloud's "internal link" (/index.php/f/<id>, from the sharing sidebar)
 * names a file id that exists only on this node. Someone living on another
 * node has no session here, so core would send them to this node's login page,
 * where they cannot sign in. Send them to the master instead, which signs them
 * in and finds their own copy of the file (ClusterLinkService).
 *
 * Core's security check throws before any app middleware's beforeController(),
 * so this looks at the login redirect it produced, in afterController().
 * Registered globally so it sees the Files app's controller.
 */
class ClusterLinkMiddleware extends Middleware {
	public function __construct(
		private ShardingService $sharding,
		private IUserSession    $userSession,
		private IRequest        $request,
		private IConfig         $config,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($methodName !== 'showFile'
			|| get_class($controller) !== 'OCA\\Files\\Controller\\ViewController'
			|| $this->userSession->isLoggedIn()
			|| $this->sharding->masterUrl() === '') {
			return $response;
		}
		$fileId = (string)($this->request->getParam('fileid') ?? '');
		if (!preg_match('/^\d{1,18}$/', $fileId)) {
			return $response;
		}
		$node = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		return new RedirectResponse(rtrim($this->sharding->masterUrl(), '/')
			. '/index.php/apps/files_sharding/fid/' . $fileId . '?node=' . urlencode($node));
	}
}
