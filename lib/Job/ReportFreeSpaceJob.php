<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Job;

use OCA\FilesSharding\Service\InterServerClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Runs hourly on silos: reads local free disk space and reports it to the
 * master so it can make informed auto-assignment decisions.
 *
 * Requires config.php keys:
 *   files_sharding_server_id   — integer ID of this silo in the master DB
 *   files_sharding_master_internal_url (or files_sharding_master_url)
 */
class ReportFreeSpaceJob extends TimedJob {
	public function __construct(
		ITimeFactory                   $time,
		private IConfig                $config,
		private InterServerClient      $client,
		private LoggerInterface        $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly
	}

	protected function run(mixed $argument): void {
		// Only silos report; master has no server_id set.
		$serverId = (int)$this->config->getSystemValue('files_sharding_server_id', 0);
		if ($serverId <= 0) {
			return;
		}

		$masterUrl = rtrim((string)$this->config->getSystemValue('files_sharding_master_internal_url', ''), '/');
		if ($masterUrl === '') {
			$masterUrl = rtrim((string)$this->config->getSystemValue('files_sharding_master_url', ''), '/');
		}
		if ($masterUrl === '') {
			$this->logger->warning('files_sharding: ReportFreeSpaceJob: master URL not configured');
			return;
		}

		$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
		if ($dataDir === '' || !is_dir($dataDir)) {
			$this->logger->warning('files_sharding: ReportFreeSpaceJob: datadirectory not readable');
			return;
		}

		$freeBytes = disk_free_space($dataDir);
		if ($freeBytes === false) {
			$this->logger->warning('files_sharding: ReportFreeSpaceJob: disk_free_space() failed');
			return;
		}

		$freeGb = (int)($freeBytes / 1_073_741_824);

		$result = $this->client->post($masterUrl, "internal/servers/{$serverId}/free", ['free_gb' => $freeGb]);
		if ($result === null) {
			$this->logger->warning("files_sharding: ReportFreeSpaceJob: could not report free space to master (server_id={$serverId})");
		} else {
			$this->logger->debug("files_sharding: ReportFreeSpaceJob: reported {$freeGb} GB free for server_id={$serverId}");
		}
	}
}
