<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Removes the storage bookkeeping a federated share leaves behind.
 *
 * When an external share is mounted, core materialises it as a row in the
 * table oc_storages (id = 'shared::' . md5(share_token . '@' . remote), see
 * OCA\Files_Sharing\External\Storage::getId()) plus its file metadata in
 * oc_filecache and a row in oc_mounts. NEITHER core's removeShare() NOR our
 * prune paths (ShareSyncService silo prune, ShareAuthorityReconcileJob master
 * prune) ever cleaned those up — every unshare left permanent orphans.
 *
 * purge() is called by our prune paths right after deleting rows from the
 * table oc_share_external. It only touches a storage when NO remaining
 * oc_share_external row still references the same share (a group share can
 * hold rows for several local users with the same token+remote).
 */
class ExternalStoragePurger {

	public function __construct(
		private IDBConnection   $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<array{token: string, remote: string}> $pairs share_token+remote
	 *        of just-deleted oc_share_external rows
	 * @return int number of storages purged
	 */
	public function purge(array $pairs): int {
		$purged = 0;
		foreach ($pairs as $pair) {
			$token  = trim((string)($pair['token'] ?? ''));
			$remote = trim((string)($pair['remote'] ?? ''));
			if ($token === '' || $remote === '') {
				continue;
			}
			try {
				if ($this->shareStillReferenced($token, $remote)) {
					continue;
				}
				// The storage id hashes the remote VERBATIM as stored at mount time;
				// tolerate the with/without-trailing-slash ambiguity by trying both.
				$candidates = [
					'shared::' . md5($token . '@' . rtrim($remote, '/')),
					'shared::' . md5($token . '@' . rtrim($remote, '/') . '/'),
				];
				$purged += $this->dropStorages($candidates);
			} catch (\Throwable $e) {
				$this->logger->warning('files_sharding: external-storage purge failed for remote ' . $remote . ': ' . $e->getMessage());
			}
		}
		if ($purged > 0) {
			$this->logger->info("files_sharding: purged {$purged} orphaned external-share storage(s)");
		}
		return $purged;
	}

	private function shareStillReferenced(string $token, string $remote): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('share_external')
			->where($qb->expr()->eq('share_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->in('remote', $qb->createNamedParameter(
				[rtrim($remote, '/'), rtrim($remote, '/') . '/'], IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** Delete oc_filecache + oc_mounts + oc_storages for the given storage ids. */
	private function dropStorages(array $storageIds): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')
			->from('storages')
			->where($qb->expr()->in('id', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$numericIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($numericIds === []) {
			return 0; // never mounted on this node — nothing to clean
		}

		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('filecache')
				->where($del->expr()->in('storage', $del->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->runAcrossAllShards()
				->executeStatement();

			$del = $this->db->getQueryBuilder();
			$del->delete('mounts')
				->where($del->expr()->in('storage_id', $del->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();

			$del = $this->db->getQueryBuilder();
			$del->delete('storages')
				->where($del->expr()->in('numeric_id', $del->createNamedParameter($numericIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return count($numericIds);
	}
}
