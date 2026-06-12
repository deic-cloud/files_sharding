<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Single sign-out: logging out of a silo must also end the master session
 * (otherwise "Log in via master server" silently re-authenticates) and land
 * the user on the master login page. Core hard-codes the post-logout
 * redirect to the local login form; this global middleware rewrites it on
 * silos to the master's files_sharding logout, which clears the master
 * session and shows its login form.
 */
class LogoutRedirectMiddleware extends Middleware {
	public function __construct(
		private ShardingService $shardingService,
		private IRequest        $request,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof \OC\Core\Controller\LoginController) || $methodName !== 'logout') {
			return $response;
		}
		if ($this->shardingService->isMaster()) {
			return $response;
		}
		$master = $this->shardingService->masterUrl();
		if ($master === '') {
			return $response;
		}
		$redirect = new RedirectResponse($master . '/index.php/apps/files_sharding/logout');
		// keep core's logout hygiene for this origin
		if ($this->request->getServerProtocol() === 'https') {
			$redirect->addHeader('Clear-Site-Data', '"cache", "storage"');
		}
		return $redirect;
	}
}
