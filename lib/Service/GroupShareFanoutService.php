<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Cross-silo GROUP sharing — delivery model A (per-member federated fan-out).
 *
 * When a folder is shared with a group, we mint one real federated (TYPE_REMOTE)
 * child share `owner→member@master` for every member whose home silo is NOT the
 * owner's node. Members co-resident on the owner's node keep getting core's native
 * local (usergroup) child, so we skip them. Each child rides the same path a user
 * federated share does: OCM to the master → mirrored + auto-accepted on the
 * member's silo → native mount. No public link, proper per-recipient token.
 *
 * RESIDENCY is only knowable on the master (silos have no user→server map), so the
 * "which members are remote" decision is resolved there (remoteMembersForOwner);
 * silos ask via internal/group-remote-members.
 *
 * RECONCILE is stateless and idempotent: it diffs the DESIRED children (current
 * remote members) against the EXISTING ones (this table) per group share, creating
 * missing and pruning stale — plus orphan children whose parent group share is
 * gone. So a membership change, a glitch-dropped push, or a replayed event all
 * converge to the correct set with no stray shares.
 */
class GroupShareFanoutService {
	public function __construct(
		private IDBConnection   $db,
		private IShareManager   $shareManager,
		private IGroupManager   $groupManager,
		private ShardingService $shardingService,
		private InterServerClient $client,
		private IConfig         $config,
		private LoggerInterface $logger,
	) {
	}

	// ── Master-side residency resolution ──────────────────────────────────────

	/**
	 * Accepted members of $gid whose home silo is NOT $ownerUrl (i.e. the ones the
	 * owner node must reach by a federated child). MASTER ONLY — uses the user→server
	 * map. A member with no assignment is treated as master-resident.
	 *
	 * @return string[] member uids
	 */
	public function remoteMembersForOwner(string $gid, string $ownerUrl): array {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return [];
		}
		$masterUrl = $this->shardingService->masterUrl();
		$out = [];
		foreach ($group->getUsers() as $user) {
			$uid     = $user->getUID();
			$server  = $this->shardingService->getUserServer($uid);
			$homeUrl = $server !== null ? $server->getUrl() : $masterUrl;
			if (!$this->shardingService->sameNode($homeUrl, $ownerUrl)) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/**
	 * Remote members relative to THIS node, resolved via the master when we're a silo.
	 *
	 * @return string[] member uids
	 */
	private function remoteMembers(string $gid): array {
		$ownUrl = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if ($ownUrl === '') {
			return [];
		}
		if ($this->shardingService->isMaster()) {
			return $this->remoteMembersForOwner($gid, $ownUrl);
		}
		$res = $this->client->postDirect(
			$this->shardingService->masterInternalUrl(),
			'internal/group-remote-members',
			['gid' => $gid, 'ownerUrl' => $ownUrl],
		);
		return is_array($res['members'] ?? null) ? array_values($res['members']) : [];
	}

	// ── Reconcile ─────────────────────────────────────────────────────────────

	/**
	 * Reconcile every local group share for $gid on this node. Broadcast here by the
	 * master on membership change, and called locally on share create.
	 */
	public function reconcileGid(string $gid): void {
		$groupShareIds = $this->localGroupShareIds($gid);
		$remote = $this->remoteMembers($gid);

		foreach ($groupShareIds as $id) {
			try {
				$share = $this->shareManager->getShareById('ocinternal:' . $id);
				$this->reconcileShare($share, $gid, $remote);
			} catch (\Throwable $e) {
				$this->logger->warning("files_sharding: fanout: reconcile of group share {$id} ({$gid}) failed: " . $e->getMessage());
			}
		}

		// Orphan cleanup: children whose parent group share no longer exists here.
		$this->pruneOrphans($gid, $groupShareIds);
	}

	/**
	 * Reconcile one group share's federated children against the desired remote-member
	 * set. $remoteMembers is the uid list (already resolved for this node).
	 */
	public function reconcileShare(IShare $groupShare, string $gid, array $remoteMembers): void {
		$masterHost = $this->authorityless($this->shardingService->masterUrl());
		if ($masterHost === '') {
			return;
		}
		$groupShareId = (int)$groupShare->getId();
		$sharedBy = (string)$groupShare->getSharedBy();
		$node     = $groupShare->getNode();
		$perms    = $groupShare->getPermissions();

		$desired = [];
		foreach ($remoteMembers as $uid) {
			$desired[$uid . '@' . $masterHost] = true;
		}

		$existing = $this->existingChildren($groupShareId); // recipient => remote_share_id

		// Create missing children.
		foreach (array_keys($desired) as $recipient) {
			if (isset($existing[$recipient])) {
				continue;
			}
			try {
				$new = $this->shareManager->newShare();
				$new->setNode($node)
					->setShareType(IShare::TYPE_REMOTE)
					->setSharedWith($recipient)
					->setSharedBy($sharedBy)
					->setPermissions($perms);
				$created = $this->shareManager->createShare($new);
				$this->recordChild($gid, $groupShareId, (int)$node->getId(), $recipient, (int)$created->getId(), $sharedBy);
			} catch (\Throwable $e) {
				// e.g. the owner already shares this node with the member individually —
				// they already have access; nothing to deliver, so skip quietly.
				$this->logger->info("files_sharding: fanout: skip child {$recipient} for group share {$groupShareId}: " . $e->getMessage());
			}
		}

		// Prune children no longer desired (member left / became co-resident).
		foreach ($existing as $recipient => $remoteShareId) {
			if (isset($desired[$recipient])) {
				continue;
			}
			$this->deleteChild($remoteShareId);
			$this->forgetChild($groupShareId, $recipient);
		}
	}

	/** Remove ALL federated children for a group share (owner unshared the folder). */
	public function pruneForGroupShare(int $groupShareId): void {
		foreach ($this->existingChildren($groupShareId) as $recipient => $remoteShareId) {
			$this->deleteChild($remoteShareId);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_sharding_group_fanout')
			->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Broadcast a reconcile of $gid to every node (self included). MASTER drives this. */
	public function broadcastReconcile(string $gid): void {
		// Always reconcile the master's own group shares directly — don't depend on the
		// master being present in its own server registry.
		$this->reconcileGid($gid);
		foreach ($this->shardingService->getAllServers() as $server) {
			if ($this->shardingService->isSelf($server)) {
				continue;
			}
			$this->client->postDirect(
				$this->shardingService->apiUrlForServer($server),
				'internal/group-share-reconcile',
				['gid' => $gid],
			);
		}
	}

	// ── JS suppression source ─────────────────────────────────────────────────

	/**
	 * The federated-child share ids owned by $userId — the ones the sidebar hides so a
	 * group share shows as a single "shared with <group>" row.
	 *
	 * @return int[]
	 */
	public function fanoutShareIdsForOwner(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('remote_share_id')
			->from('files_sharding_group_fanout')
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$ids = [];
		foreach ($res->fetchAllAssociative() as $r) {
			$ids[] = (int)$r['remote_share_id'];
		}
		$res->closeCursor();
		return $ids;
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/** @return int[] local TYPE_GROUP share ids for $gid */
	private function localGroupShareIds(string $gid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('share')
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($gid)));
		$res = $qb->executeQuery();
		$ids = [];
		foreach ($res->fetchAllAssociative() as $r) {
			$ids[] = (int)$r['id'];
		}
		$res->closeCursor();
		return $ids;
	}

	/** @return array<string,int> recipient => remote_share_id */
	private function existingChildren(int $groupShareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('recipient', 'remote_share_id')
			->from('files_sharding_group_fanout')
			->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		foreach ($res->fetchAllAssociative() as $r) {
			$out[(string)$r['recipient']] = (int)$r['remote_share_id'];
		}
		$res->closeCursor();
		return $out;
	}

	private function recordChild(string $gid, int $groupShareId, int $nodeId, string $recipient, int $remoteShareId, string $owner): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('files_sharding_group_fanout')
			->setValue('gid',             $qb->createNamedParameter($gid))
			->setValue('group_share_id',  $qb->createNamedParameter($groupShareId, IQueryBuilder::PARAM_INT))
			->setValue('node_id',         $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT))
			->setValue('recipient',       $qb->createNamedParameter($recipient))
			->setValue('remote_share_id', $qb->createNamedParameter($remoteShareId, IQueryBuilder::PARAM_INT))
			->setValue('owner',           $qb->createNamedParameter($owner));
		$qb->executeStatement();
	}

