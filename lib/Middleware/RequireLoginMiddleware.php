<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\LinkPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;

/**
 * Enforces "Require login" (LinkPolicy) on the public-link controllers of
 * files_sharing: the share page, its download and preview routes, and the
 * share-info API. An unidentified visitor is sent through the SSO hop or to
 * the login page and comes back to the link. Registered as a global
 * middleware (true) so it sees core's controllers.
 */
class RequireLoginMiddleware extends Middleware {
	private const CONTROLLERS = [
		'OCA\\Files_Sharing\\Controller\\ShareController',
		'OCA\\Files_Sharing\\Controller\\PublicPreviewController',
		'OCA\\Files_Sharing\\Controller\\ShareInfoController',
	];

	public function __construct(
		private LinkPolicy    $policy,
		private IShareManager $shareManager,
		private IRequest      $request,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!in_array(get_class($controller), self::CONTROLLERS, true)) {
			return;
		}
		$token = (string)($this->request->getParam('token') ?? $this->request->getParam('t') ?? '');
		if ($token === '') {
			return;
		}
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound) {
			return; // not on this node / unknown — the controller reports it
		}
		if (!$this->policy->requiresLogin($share) || $this->policy->identity() !== '') {
			return;
		}
		throw new RequireLoginException($controller instanceof \OCP\AppFramework\OCSController
			|| str_ends_with(get_class($controller), 'ShareInfoController'));
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!($exception instanceof RequireLoginException)) {
			throw $exception;
		}
		if ($exception->isApi) {
			return new JSONResponse(['message' => 'This link requires login'], Http::STATUS_UNAUTHORIZED);
		}
		return new RedirectResponse($this->policy->redirectUrlFor($this->request->getRequestUri()));
	}
}

class RequireLoginException extends \Exception {
	public function __construct(public readonly bool $isApi) {
		parent::__construct('This link requires login');
	}
}
