<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Auth;

use OCA\FilesSharding\Db\ServerMapper;
use OCA\FilesSharding\Service\CertificateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserBackend;
use OCP\IUserManager;
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
 *    WebDAV access without a password. For a certificate WE issued, the serial
 *    must also be that of the certificate the account currently holds, so that
 *    deleting or regenerating it withdraws the old one — see
 *    certificateIsCurrent(). A DN the user registered for a certificate issued
 *    elsewhere is matched on the DN alone, as before.
 *
 * 3. Trusted daemon: A request whose presented certificate DN is one of the
 *    'trusted_dn_header_host_dns' (e.g. the batch service, "/CN=batch") may act
 *    on behalf of any user — the user is named in the 'dn_header' header
 *    (default SSL-CLIENT-DN), which only the trusted daemon/proxy may set.
 *
 * Apache/nginx must be configured to pass the verified certificate DN as
 * the SSL_CLIENT_S_DN header (or SSL_CLIENT_S_DN server variable).
 */
class X509Backend extends ABackend implements IUserBackend, IApacheBackend, ICheckPasswordBackend {
	private const SUDO_TTL = 300; // seconds

	/** Whether the DN we are acting on came from the verified TLS connection here. */
	private bool $dnFromEnv = false;

	public function __construct(
		private IRequest      $request,
		private IConfig       $config,
		private ServerMapper  $serverMapper,
		private ISession      $session,
		private IDBConnection $db,
		private IUserManager  $userManager,
		private CertificateService $certificates,
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
		// Only activate when the DN resolves to an actual identity — prevents a
		// proxy that forwards SSL headers for all connections from hijacking
		// password sessions.
		if ($this->isTrustedDaemon($dn)) {
			return $this->impersonatedUser() !== '';
		}
		return $this->serverMapper->findByDn($dn) !== null
			|| $this->findUserByDn($dn) !== '';
	}

