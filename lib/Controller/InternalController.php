<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\GroupShareRegistry;
use OCA\FilesSharding\Service\ShareSyncService;
use OCA\FilesSharding\Service\ShardingService;
use OCA\FilesSharding\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Plain (non-OCS) controller for inter-server calls that arrive without a
 * Nextcloud user session. Access is gated by the shared secret in config.php.
 * Uses plain Controller so #[PublicPage] works correctly (OCSController does
 * not support unauthenticated requests in NC33).
 */
class InternalController extends Controller {
	public function __construct(
		string                                    $appName,
		IRequest                                  $request,
		private ShardingService                   $shardingService,
		private TokenService                      $tokenService,
		private IUserManager                      $userManager,
		private IConfig                           $config,
		private IDBConnection                     $db,
		private ICloudFederationFactory           $cloudFederationFactory,
		private ICloudFederationProviderManager   $cloudFederationProviderManager,
		private ShareSyncService                  $shareSyncService,
		private GroupShareRegistry                $groupShareRegistry,
	) {
		parent::__construct($appName, $request);
	}

	private function checkSecret(): ?JSONResponse {
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret === '' || $this->request->getHeader('Authorization') !== 'Bearer ' . $secret) {
			return new JSONResponse(['message' => 'Unauthorized'], 401);
		}
		return null;
	}

	/**
	 * Validate a one-time login token and return the user record.
	 * Called by a silo during the master→silo login redirect flow.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function validateToken(string $token): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master validates tokens'], 403);
		}
		$userId = $this->tokenService->consume($token);
		if ($userId === null) {
			return new JSONResponse(['message' => 'Invalid or expired token'], 401);
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new JSONResponse(['message' => 'User not found'], 404);
		}
		return new JSONResponse([
			'user_id'      => $user->getUID(),
			'display_name' => $user->getDisplayName(),
			'email'        => $user->getEMailAddress() ?? '',
			'quota'        => $user->getQuota(),
		]);
	}

	/**
	 * Issue a one-time login token for $userId.
	 * Called by silos that need to redirect a user to a different silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function issueToken(string $userId): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master issues tokens'], 403);
		}
		if (!$this->userManager->userExists($userId)) {
			return new JSONResponse(['message' => 'User not found'], 404);
		}
		return new JSONResponse(['token' => $this->tokenService->issue($userId)]);
	}

	/**
	 * Receive a free-space report from a silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function updateFree(int $id, int $freeGb): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		$ok = $this->shardingService->updateFree($id, $freeGb);
		return $ok
			? new JSONResponse(['success' => true])
			: new JSONResponse(['message' => 'Server not found'], 404);
	}

	/**
	 * Search local users whose UID or display name contains $q.
	 * Returns [{user_id, display_name, silo_url}] for users that have a silo assigned.
	 * Called by silos to populate the share-dialog search with cross-silo users.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function searchUsers(string $q = '', int $limit = 10): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master can search users'], 403);
		}
		if (strlen($q) < 2) {
			return new JSONResponse(['users' => []]);
		}

		$matches = $this->userManager->search($q, $limit);
		$users   = [];
		foreach ($matches as $user) {
			$server = $this->shardingService->getUserServer($user->getUID());
			if ($server === null) {
				continue;
			}
			$users[] = [
				'user_id'      => $user->getUID(),
				'display_name' => $user->getDisplayName(),
				'silo_url'     => $server->getUrl(),
			];
		}

		return new JSONResponse(['users' => $users]);
	}

	/**
	 * Returns all registered silo server URLs.
	 * Called by SyncSiloTrustJob on silos to build federation trust.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function listServers(): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		$servers = array_map(
			fn($s) => ['id' => $s->getId(), 'url' => $s->getUrl()],
			$this->shardingService->getAllServers(),
		);
		return new JSONResponse(['servers' => $servers]);
	}

	/**
	 * Export a user's received federated shares from the master's oc_share_external.
	 * Called by silos at login time to mirror pending shares locally so Alice can
	 * accept them without needing to log into the master.
	 * Returns fields sufficient to re-create a pending ExternalShare on the silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function exportExternalShares(string $userId): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master exports shares'], 403);
		}
		if (!$this->userManager->userExists($userId)) {
			return new JSONResponse(['message' => 'User not found'], 404);
		}

		// (1) Direct incoming shares: rows addressed to this user.
		$qb = $this->db->getQueryBuilder();
		$qb->select('remote', 'remote_id', 'share_token', 'name', 'owner', 'share_type', 'password')
		   ->from('share_external')
		   ->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$shares  = $result->fetchAllAssociative();
		$result->closeCursor();

		// (2) Group shares: expand the user's group memberships and include every
		// group share (dedicated registry table) for a group they belong to. Computed
		// on demand from the authoritative membership — the group share is ONE registry
		// row, never fanned out per member. Join/leave are reflected here on the next
		// resolve.
		foreach ($this->groupShareRegistry->resolveForGroups($this->shardingService->getUserGroupIds($userId)) as $row) {
			$shares[] = $row;
		}

		return new JSONResponse(['shares' => $shares]);
	}

	/**
	 * Register a group share in the master's authoritative registry. Called by a
	 * silo when one of its users shares a folder with a group (master-resident
	 * owners register locally without this round-trip).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerGroupShare(
		string $gid = '', string $owner = '', string $ownerUrl = '',
		string $token = '', string $name = '', string $remoteId = '', string $permissions = '0',
	): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master holds the group-share registry'], 403);
		}
		if ($gid === '' || $owner === '' || $ownerUrl === '' || $token === '' || $name === '') {
			return new JSONResponse(['message' => 'Missing required parameter'], 400);
		}
		$this->groupShareRegistry->registerLocal($gid, $owner, $ownerUrl, $token, $name, $remoteId, (int)$permissions);
		return new JSONResponse(['success' => true]);
	}

	/** Remove a group share from the master's registry (owner unshared it). */
	#[PublicPage]
	#[NoCSRFRequired]
	public function deregisterGroupShare(string $gid = '', string $owner = '', string $name = ''): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master holds the group-share registry'], 403);
		}
		if ($gid === '' || $owner === '' || $name === '') {
			return new JSONResponse(['message' => 'Missing required parameter'], 400);
		}
		$this->groupShareRegistry->deregisterLocal($gid, $owner, $name);
		return new JSONResponse(['success' => true]);
	}

	/**
	 * Proxy a SHARE_ACCEPTED OCM notification from a silo to the sharer's server.
	 *
	 * When Alice (alice@master-host) accepts a share on her silo, the silo cannot
	 * send the OCM notification directly: Bob's silo validates that the request
	 * originates from the master (the host in alice@master-host) and rejects it
	 * with 400 if it comes from Alice's silo instead.
	 * The silo calls this endpoint so the master — the correct origin — sends the
	 * SHARE_ACCEPTED notification on Alice's behalf.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function proxyShareAccept(string $remote, string $remoteId, string $sharedSecret): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Only the master proxies share acceptance'], 403);
		}
		if ($remote === '' || $remoteId === '' || $sharedSecret === '') {
			return new JSONResponse(['message' => 'Missing required parameter'], 400);
		}

		$notification = $this->cloudFederationFactory->getCloudFederationNotification();
		$notification->setMessage(
			'SHARE_ACCEPTED',
			'file',
			$remoteId,
			[
				'sharedSecret' => $sharedSecret,
				'message'      => 'Recipient accept the share',
			],
		);

		$result = $this->cloudFederationProviderManager->sendNotification($remote, $notification);
		if ($result === false) {
			return new JSONResponse(['success' => false, 'message' => 'OCM notification failed'], 502);
		}
		return new JSONResponse(['success' => true]);
	}

	/**
	 * Propagate a user property change from the master to this silo.
	 * Supported features: displayName, eMailAddress, enabled, quota.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function updateUser(string $userId, string $feature, string $value): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new JSONResponse(['message' => 'User not found'], 404);
		}
		match ($feature) {
			'displayName'  => $user->setDisplayName($value),
			'eMailAddress' => $user->setEMailAddress($value),
			'enabled'      => $user->setEnabled($value === 'true' || $value === '1'),
			'quota'        => $user->setQuota($value),
			default        => null,
		};
		return new JSONResponse(['success' => true]);
	}

	/**
	 * Receive a password hash propagated from a user's silo (see
	 * PasswordChangedListener) and store it locally so this node can password-verify
	 * the user — e.g. on the master when WAYF is down. Writes oc_users directly:
	 * setPassword() would re-hash to a different value, userManager->createUser()
	 * refuses a uid already claimed by the SAML backend, and a direct write doesn't
	 * re-dispatch PasswordUpdatedEvent (so no propagation loop). Creates the
	 * Database-backed row if the user only exists in the SAML backend here.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function setPasswordHash(string $userId): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		$hash = (string)$this->request->getParam('hash', '');
		if ($hash === '') {
			return new JSONResponse(['message' => 'hash required'], 400);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')->from('users')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
		$cursor = $qb->executeQuery();
		$exists = $cursor->fetch() !== false;
		$cursor->closeCursor();

		if ($exists) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('users')
				->set('password', $qb->createNamedParameter($hash))
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('users')->values([
				'uid'       => $qb->createNamedParameter($userId),
				'uid_lower' => $qb->createNamedParameter(mb_strtolower($userId)),
				'password'  => $qb->createNamedParameter($hash),
			]);
			$qb->executeStatement();
		}
		return new JSONResponse(['success' => true]);
	}

	/**
	 * Delete a user on this silo.
	 * Called by the master after the user has been deleted on the master side.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteUser(string $userId): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if ($this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Silos only'], 403);
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new JSONResponse(['success' => true, 'note' => 'user not found']);
		}
		$ok = $user->delete();
		return new JSONResponse(['success' => $ok]);
	}

	/**
	 * Push-trigger share sync for a silo user.
	 * Called by the master's OcmShareReceivedMiddleware immediately after an
	 * OCM share lands for a user assigned to this silo, so the pending share
	 * and its notification appear without requiring a logout/login cycle.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function syncShares(string $userId): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if ($this->shardingService->isMaster()) {
			return new JSONResponse(['message' => 'Silos only'], 403);
		}
		if (!$this->userManager->userExists($userId)) {
			return new JSONResponse(['message' => 'User not found'], 404);
		}
		$inserted = $this->shareSyncService->syncForUser($userId);
		return new JSONResponse(['success' => true, 'inserted' => $inserted]);
	}
}
