<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Cluster-wide facts about accounts, kept on the master:
 *
 *  - RESTRICTED ACCOUNTS: accounts that may only be used from the addresses in
 *    the system config 'adminips' (prefixes, e.g. "10.0., 192.38.111.185"):
 *    the admin account, the privileged 'cloud' account, test accounts. Listed
 *    explicitly (admin page on the master); membership of the admin group
 *    plays no part. Enforced on web/OCS (AdminIpMiddleware) and WebDAV
 *    (RestrictedAccountPlugin).
 *  - WAYF LOGIN: whether an account has ever logged in through SAML (WAYF) on
 *    the master - recorded at that login (SamlLoginListener), backfilled once
 *    from user_saml's accounts. Migrated accounts are ordinary database
 *    accounts until their first WAYF login, so the account backend alone
 *    cannot tell. Used e.g. by user_pods.
 *
 * Silos ask the master (internal endpoints) and cache the answers.
 */
class AccountPolicy {
	public const KEY_RESTRICTED = 'restricted_users';
	public const PREF_WAYF = 'wayf_login';
	private const CACHE_TTL = 300;

	private ?\OCP\ICache $cache = null;

	public function __construct(
		private ShardingService $sharding,
		private InterServerClient $client,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		if ($cacheFactory->isLocalCacheAvailable()) {
			$this->cache = $cacheFactory->createLocal('files_sharding_account_policy');
		}
	}

	// ── Restricted accounts ──────────────────────────────────────────────────

	/** @return string[] */
	public function restrictedUsers(): array {
		if ($this->sharding->isMaster()) {
			return $this->storedRestricted();
		}
		$cached = $this->cache?->get('restricted');
		if (is_array($cached)) {
			return $cached;
		}
		$data = $this->client->getDirect($this->sharding->masterInternalUrl(), 'internal/restricted-users');
		if (is_array($data) && isset($data['users']) && is_array($data['users'])) {
			$users = array_values(array_map('strval', $data['users']));
			// local copy: the last known list stays in force if the master is unreachable
			$this->appConfig->setValueString('files_sharding', self::KEY_RESTRICTED, json_encode($users));
		} else {
			$users = $this->storedRestricted();
		}
		$this->cache?->set('restricted', $users, self::CACHE_TTL);
		return $users;
	}

	/** Master only: replace the list. @param string[] $users */
	public function setRestrictedUsers(array $users): array {
		$users = array_values(array_unique(array_filter(array_map(static fn ($u) => trim((string)$u), $users), static fn ($u) => $u !== '')));
		sort($users);
		$this->appConfig->setValueString('files_sharding', self::KEY_RESTRICTED, json_encode($users));
		$this->cache?->remove('restricted');
		return $users;
	}

	public function isRestricted(string $uid): bool {
		return $uid !== '' && in_array($uid, $this->restrictedUsers(), true);
	}

	/** The address prefixes restricted accounts may use (system config 'adminips'). */
	public function allowedAddresses(): array {
		$v = trim((string)$this->config->getSystemValue('adminips', ''));
		return preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	}

	/**
	 * May $uid be used from $ip? Always for unrestricted accounts. For restricted
	 * ones: loopback, or an 'adminips' prefix; with 'adminips' empty the feature
	 * is off (an unconfigured instance never locks anyone out).
	 */
	public function isAllowedFrom(string $uid, string $ip): bool {
		if (!$this->isRestricted($uid)) {
			return true;
		}
		$prefixes = $this->allowedAddresses();
		if ($prefixes === [] || $ip === '127.0.0.1' || $ip === '::1') {
			return true;
		}
		foreach ($prefixes as $prefix) {
			if (str_starts_with($ip, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private function storedRestricted(): array {
		$v = json_decode($this->appConfig->getValueString('files_sharding', self::KEY_RESTRICTED, '[]'), true);
		return is_array($v) ? array_values(array_map('strval', $v)) : [];
	}

	// ── WAYF login ───────────────────────────────────────────────────────────

	/** Master: record that $uid has logged in through WAYF. */
	public function recordWayfLogin(string $uid): void {
		if ($this->config->getUserValue($uid, 'files_sharding', self::PREF_WAYF, '') === '') {
			$this->config->setUserValue($uid, 'files_sharding', self::PREF_WAYF, (string)time());
		}
	}

	/** Has $uid ever logged in through WAYF (asked on the master)? */
	public function hasWayfLogin(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		if ($this->sharding->isMaster()) {
			return $this->config->getUserValue($uid, 'files_sharding', self::PREF_WAYF, '') !== '';
		}
		$key = 'wayf_' . md5($uid);
		$cached = $this->cache?->get($key);
		if ($cached !== null) {
			return (bool)$cached;
		}
		$data = $this->client->getDirect($this->sharding->masterInternalUrl(), 'internal/users/' . rawurlencode($uid) . '/wayf');
		if (!is_array($data) || !array_key_exists('wayf', $data)) {
			return false; // master unreachable: not cached, asked again next time
		}
		$wayf = (bool)$data['wayf'];
		// a yes does not change; a no may (first WAYF login), so it is kept briefly
		$this->cache?->set($key, $wayf ? 1 : 0, $wayf ? 86400 : 60);
		return $wayf;
	}

	/** Master, once: mark every account user_saml created. Returns how many were new. */
	public function backfillWayfLogins(): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$res = $qb->select('uid')->from('user_saml_users')->executeQuery();
			$n = 0;
			while ($uid = $res->fetchOne()) {
				if ($this->config->getUserValue((string)$uid, 'files_sharding', self::PREF_WAYF, '') === '') {
					$this->config->setUserValue((string)$uid, 'files_sharding', self::PREF_WAYF, 'backfill');
					$n++;
				}
			}
			$res->closeCursor();
			return $n;
		} catch (\Throwable $e) {
			$this->logger->info('files_sharding: no user_saml accounts to backfill: ' . $e->getMessage());
			return 0;
		}
	}
}
