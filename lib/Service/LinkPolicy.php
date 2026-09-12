<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * "Require login" on public links.
 *
 * A public link flagged with the share attribute files_sharding:require_login
 * opens only for people who hold an account on this service, and every access
 * is logged with their username (see Application::boot, apache_note). Old-
 * service parity: the DTU administration relies on it for PhD-review
 * workflows.
 *
 * Who counts as identified on the node that serves the link:
 *  - a logged-in user (session or Basic auth), or
 *  - a link VISITOR: someone logged in on another cluster node who was passed
 *    through the SSO hop (LoginController::ssoIssue → ssoVisit) — recorded in
 *    the PHP session only, no local account is created for them.
 *
 * Enforced on every surface a link is served from: the share page, download
 * and preview controllers (RequireLoginMiddleware), the public DAV endpoint
 * the web UI uses (RequireLoginPlugin) and our /remote.php/public/<token>
 * (appinfo/public.php).
 */
class LinkPolicy {
	public const SCOPE       = 'files_sharding';
	public const KEY         = 'require_login';
	public const VISITOR_KEY = 'files_sharding_visitor';
	public const TRIED_COOKIE = 'files_sharding_sso_tried';

	public function __construct(
		private IUserSession    $userSession,
		private ISession        $session,
		private IRequest        $request,
		private IShareManager   $shareManager,
		private SsoCookie       $ssoCookie,
		private ShardingService $shardingService,
	) {
	}

	public function requiresLogin(IShare $share): bool {
		if ($share->getShareType() !== IShare::TYPE_LINK) {
			return false;
		}
		$attrs = $share->getAttributes();
		if ($attrs === null) {
			return false;
		}
		return (bool)$attrs->getAttribute(self::SCOPE, self::KEY);
	}

	public function setRequireLogin(IShare $share, bool $required): void {
		$attrs = $share->getAttributes() ?? $share->newAttributes();
		$attrs->setAttribute(self::SCOPE, self::KEY, $required);
		$share->setAttributes($attrs);
		$this->shareManager->updateShare($share);
	}

	/** The identified person behind this request: uid of the logged-in user, or the link visitor's uid, or ''. */
	public function identity(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			return $uid;
		}
		try {
			$visitor = $this->session->get(self::VISITOR_KEY);
		} catch (\Throwable) {
			return '';
		}
		return is_array($visitor) ? (string)($visitor['uid'] ?? '') : '';
	}

	public function setVisitor(string $uid, string $displayName = ''): void {
		$this->session->set(self::VISITOR_KEY, ['uid' => $uid, 'display_name' => $displayName, 'at' => time()]);
	}

	/**
	 * Where to send an unidentified visitor of a require-login link so they
	 * come back identified. $returnPath is the link's path on THIS node
	 * (e.g. /index.php/s/<token>).
	 *
	 * 1. The cluster marker cookie names another node the visitor is logged in
	 *    on → SSO hop: that node issues a token and sends the browser back here
	 *    as a link visitor (once per minute, so a stale marker cannot loop).
	 * 2. Otherwise → the master's login page, with the SSO issue endpoint as
	 *    redirect target: after login the user lands on their home node, whose
	 *    ssoIssue() passes them through to this node.
	 */
	public function redirectUrlFor(string $returnPath): string {
		$thisBase = $this->ssoCookie->ownUrl();
		$issue    = '/index.php/apps/files_sharding/sso/issue'
			. '?target=' . urlencode($thisBase)
			. '&return=' . urlencode($returnPath);

		$home = $this->ssoCookie->homeElsewhere();
		if ($home !== null && ($_COOKIE[self::TRIED_COOKIE] ?? '') === '') {
			setcookie(self::TRIED_COOKIE, '1', [
				'expires' => time() + 60, 'path' => '/',
				'secure' => $this->request->getServerProtocol() === 'https',
				'httponly' => true, 'samesite' => 'Lax',
			]);
			return $home . $issue;
		}

		$master = rtrim($this->shardingService->masterUrl(), '/');
		if ($master === '') {
			$master = $thisBase;
		}
		return $master . '/index.php/login?redirect_url=' . urlencode($issue);
	}
}
