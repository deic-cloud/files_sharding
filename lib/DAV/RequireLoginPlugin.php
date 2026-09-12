<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCA\FilesSharding\Service\LinkPolicy;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

/**
 * Enforces "Require login" (LinkPolicy) on core's public DAV endpoint
 * (/public.php/dav/files/<token>/…), which the share page uses for listings,
 * downloads and previews. Added via BeforeSabrePubliclyLoadedEvent.
 */
class RequireLoginPlugin extends ServerPlugin {
	public function __construct(
		private LinkPolicy    $policy,
		private IShareManager $shareManager,
	) {
	}

	public function initialize(Server $server): void {
		// After authentication (priority 10) — the anonymous public auth is
		// fine, we only need the share and the visitor's identity.
		$server->on('beforeMethod:*', [$this, 'check'], 20);
	}

	public function check(RequestInterface $request): void {
		// Path relative to the base URI: files/<token>[/…] (also "uploads/<token>/…").
		$segs = explode('/', trim($request->getPath(), '/'));
		$token = $segs[1] ?? '';
		if ($token === '') {
			return;
		}
		try {
			$share = $this->shareManager->getShareByToken(urldecode($token));
		} catch (ShareNotFound) {
			return;
		}
		if ($this->policy->requiresLogin($share) && $this->policy->identity() === '') {
			throw new NotAuthenticated('This link requires login');
		}
	}
}
