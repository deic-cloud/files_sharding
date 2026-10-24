<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\LinkLocator;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Public links resolve cluster-wide on the master.
 *
 * A link's token exists only on the node that holds the data, but links are
 * published with whatever host the owner saw — and the OLD service's links
 * (sciencedata.dk/shared/<token>, in papers for 10+ years) all name the master,
 * which the image rewrites to /index.php/s/<token>. When the master does not
 * hold the token, ask the registered silos for it (LinkLocator, internal API)
 * and redirect the browser there, path and query intact. Core's
 * PublicShareMiddleware has by then counted the unknown token as a failed guess
 * (brute-force protection, action <Controller>::<method>); for a token the
 * cluster does hold that attempt is undone, or every visitor of ~10 silo-held
 * links would be locked out (429). Unknown tokens still count. Same lookup in
 * appinfo/public.php for /remote.php/public/<token>.
 * Registered globally so it sees core's share controllers.
 */
class ShareLinkResolveMiddleware extends Middleware {
	private const CONTROLLERS = [
		'OCA\\Files_Sharing\\Controller\\ShareController',
		'OCA\\Files_Sharing\\Controller\\PublicPreviewController',
	];

	public function __construct(
		private ShardingService $shardingService,
		private LinkLocator     $links,
		private IRequest        $request,
		private IThrottler      $throttler,
	) {
	}

	/**
	 * Core's PublicShareMiddleware throws for an unknown token BEFORE any app
	 * middleware runs, and afterException() is only offered to middlewares whose
	 * beforeController() ran — so the hook that reliably sees the 404 is
	 * afterController(), which every middleware gets once a response exists.
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($response->getStatus() !== 404
			|| !in_array(get_class($controller), self::CONTROLLERS, true)
			|| !$this->shardingService->isMaster()) {
			return $response;
		}
		$token = (string)($this->request->getParam('token') ?? '');
		if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $token)) {
			return $response;
		}
		if ($this->links->isLocal($token)) {
			return $response; // ours after all (404 for another reason)
		}
		$home = $this->links->ownerNode($token);
		if ($home === null) {
			return $response; // nobody has it
		}
		$parts = explode('\\', get_class($controller));
		$this->throttler->resetDelay($this->request->getRemoteAddress(), end($parts) . '::' . $methodName, ['token' => $token]);
		return new RedirectResponse(rtrim($home, '/') . $this->request->getRequestUri());
	}
}
