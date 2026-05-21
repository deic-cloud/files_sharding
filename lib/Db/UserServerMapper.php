<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<UserServer> */
class UserServerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'files_sharding_user_servers', UserServer::class);
	}

	/** @throws DoesNotExistException */
	public function findByUserId(string $userId): UserServer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return UserServer[] */
	public function findByServerId(int $serverId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('server_id', $qb->createNamedParameter($serverId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id');
		return $this->findEntities($qb);
	}

	public function upsert(string $userId, int $serverId, int $access): UserServer {
		try {
			$record = $this->findByUserId($userId);
			$record->setServerId($serverId);
			$record->setAccess($access);
			return $this->update($record);
		} catch (DoesNotExistException) {
			$record = new UserServer();
			$record->setUserId($userId);
			$record->setServerId($serverId);
			$record->setAccess($access);
			return $this->insert($record);
		}
	}

	/**
	 * Returns all assignments, optionally filtered by server.
	 * @return UserServer[]
	 */
	public function findAllAssignments(?int $serverId = null, int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('user_id');
		if ($serverId !== null) {
			$qb->where($qb->expr()->eq('server_id', $qb->createNamedParameter($serverId, IQueryBuilder::PARAM_INT)));
		}
		$qb->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function countAssignments(?int $serverId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		if ($serverId !== null) {
			$qb->where($qb->expr()->eq('server_id', $qb->createNamedParameter($serverId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
