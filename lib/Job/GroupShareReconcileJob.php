<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Job;

use OCA\FilesSharding\Service\GroupShareFanoutService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Asynchronous per-member federated fan-out for a GROUP share.
 *
 * Creating a group share mints one real federated child (`owner→member@master`)
 * for every member whose home silo differs from the owner's — each an OCM
 * round-trip to the master (which in turn pushes to the member's silo). Done
 * inline in the ShareCreatedEvent that fires during the OCS shares POST, that
 * serial network work blocked the owner's share dialog for tens of seconds
 * (25s observed with a handful of cross-silo members).
 *
 * The LOCAL group-share row is created synchronously by core and is all the
 * owner's request needs; cross-silo delivery is eventual by nature (a member's
 * mount is auto-accepted/synced on their silo regardless of when the child is
 * minted). So we enqueue the reconcile here and let the owner's request return
 * immediately. reconcileGid() is stateless and idempotent, so a replayed or
 * coalesced job simply converges to the correct child set.
 */
class GroupShareReconcileJob extends QueuedJob {
	public function __construct(
		ITimeFactory                    $time,
		private GroupShareFanoutService $fanout,
		private LoggerInterface         $logger,
	) {
		parent::__construct($time);
	}

	protected function run(mixed $argument): void {
		$gid = (string)($argument['gid'] ?? '');
		if ($gid === '') {
			return;
		}
		try {
			$this->fanout->reconcileGid($gid);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: GroupShareReconcileJob: reconcile of ' . $gid . ' failed: ' . $e->getMessage());
		}
	}
}
