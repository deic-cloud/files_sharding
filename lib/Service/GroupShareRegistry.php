<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Master-side authoritative registry of GROUP shares, stored as rows in
 * oc_share_external with `target_group` set (no new table — see files_sharding
 * README, "Sharing model"). One row per group share; the master resolver
 * (InternalController::exportExternalShares) expands it per member on demand.
 * Never fanned out into per-member rows.
 *
 * A group share carries no NC access token, so the owner's node mints a companion
 * link-share token for the folder and stores it here; a member's silo mounts the
 * folder through the owner's federated/public-webdav endpoint with that token,
 * exactly like a user federated share. The token lives only in these server-side
 * rows and the silo caches — never exposed to a user's browser.
 */
class GroupShareRegistry {
	public function __construct(
		private IDBConnection   $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Insert (or replace) the authoritative registry row for a group share.
	 * Idempotent on (target_group, owner, name): re-registering replaces.
	 */
	public function registerLocal(
		string $gid,
		string $owner,
		string $ownerUrl,
		string $token,
		string $name,
		string $remoteId,
		int    $permissions,
	): void {
		$this->deregisterLocal($gid, $owner, $name);

		// JS-safe id (microseconds since epoch) — same rationale as ShareSyncService.
		$id = (int) floor(microtime(true) * 1000000);
		$remote = rtrim($ownerUrl, '/') . '/';

		$qb = $this->db->getQueryBuilder();
		$qb->insert('share_external')
		   ->setValue('id',              $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
		   ->setValue('parent',          $qb->createNamedParameter('-1'))
		   ->setValue('share_type',      $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
		   ->setValue('remote',          $qb->createNamedParameter($remote))
		   ->setValue('remote_id',       $qb->createNamedParameter($remoteId))
		   ->setValue('share_token',     $qb->createNamedParameter($token))
		   ->setValue('password',        $qb->createNamedParameter(''))
		   ->setValue('name',            $qb->createNamedParameter($name))
		   ->setValue('owner',           $qb->createNamedParameter($owner))
		   ->setValue('user',            $qb->createNamedParameter(''))
		   ->setValue('mountpoint',      $qb->createNamedParameter(''))
		   ->setValue('mountpoint_hash', $qb->createNamedParameter(''))
		   ->setValue('accepted',        $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
		   ->setValue('target_group',    $qb->createNamedParameter($gid));
		$qb->executeStatement();

		$this->logger->info("files_sharding: GroupShareRegistry: registered group share '{$name}' (group {$gid}, owner {$owner}) token-served from {$remote}");
	}

	/** Remove the registry row(s) for a group share. */
	public function deregisterLocal(string $gid, string $owner, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('share_external')
		   ->where($qb->expr()->eq('target_group', $qb->createNamedParameter($gid)))
		   ->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
		   ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$qb->executeStatement();
	}
}
