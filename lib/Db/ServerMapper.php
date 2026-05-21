<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Server> */
class ServerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'files_sharding_servers', Server::class);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): Server {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Server[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('site')->addOrderBy('url');
		return $this->findEntities($qb);
	}

	/** @return Server[] */
	public function findBySite(string $site): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
			->orderBy('url');
		return $this->findEntities($qb);
	}

	/** Finds a server whose x509_dn matches. */
	public function findByDn(string $dn): ?Server {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('x509_dn', $qb->createNamedParameter($dn)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function updateFree(int $id, int $freeGb): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('free_gb', $qb->createNamedParameter($freeGb, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
