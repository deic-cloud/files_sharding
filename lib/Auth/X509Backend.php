<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Auth;

use OCA\FilesSharding\Db\ServerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
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
		private IDBConnection $db,
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
		// The web server passes the verified client certificate subject DN:
		//   Apache: SSL_CLIENT_S_DN as header SSL-CLIENT-S-DN
		//   nginx:  $ssl_client_s_dn as header X-Ssl-Client-S-Dn
		$dn = trim($this->request->getHeader('SSL-CLIENT-S-DN')
			?: $this->request->getHeader('X-Ssl-Client-S-Dn')
			?: '');
		if ($dn === '') {
			return '';
		}
		// Trusted-daemon relay: a request may be made on behalf of a user by a
		// trusted host (e.g. a compute service fetching/delivering that user's
		// files). If the presented certificate DN is one of the configured
		// trusted relay hosts, the real user's DN is carried in the header named
		// by 'dn_header' — set only by the trusted front proxy — so use it
		// instead. Both the trusted-host match and the user lookup are done on
		// tokenised DNs so slash/comma format and attribute order don't matter.
		$relayed = $this->relayedDn($dn);
		return $relayed !== '' ? $relayed : $dn;
	}

	/**
	 * If $presentedDn is a trusted relay host and the dn_header carries a DN,
	 * return that relayed user DN; otherwise ''.
	 */
	private function relayedDn(string $presentedDn): string {
		$trusted  = trim($this->config->getSystemValueString('trusted_dn_header_host_dns', ''));
		$dnHeader = trim($this->config->getSystemValueString('dn_header', ''));
		if ($trusted === '' || $dnHeader === '') {
			return '';
		}
		$headerDn = trim($this->request->getHeader($dnHeader));
		if ($headerDn === '') {
			return '';
		}
		$presentedTok = self::tokenizeDn($presentedDn);
		// trusted_dn_header_host_dns is a comma-separated list of (slash-format)
		// host DNs; slash-format DNs contain no commas, so splitting is safe.
		foreach (explode(',', $trusted) as $hostDn) {
			if (self::tokenizeDn($hostDn) == $presentedTok) {
				return $headerDn;
			}
		}
		return '';
	}

	/**
	 * Normalise a DN into an [attr => value] map so DNs compare equal regardless
	 * of separator style (/CN=…/O=… vs CN=…,O=…) and attribute order.
	 * @return array<string,string>
	 */
	private static function tokenizeDn(string $dn): array {
		$dn = trim($dn);
		if ($dn === '') {
			return [];
		}
		$parts = $dn[0] === '/' ? explode('/', $dn) : explode(',', $dn);
		$out = [];
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part === '' || !str_contains($part, '=')) {
				continue;
			}
			[$k, $v] = explode('=', $part, 2);
			$out[trim($k)] = trim($v);
		}
		return $out;
	}

	/**
	 * Map a certificate subject DN to a user id. User DNs are stored in
	 * oc_preferences (app=files_sharding, key=x509_dn_0..9) by the personal
	 * X.509 settings. Compared tokenised, so the stored format need not match
	 * the format the web server/proxy presents. Returns '' if none — or if more
	 * than one user has registered the same DN (ambiguous → refuse).
	 */
	private function findUserByDn(string $dn): string {
		$target = self::tokenizeDn($dn);
		if ($target === []) {
			return '';
		}
		$keys = array_map(static fn ($i) => "x509_dn_{$i}", range(0, 9));
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('files_sharding')))
			->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$match = '';
		while ($row = $result->fetch()) {
			if (($row['configvalue'] ?? '') === '') {
				continue;
			}
			if (self::tokenizeDn((string)$row['configvalue']) == $target) {
				if ($match !== '' && $match !== $row['userid']) {
					$this->logger->error('files_sharding: X.509 DN registered by more than one user, refusing: ' . $dn);
					$result->closeCursor();
					return '';
				}
				$match = (string)$row['userid'];
			}
		}
		$result->closeCursor();
		return $match;
	}
}