	public function getCurrentUserId(): string {
		$dn = $this->getClientDn();
		if ($dn === '') {
			return '';
		}

		// Trusted daemon (e.g. the batch service, cert "/CN=batch"): act on
		// behalf of the user named in the Basic-auth header (empty password).
		// The verified daemon certificate is the authorisation; the username
		// only selects whom to impersonate. Must be an existing account.
		if ($this->isTrustedDaemon($dn)) {
			return $this->impersonatedUser();
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

	/**
	 * Is this request a trusted daemon (trusted_dn_header_host_dns certificate)
	 * acting on behalf of a user? Used by the conceal gate to let such daemons
	 * see the user's full view.
	 */
	public function isTrustedDaemonRequest(): bool {
		$dn = $this->getClientDn();
		return $dn !== '' && $this->isTrustedDaemon($dn) && $this->impersonatedUser() !== '';
	}

	private function getClientDn(): string {
		// The VERIFIED client-certificate subject DN.
		//
		// On direct Apache (the physical servers) it lands in the SSL_CLIENT_S_DN
		// environment variable — or REDIRECT_SSL_CLIENT_S_DN after an internal
		// rewrite such as /grid/ -> /remote.php/webdav/. This is the SAME source
		// the old ScienceData service used (chooser/lib/x509_auth.php), and it is
		// unforgeable: Apache only sets it when SSLVerifyClient actually validated
		// the certificate. We therefore trust the env var only when
		// SSL_CLIENT_VERIFY == SUCCESS.
		//
		// A front proxy that terminates the client-cert TLS itself (kube-Caddy on
		// the pods) instead forwards the DN as a request header — used as the
		// fallback. The proxy MUST overwrite any client-supplied header so it
		// cannot be forged.
		$srv = $this->request->server ?? $_SERVER;
		$verify = (string)($srv['SSL_CLIENT_VERIFY'] ?? $srv['REDIRECT_SSL_CLIENT_VERIFY'] ?? '');
		if ($verify === 'SUCCESS') {
			foreach (['SSL_CLIENT_S_DN', 'REDIRECT_SSL_CLIENT_S_DN'] as $k) {
				if (!empty($srv[$k])) {
					$this->dnFromEnv = true;
					return trim((string)$srv[$k]);
				}
			}
		}
		$this->dnFromEnv = false;
		return trim($this->request->getHeader('SSL-CLIENT-S-DN')
			?: $this->request->getHeader('X-Ssl-Client-S-Dn')
			?: '');
	}

	/**
	 * Serial of the certificate actually presented, from the same two sources as
	 * the DN: Apache's SSL_CLIENT_M_SERIAL where TLS was terminated here, or a
	 * header where a trusted proxy terminated it and chose to forward one.
	 */
	private function getClientSerial(): string {
		$srv = $this->request->server ?? $_SERVER;
		$verify = (string)($srv['SSL_CLIENT_VERIFY'] ?? $srv['REDIRECT_SSL_CLIENT_VERIFY'] ?? '');
		if ($verify === 'SUCCESS') {
			foreach (['SSL_CLIENT_M_SERIAL', 'REDIRECT_SSL_CLIENT_M_SERIAL'] as $k) {
				if (!empty($srv[$k])) {
					return CertificateService::normalizeSerial((string)$srv[$k]);
				}
			}
		}
		return CertificateService::normalizeSerial(
			$this->request->getHeader('SSL-CLIENT-M-SERIAL')
			?: $this->request->getHeader('X-Ssl-Client-M-Serial')
			?: ''
		);
	}

	/**
	 * Is this certificate still the one the user currently holds?
	 *
	 * The service issues certificates but publishes no revocation list, so the
	 * way a user withdraws one is to delete or regenerate it: the old copy must
	 * then stop opening doors. That only works if authentication looks at more
	 * than the subject DN, which is identical across every certificate we ever
	 * issue to the same person. So for OUR OWN certificates we also require the
	 * serial to be the serial of the certificate currently stored for that user
	 * — regenerating mints a new one, deleting leaves none. This is what the old
	 * service did (chooser/lib/x509_auth.php compared SSL_CLIENT_M_SERIAL with
	 * the serial read back from usercert.pem) and what the port had lost.
	 *
	 * Certificates issued ELSEWHERE, whose DN a user registered here by hand,
	 * are left alone: we hold no copy, so we have nothing to compare and no
	 * business refusing them.
	 *
	 * Where a trusted proxy terminated the TLS and forwarded no serial there is
	 * nothing to check either; that path is trusted by deployment configuration
	 * already. Where we verified the certificate ourselves, the serial is always
	 * exported next to the DN, so its absence is a reason to refuse.
	 */
	private function certificateIsCurrent(string $uid, string $dn): bool {
		if (self::tokenizeDn($dn) !== self::tokenizeDn($this->certificates->issuedDn($uid))) {
			return true; // not ours — nothing to pin it to
		}
		$presented = $this->getClientSerial();
		if ($presented === '') {
			if (!$this->dnFromEnv) {
				return true; // proxy path, no serial forwarded
			}
			$this->logger->warning('files_sharding: X.509: refusing ' . $uid
				. ' — our own certificate DN but no serial presented');
			return false;
		}
		$current = $this->certificates->currentSerial($uid);
		if ($current === '') {
			$this->logger->warning('files_sharding: X.509: refusing ' . $uid
				. ' — certificate ' . $presented . ' presented but the account holds none');
			return false;
		}
		if ($current === '0') {
			// Issued before certificates got a serial of their own. It still has to
			// match, but every copy matches, so this pins nothing until it is reissued.
			$this->logger->info('files_sharding: X.509: ' . $uid
				. ' holds a certificate with serial 0, from before unique serials; '
				. 'regenerating it would make withdrawal effective');
		}
		if ($current !== $presented) {
			$this->logger->warning('files_sharding: X.509: refusing ' . $uid
				. ' — certificate ' . $presented . ' is not the current one (' . $current . ')');
			return false;
		}
		return true;
	}

	/**
	 * Is the presented certificate DN one of the configured trusted daemons
	 * (trusted_dn_header_host_dns) that may act on behalf of other users?
	 * Matched on tokenised DNs so slash/comma format and attribute order don't
	 * matter.
	 */
	private function isTrustedDaemon(string $presentedDn): bool {
		$trusted = trim($this->config->getSystemValueString('trusted_dn_header_host_dns', ''));
		if ($trusted === '') {
			return false;
		}
		$presentedTok = self::tokenizeDn($presentedDn);
		if ($presentedTok === []) {
			return false;
		}
		// trusted_dn_header_host_dns is a comma-separated list of (slash-format)
		// host DNs; slash-format DNs contain no commas, so splitting is safe.
		foreach (explode(',', $trusted) as $hostDn) {
			if (self::tokenizeDn($hostDn) == $presentedTok) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The user a trusted daemon is acting on behalf of, carried in the header
	 * named by 'dn_header' (default SSL-CLIENT-DN) and set only by the trusted
	 * daemon/proxy. The value may be a bare username or a full subject DN, so
	 * resolve — in order — an exact username, the CN of a DN, or a DN a user
	 * has registered. Returns '' (no impersonation) unless it names a real,
	 * existing account.
	 */
	private function impersonatedUser(): string {
		$header = trim($this->config->getSystemValueString('dn_header', 'SSL-CLIENT-DN'));
		if ($header === '') {
			return '';
		}
		$value = trim($this->request->getHeader($header));
		if ($value === '') {
			return '';
		}
		if ($this->userManager->userExists($value)) {
			return $value;
		}
		$tok = self::tokenizeDn($value);
		if (isset($tok['CN']) && $this->userManager->userExists($tok['CN'])) {
			return $tok['CN'];
		}
		return $this->findUserByDn($value);
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
		if ($match !== '' && !$this->certificateIsCurrent($match, $dn)) {
			return '';
		}
		return $match;
	}
}
