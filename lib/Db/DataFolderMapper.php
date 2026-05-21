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
}
