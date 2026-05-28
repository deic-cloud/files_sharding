<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<DataFolder> */
class DataFolderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'files_sharding_folders', DataFolder::class);
	}

	/** @return DataFolder[] */
	public function findByUserId(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('folder');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): DataFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function findByUserIdAndFolderAndLockedBy(string $userId, string $folder, string $lockedBy): ?DataFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('folder',   $qb->createNamedParameter($folder)))
			->andWhere($qb->expr()->eq('locked_by', $qb->createNamedParameter($lockedBy)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function upsertLockedRule(string $userId, string $folder, bool $hideFromClients, string $lockedBy): void {
		$existing = $this->findByUserIdAndFolderAndLockedBy($userId, $folder, $lockedBy);
		if ($existing !== null) {
			$existing->setHideFromClients($hideFromClients);
			$this->update($existing);
		} else {
			$rule = new DataFolder();
			$rule->setUserId($userId);
			$rule->setFolder($folder);
			$rule->setOnlyFrom('');
			$rule->setHideFromClients($hideFromClients);
			$rule->setLockedBy($lockedBy);
			$this->insert($rule);
		}
	}

	public function deleteLockedRule(string $userId, string $folder, string $lockedBy): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id',   $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('folder',   $qb->createNamedParameter($folder)))
			->andWhere($qb->expr()->eq('locked_by', $qb->createNamedParameter($lockedBy)));
		$qb->executeStatement();
	}
}
