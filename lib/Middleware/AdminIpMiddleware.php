<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\AccountPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Web/OCS half of the restricted-account rule (AccountPolicy): an account on
 * the restricted list - the admin account, 'cloud', test accounts - used from
 * an address outside the system config 'adminips' is logged out and bounced to
 * the front page. Ports the old FilesSharding\Lib::checkAdminIP(), which keyed
 * on the admin group; the list is explicit now (admin page on the master).
 * WebDAV is covered by RestrictedAccountPlugin.
 *
 *  - Empty 'adminips' = feature OFF, so an unconfigured instance never locks anyone out.
 *  - Prefix match ('130.226.' matches '130.226.1.2'); loopback always allowed.
 *  - IRequest::getRemoteAddress() gives the real client address, also behind a
 *    configured trusted proxy.
 *
 * Registered as a global middleware so it covers the whole app-framework surface.
 */
class AdminIpMiddleware extends Middleware {
	public function __construct(
		private IUserSession    $userSession,
		private AccountPolicy   $policy,
		private IRequest        $request,
		private IURLGenerator   $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$ip = $this->request->getRemoteAddress();
		if ($this->policy->isAllowedFrom($user->getUID(), $ip)) {
			return;
		}
		$this->logger->warning(
			"files_sharding: restricted account '{$user->getUID()}' blocked from non-whitelisted IP {$ip}"
		);
		$this->userSession->logout();
		throw new AdminIpBlockedException();
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof AdminIpBlockedException) {
			return new RedirectResponse($this->urlGenerator->getAbsoluteURL('/'));
		}
		throw $exception;
	}
}
