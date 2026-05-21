<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Issues and validates short-lived one-time tokens for the master→silo login
 * redirect. Tokens are stored in the database so they are visible to all
 * PHP-FPM workers (APCu is per-process and unreliable in multi-worker setups).
 */
class TokenService {
	private const TTL    = 300; // seconds
	private const TABLE  = 'files_sharding_tokens';

	public function __construct(
		private IDBConnection $db,
		private ISecureRandom $random,
	) {
	}

	/** Issues a token for $userId and returns the token string. */
	public function issue(string $userId): string {
		$this->purgeExpired();

		$token = $this->random->generate(32);
		$qb    = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'token'      => $qb->createNamedParameter($token),
				'user_id'    => $qb->createNamedParameter($userId),
				'expires_at' => $qb->createNamedParameter(time() + self::TTL),
			])
			->executeStatement();

		return $token;
	}

	/**
	 * Validates $token and returns the associated userId, or null if the token
	 * is invalid or expired. Single-use — deletes the token on success.
	 */
	public function consume(string $token): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter(time())));

		$result = $qb->executeQuery();
		$userId = $result->fetchOne();
		$result->closeCursor();

		if ($userId === false) {
			return null;
		}

		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete(self::TABLE)
			->where($qb2->expr()->eq('token', $qb2->createNamedParameter($token)))
			->executeStatement();

		return (string)$userId;
	}

	private function purgeExpired(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter(time())))
			->executeStatement();
	}
}
