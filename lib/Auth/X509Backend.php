<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Auth;

use OCA\FilesSharding\Db\ServerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserBackend;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use Psr\Log\LoggerInterface;

/**
 * Authentication backend that accepts X.509 client certificates.
 *
 * Two use-cases:
 *
 * 1. Inter-server (silo→master): A request carrying a client certificate
 *    whose Subject DN matches a registered server's x509_dn is authenticated
 *    as the synthetic user "_server_{serverId}". These accounts never appear
 *    in the user list; they exist only to provide an authenticated identity
 *    for X.509-based inter-server calls.
 *
 * 2. User pods / containers: A user's own client certificate (DN stored in
 *    oc_preferences by the personal settings page) authenticates them for
 *    WebDAV access without a password.
 *
 * Apache/nginx must be configured to pass the verified certificate DN as
 * the SSL_CLIENT_S_DN header (or SSL_CLIENT_S_DN server variable).
 */
class X509Backend extends ABackend implements IUserBackend, IApacheBackend, ICheckPasswordBackend {
	private const SUDO_TTL = 300; // seconds

	public function __construct(
		private IRequest      $request,
		private IConfig       $config,
		private ServerMapper  $serverMapper,
		private ISession      $session,
		private LoggerInterface $logger,
	) {
	}

	// ── ICheckPasswordBackend ─────────────────────────────────────────────────

	/**
	 * Accept a session-stored sudo token as the user's "password".
	 * Called by NC's PasswordConfirmationMiddleware strict mode via
	 * UserManager::checkPassword() — iterates ALL backends, so this fires
	 * even though silo users are DB-owned.
	 */
	public function checkPassword(string $loginName, string $password): string|false {
		$token = $this->session->get('fsh_sudo_token');
		$at    = (int)$this->session->get('fsh_sudo_token_at');
		if ($token === null || $token === '' || $password !== $token) {
			return false;
		}
		if ((time() - $at) > self::SUDO_TTL) {
			return false;
		}
		// Verify the token belongs to the user making the request.
		if ($this->session->get('fsh_sudo_user') !== $loginName) {
			return false;
		}
		return $loginName;
	}

	// ── IApacheBackend ────────────────────────────────────────────────────────

	public function isSessionActive(): bool {
		$dn = $this->getClientDn();
		if ($dn === '') {
			return false;
		}
		// Only activate when the DN is actually known — prevents a proxy that
		// forwards SSL headers for all connections from hijacking password sessions.
		return $this->serverMapper->findByDn($dn) !== null
			|| $this->findUserByDn($dn) !== '';
	}

	public function getCurrentUserId(): string {
		$dn = $this->getClientDn();
		if ($dn === '') {
			return '';
		}

		// Check if the DN matches a registered server
		$server = $this->serverMapper->findByDn($dn);
		if ($server !== null) {
			return '_server_' . $server->getId();
		}

		// Check if the DN matches a user's stored certificate subjects
		return $this->findUserByDn($dn);
	}

	public function getLogoutUrl(): string {
		return '';
	}

	public function getBackendName(): string {
		return 'X509';
	}

	// ── UserInterface stubs ───────────────────────────────────────────────────

	public function userExists($uid): bool {
		return str_starts_with((string)$uid, '_server_');
	}

	public function deleteUser($uid): bool {
		return false;
	}

	public function getUsers($search = '', $limit = null, $offset = null): array {
		return [];
	}

	public function getDisplayName($uid): string {
		return (string)$uid;
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		return [];
	}

	public function hasUserListings(): bool {
		return false;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getClientDn(): string {
		// Apache: SSL_CLIENT_S_DN passed as header SSL-CLIENT-S-DN
		// nginx:  $ssl_client_s_dn passed as header X-Ssl-Client-S-Dn
		$dn = $this->request->getHeader('SSL-CLIENT-S-DN')
			?: $this->request->getHeader('X-Ssl-Client-S-Dn')
			?: '';
		return trim($dn);
	}

	private function findUserByDn(string $dn): string {
		// User certificate DNs are stored in oc_preferences as
		// app=files_sharding, key=x509_dn_{index}, value=<dn>
		// We do a brute-force scan; results are small and rarely change.
		$rows = $this->config->getUsersForUserValue('files_sharding', 'x509_dn_0', $dn);
		if (!empty($rows)) {
			return $rows[0];
		}
		// Check indices 1..9
		for ($i = 1; $i < 10; $i++) {
			$rows = $this->config->getUsersForUserValue('files_sharding', "x509_dn_{$i}", $dn);
			if (!empty($rows)) {
				return $rows[0];
			}
		}
		return '';
	}
}
