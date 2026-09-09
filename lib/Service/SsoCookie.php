<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IConfig;
use OCP\IRequest;

/**
 * Cluster-wide "where am I logged in" marker.
 *
 * NC sessions are per node (host-scoped cookies, one instance id each), so a
 * node serving content cannot tell whether an anonymous visitor is logged in
 * elsewhere in the cluster. The old service copied sessions between nodes;
 * here the user's HOME node drops one cookie on the cluster's shared parent
 * domain naming itself. A node that finds no local session but sees the marker
 * pointing at another node can send the browser there for a one-time token
 * (LoginController::ssoIssue → target's exchange) instead of treating the
 * visitor as anonymous. It carries no identity — only a node URL — and a stale
 * marker degrades to "anonymous" (ssoIssue bounces straight back when the
 * home node has no session either).
 *
 * Domain: system value files_sharding_sso_cookie_domain, else derived from the
 * master URL host by dropping its first label (lab.example.org → .example.org).
 * A host without a shared parent (two labels) disables the marker.
 */
class SsoCookie {
	public const NAME = 'files_sharding_home';

	public function __construct(
		private IConfig         $config,
		private IRequest        $request,
		private ShardingService $shardingService,
	) {
	}

	/** Cookie domain, '' when the marker cannot span the cluster. */
	public function domain(): string {
		$configured = trim((string)$this->config->getSystemValue('files_sharding_sso_cookie_domain', ''));
		if ($configured !== '') {
			return $configured;
		}
		$host   = (string)parse_url($this->shardingService->masterUrl(), PHP_URL_HOST);
		$labels = array_values(array_filter(explode('.', $host)));
		if (count($labels) < 3 || filter_var($host, FILTER_VALIDATE_IP)) {
			return '';
		}
		array_shift($labels);
		return '.' . implode('.', $labels);
	}

	/** This node's public base URL (overwrite.cli.url), '' if unset. */
	public function ownUrl(): string {
		return rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
	}

	/** Called on the user's home node once a session exists there. */
	public function markHome(): void {
		$domain = $this->domain();
		$own    = $this->ownUrl();
		if ($domain === '' || $own === '') {
			return;
		}
		$maxAge = $this->config->getSystemValueInt('remember_login_cookie_lifetime', 60 * 60 * 24 * 15);
		setcookie(self::NAME, $own, [
			'expires'  => time() + $maxAge,
			'path'     => '/',
			'domain'   => $domain,
			'secure'   => $this->request->getServerProtocol() === 'https',
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		$_COOKIE[self::NAME] = $own;
	}

	public function clear(): void {
		$domain = $this->domain();
		unset($_COOKIE[self::NAME]);
		if ($domain === '') {
			return;
		}
		setcookie(self::NAME, '', [
			'expires'  => time() - 3600,
			'path'     => '/',
			'domain'   => $domain,
			'secure'   => $this->request->getServerProtocol() === 'https',
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}

	/**
	 * The home node the browser claims a session on, validated against the
	 * cluster registry; null when absent, unknown, or this very node.
	 */
	public function homeElsewhere(): ?string {
		$home = rtrim((string)($_COOKIE[self::NAME] ?? ''), '/');
		if ($home === '' || $home === $this->ownUrl()) {
			return null;
		}
		if (!$this->shardingService->isClusterServer($home)) {
			return null;
		}
		return $home;
	}
}
