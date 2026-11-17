<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCA\FilesSharding\Service\AccountPolicy;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * WebDAV half of the restricted-account rule (AccountPolicy): a restricted
 * account - admin, 'cloud', test accounts - is refused from addresses outside
 * 'adminips', also with a device password. Runs after authentication.
 */
class RestrictedAccountPlugin extends ServerPlugin {
	public function __construct(
		private AccountPolicy $policy,
		private IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		// authentication runs at priority 10
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 20);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		$ip = $this->request->getRemoteAddress();
		if ($this->policy->isAllowedFrom($user->getUID(), $ip)) {
			return;
		}
		$this->logger->warning("files_sharding: restricted account '{$user->getUID()}' refused over WebDAV from {$ip}");
		throw new Forbidden('This account may only be used from known addresses');
	}
}
