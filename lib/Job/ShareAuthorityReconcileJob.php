<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Job;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Master-side convergence backstop for cross-silo federated share mirrors.
 *
 * The master's oc_share_external rows are the authoritative set that every silo
 * pulls and mirrors (ShareSyncService). Those rows are normally corrected by OCM
 * unshare notifications from the owning silo — a fire-and-forget path. If one is
 * missed (owner silo briefly down, a race, rapid churn) the master row leaks; and
 * because silos faithfully mirror the master, the dead mount then persists across
 * the whole cluster with no backstop (and a dead mirror could even crash the
 * userless ScanFiles cron before the null-user guard).
 *
 * This job makes the master's authority PULL-VALIDATED rather than merely
 * ACCUMULATED: for every cluster-origin row it asks the owning silo — in ONE batch
 * call per silo, not a per-mirror PROPFIND — which of those share ids still exist,
 * and prunes the rest. Downstream silos then drop their mirrors on their next
 * ShareSyncService reconcile, so the whole chain converges (invariants I1–I3 in
 * docs/share-lifecycle.md). Rows from genuine EXTERNAL (non-cluster) remotes are
 * left to core's own on-access handling — we cannot cheaply batch-query a foreign
 * server, and must never prune them here.
 *
 * Safety: a missing/garbled reply from an owner (transient outage) prunes NOTHING
 * for that owner — we only ever remove rows the owner explicitly reports absent.
 */
class ShareAuthorityReconcileJob extends TimedJob {
	public function __construct(
		ITimeFactory              $time,
		private IDBConnection     $db,
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private LoggerInterface   $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900); // every 15 min — eventual, cheap (one call per owner silo)
	}

	protected function run(mixed $argument): void {
		if (!$this->shardingService->isMaster()) {
			return; // the authoritative rows live on the master
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'remote', 'remote_id')->from('share_external');
		$cur = $qb->executeQuery();
		$rows = $cur->fetchAllAssociative();
		$cur->closeCursor();

		// remote(sans trailing slash) => [ remote_id(string) => [local row ids] ]
		$byRemote = [];
		foreach ($rows as $r) {
			$remote = rtrim((string)$r['remote'], '/');
			$rid    = (string)$r['remote_id'];
			if ($remote === '' || $rid === '') {
				continue;
			}
			$byRemote[$remote][$rid][] = (int)$r['id'];
		}

		$servers = $this->shardingService->getAllServers();
		$prunedTotal = 0;

		foreach ($byRemote as $remote => $idsMap) {
			if (!$this->shardingService->isClusterServer($remote)) {
				continue; // genuine external remote — not ours to validate, never prune
			}
			$server = null;
			foreach ($servers as $s) {
				if ($this->shardingService->sameNode($s->getUrl(), $remote)) {
					$server = $s;
					break;
				}
			}
			if ($server === null) {
				continue; // owner not in the registry (yet) — leave it alone
			}

			$remoteIds = array_map('strval', array_keys($idsMap));
			$res = $this->client->postDirect(
				$this->shardingService->apiUrlForServer($server),
				'internal/shares/live-ids',
				['ids' => $remoteIds],
			);
			if (!is_array($res) || !isset($res['live']) || !is_array($res['live'])) {
				// Transient outage / bad reply: prune NOTHING for this owner this run.
				$this->logger->warning('files_sharding: ShareAuthorityReconcileJob: no live-ids reply from ' . $remote . '; skipping (will retry)');
				continue;
			}

			$live = array_fill_keys(array_map('strval', $res['live']), true);
			$deadRowIds = [];
			foreach ($idsMap as $rid => $rowIds) {
				if (!isset($live[(string)$rid])) {
					foreach ($rowIds as $rowId) {
						$deadRowIds[] = $rowId;
					}
				}
			}
			if ($deadRowIds === []) {
				continue;
			}

			$del = $this->db->getQueryBuilder();
			$del->delete('share_external')->where($del->expr()->in('id', $del->createParameter('ids')));
			$del->setParameter('ids', $deadRowIds, IQueryBuilder::PARAM_INT_ARRAY);
			$del->executeStatement();
			$prunedTotal += count($deadRowIds);
			$this->logger->info('files_sharding: ShareAuthorityReconcileJob: pruned ' . count($deadRowIds)
				. ' stale mirror(s) owned by ' . $remote);
		}

		if ($prunedTotal > 0) {
			$this->logger->info('files_sharding: ShareAuthorityReconcileJob: pruned ' . $prunedTotal . ' stale master mirror(s) total');
		}
	}
}
