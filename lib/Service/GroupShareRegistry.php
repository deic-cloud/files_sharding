<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Master-side authoritative registry of GROUP shares, stored in the dedicated
 * files_sharding table `files_sharding_group_shares` (one row per group share).
 * The master resolver (InternalController::exportExternalShares) expands each row
 * per member on demand — never fanned out into per-member rows.
 *
 * WHY A DEDICATED TABLE (not a column on oc_share_external): core's
 * `ExternalShareMapper` does `SELECT *` from oc_share_external and hydrates a
 * strict `ExternalShare` Entity, so ANY extra column there throws
 * "… is not a valid attribute" and breaks core's OCM unshare handling (empty
 * value included). Our own table is never read by core, so it's safe.
 *
 * A group share carries no NC access token, so the owner's node mints a companion
 * link-share token for the folder and stores it here; a member's silo mounts the
 * folder through the owner's public-webdav endpoint with that token, exactly like
 * a user federated share. The token lives only in server-side rows (registry +
 * silo caches) — never exposed to a user's browser.
 */
class GroupShareRegistry {
	public function __construct(
		private IDBConnection   $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Insert (or replace) the authoritative registry row for a group share.
	 * Idempotent on (gid, owner, name): re-registering replaces.
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

		$qb = $this->db->getQueryBuilder();
		$qb->insert('files_sharding_group_shares')
		   ->setValue('gid',         $qb->createNamedParameter($gid))
		   ->setValue('owner',       $qb->createNamedParameter($owner))
		   ->setValue('owner_url',   $qb->createNamedParameter(rtrim($ownerUrl, '/') . '/'))
		   ->setValue('share_token', $qb->createNamedParameter($token))
		   ->setValue('name',        $qb->createNamedParameter($name))
		   ->setValue('remote_id',   $qb->createNamedParameter($remoteId))
		   ->setValue('permissions', $qb->createNamedParameter($permissions, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();

		$this->logger->info("files_sharding: GroupShareRegistry: registered group share '{$name}' (group {$gid}, owner {$owner}) served from {$ownerUrl}");
	}

	/** Remove the registry row(s) for a group share. */
	public function deregisterLocal(string $gid, string $owner, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_sharding_group_shares')
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
		   ->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
		   ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$qb->executeStatement();
	}

	/**
	 * Resolve group shares for the given group ids into export-share rows
	 * (same shape as oc_share_external export: remote/remote_id/share_token/
	 * name/owner/share_type/password), for InternalController::exportExternalShares.
	 *
	 * @param string[] $gids
	 * @return array<int, array<string, mixed>>
	 */
	public function resolveForGroups(array $gids): array {
		if (empty($gids)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('gid', 'owner', 'owner_url', 'share_token', 'name', 'remote_id', 'permissions')
		   ->from('files_sharding_group_shares')
		   ->where($qb->expr()->in('gid', $qb->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
		$res  = $qb->executeQuery();
		$rows = [];
		foreach ($res->fetchAllAssociative() as $r) {
			$rows[] = [
				'remote'      => (string)$r['owner_url'],
				'remote_id'   => (string)$r['remote_id'],
				'share_token' => (string)$r['share_token'],
				'name'        => (string)$r['name'],
				'owner'       => (string)$r['owner'],
				'share_type'  => 1,
				'password'    => '',
			];
		}
		$res->closeCursor();
		return $rows;
	}
}