	private function forgetChild(int $groupShareId, string $recipient): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_sharding_group_fanout')
			->where($qb->expr()->eq('group_share_id', $qb->createNamedParameter($groupShareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)));
		$qb->executeStatement();
	}

	private function deleteChild(int $remoteShareId): void {
		try {
			$share = $this->shareManager->getShareById('ocFederatedSharing:' . $remoteShareId);
			$this->shareManager->deleteShare($share);
		} catch (\Throwable $e) {
			// already gone — fine, the tracking row is dropped by the caller
			$this->logger->info("files_sharding: fanout: child share {$remoteShareId} already absent: " . $e->getMessage());
		}
	}

	/** Drop children whose parent group share is no longer present on this node. */
	private function pruneOrphans(string $gid, array $liveGroupShareIds): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'group_share_id', 'remote_share_id')
			->from('files_sharding_group_fanout')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$res  = $qb->executeQuery();
		$rows = $res->fetchAllAssociative();
		$res->closeCursor();

		$live = array_flip(array_map('intval', $liveGroupShareIds));
		foreach ($rows as $r) {
			if (isset($live[(int)$r['group_share_id']])) {
				continue;
			}
			$this->deleteChild((int)$r['remote_share_id']);
			$del = $this->db->getQueryBuilder();
			$del->delete('files_sharding_group_fanout')
				->where($del->expr()->eq('id', $del->createNamedParameter((int)$r['id'], IQueryBuilder::PARAM_INT)));
			$del->executeStatement();
		}
	}

	/**
	 * host[:port] of the master URL — for building member@master recipient ids.
	 * Identical construction to ShareCreatedListener::convertToFederated (the proven
	 * user-federated path) so a group child and a user share address @master the same.
	 */
	private function authorityless(string $url): string {
		return (string)preg_replace('#^https?://#', '', rtrim($url, '/'));
	}
}
