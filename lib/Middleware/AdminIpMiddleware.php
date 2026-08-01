<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Restricts admin access to whitelisted source IPs — ports the old
 * FilesSharding\Lib::checkAdminIP(). If a member of the admin group makes a
 * request from an IP that doesn't match the 'adminips' system config
 * (comma-separated prefixes), they are logged out and bounced to the login page.
 * Regular users are never affected.
 *
 *  - Empty 'adminips' = feature OFF, so an unconfigured instance never locks its
 *    admins out.
 *  - Prefix match, matching the old behaviour ('130.226.' matches '130.226.1.2').
 *  - Loopback (127.0.0.1 / ::1) is always allowed as a safety net.
 *  - Uses IRequest::getRemoteAddress(), which returns the real client IP whether
 *    Apache serves directly (production) or a proxy is in front (with
 *    trusted_proxies configured) — never the raw REMOTE_ADDR of a proxy hop.
 *
 * Registered as a global middleware so it covers the whole app-framework surface
 * (UI, OCS), not just login.
 */
class AdminIpMiddleware extends Middleware {
	public function __construct(
		private IUserSession    $userSession,
		private IGroupManager   $groupManager,
		private IConfig         $config,
		private IRequest        $request,
		private IURLGenerator   $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		$adminips = trim((string)$this->config->getSystemValue('adminips', ''));
		if ($adminips === '') {
			return; // feature off
		}
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return; // no admin session in play
		}
		$ip = $this->request->getRemoteAddress();
		if ($this->isAllowed($ip, $adminips)) {
			return;
		}
		$this->logger->warning(
			"files_sharding: admin '{$user->getUID()}' blocked from non-whitelisted IP {$ip}"
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

	private function isAllowed(string $ip, string $adminips): bool {
		if ($ip === '127.0.0.1' || $ip === '::1') {
			return true;
		}
		// Split on commas AND whitespace, so the same ADMIN_IPS value works here and
		// in the phpMyAdmin .htaccess 'Require ip' line (which is space-separated).
		foreach (preg_split('/[\s,]+/', $adminips, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $prefix) {
			if (str_starts_with($ip, $prefix)) {
				return true;
			}
		}
		return false;
	}
}
