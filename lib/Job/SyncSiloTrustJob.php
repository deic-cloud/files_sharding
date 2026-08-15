<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Job;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs hourly on silos: fetches all registered silo URLs from the master and
 * adds each one to NC's federation trusted-server list. This ensures that
 * incoming federated shares from any silo are auto-accepted without the user
 * having to manually confirm.
 *
 * Runs on the master too (no-op: it already trusts all silos via addServer()).
 */
class SyncSiloTrustJob extends TimedJob {
	public function __construct(
		ITimeFactory             $time,
		private ShardingService  $shardingService,
		private InterServerClient $client,
		private LoggerInterface  $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly
	}

	protected function run(mixed $argument): void {
		if ($this->shardingService->isMaster()) {
			// Master trusts silos when they are registered via addServer().
			return;
		}

		$masterUrl = $this->shardingService->masterInternalUrl();
		if ($masterUrl === '') {
			$this->logger->warning('files_sharding: SyncSiloTrustJob: master URL not configured');
			return;
		}

		$data = $this->client->getDirect($masterUrl, 'internal/servers');
		if (!is_array($data) || !isset($data['servers'])) {
			$this->logger->warning('files_sharding: SyncSiloTrustJob: could not fetch server list from master');
			return;
		}

		foreach ($data['servers'] as $server) {
			$url = (string)($server['url'] ?? '');
			if ($url === '') {
				continue;
			}
			$this->shardingService->trustServer($url);
		}

		// The master is NOT in the silo registry, but master-routed federated
		// shares arrive from the master's URL — and NC only auto-accepts from a
		// server that is present in the trusted list (isTrustedServer is a plain
		// membership check, status-agnostic). Without this, silos never trust the
		// master and every master-routed share lands as a pending notification.
		$masterPublicUrl = $this->shardingService->masterUrl();
		if ($masterPublicUrl !== '') {
			$this->shardingService->trustServer($masterPublicUrl);
		}

		$this->logger->debug('files_sharding: SyncSiloTrustJob: synced ' . count($data['servers']) . ' silo(s) + master into federation trust');
	}
}
