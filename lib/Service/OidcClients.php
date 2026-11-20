<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Master: login clients of the OpenID Connect provider (the third-party app
 * 'oidc', H2CK) for services that log people in with their ScienceData
 * accounts - e.g. a group's Overleaf pod. A client is confidential, has one
 * concrete redirect URI and may be restricted to one group: only its members
 * get a token (the provider's own group check, against the groups replicated
 * to the master). Callers: user_pods on any node, over the internal API.
 *
 * Uses the provider's entity classes directly (guarded): it offers no API for
 * other apps. Checked against oidc 2.6.x.
 */
class OidcClients {
	private const CLIENT = '\\OCA\\OIDCIdentityProvider\\Db\\Client';
	private const CLIENT_MAPPER = '\\OCA\\OIDCIdentityProvider\\Db\\ClientMapper';
	private const GROUP = '\\OCA\\OIDCIdentityProvider\\Db\\Group';
	private const GROUP_MAPPER = '\\OCA\\OIDCIdentityProvider\\Db\\GroupMapper';

	public function __construct(
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ShardingService $sharding,
		private LoggerInterface $logger,
	) {
	}

	public function available(): bool {
		return $this->appManager->isEnabledForAnyone('oidc')
			&& class_exists(self::CLIENT) && class_exists(self::CLIENT_MAPPER)
			&& class_exists(self::GROUP) && class_exists(self::GROUP_MAPPER);
	}

	/**
	 * The provider's addresses, as a client needs them (the discovery
	 * document's), at the master's PUBLIC address - the browser goes there,
	 * and it is the issuer in the provider's tokens. Not the address of the
	 * current request: callers reach the master on its internal address.
	 */
	public function endpoints(): array {
		$base = $this->sharding->masterUrl() ?: rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		return [
			'issuer' => $base,
			'authorization_url' => $base . '/index.php/apps/oidc/authorize',
			'token_url' => $base . '/index.php/apps/oidc/token',
			'userinfo_url' => $base . '/index.php/apps/oidc/userinfo',
			'logout_url' => $base . '/index.php/apps/oidc/logout',
		];
	}

	/**
	 * Create a client; returns client_id and client_secret plus the endpoints.
	 * $gids: only members of these groups may log in (a member of any of them).
	 * None = any account: user_pods uses that for a pod started just for its
	 * owner, where the application in the pod admits only the owner (the
	 * provider can only restrict to groups). @param string[] $gids
	 */
	public function create(string $name, string $redirectUri, array $gids): array {
		if (!$this->available()) {
			throw new \RuntimeException('The OpenID Connect provider (app oidc) is not enabled on the master');
		}
		if (!preg_match('#^https://[^/\s]+/[^\s]*$#', $redirectUri)) {
			throw new \InvalidArgumentException('Invalid redirect URI');
		}
		$clientClass = self::CLIENT;
		$client = new $clientClass($name, [$redirectUri], 'RS256', 'confidential', 'code', 'opaque', 'openid profile email groups', '');
		$client = Server::get(self::CLIENT_MAPPER)->insert($client);
		$gids = array_values(array_unique(array_filter(array_map('strval', $gids), static fn ($g) => $g !== '')));
		foreach ($gids as $gid) {
			$groupClass = self::GROUP;
			$group = new $groupClass();
			$group->setClientId($client->getId());
			$group->setGroupId($gid);
			Server::get(self::GROUP_MAPPER)->insert($group);
		}
		$this->logger->info("files_sharding: OIDC client '$name' created for " . ($gids === [] ? 'any account' : 'groups ' . implode(', ', $gids)), ['app' => 'files_sharding']);
		return [
			'client_id' => $client->getClientIdentifier(),
			'client_secret' => $client->getSecret(),
		] + $this->endpoints();
	}

	public function delete(string $clientId): bool {
		if (!$this->available() || $clientId === '') {
			return false;
		}
		try {
			$mapper = Server::get(self::CLIENT_MAPPER);
			$client = $mapper->getByIdentifier($clientId);
			if ($client === null) {
				return false;
			}
			Server::get(self::GROUP_MAPPER)->deleteByClientId($client->getId());
			return (bool)$mapper->deleteByIdentifier($clientId);
		} catch (\Throwable $e) {
			$this->logger->warning("files_sharding: OIDC client $clientId not deleted: " . $e->getMessage(), ['app' => 'files_sharding']);
			return false;
		}
	}
}
