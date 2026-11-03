<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where the users of a migration list (users.json from the export) will live,
 * decided by the same rule as a first login (ShardingService::chooseServer: user
 * regex, then the default node, then free space) - an existing assignment is
 * kept. Assigns nothing; prints "uid <TAB> node URL <TAB> why", which the export
 * turns into the copy lists, so data is copied to where the account will be.
 * Run on the master.
 */
class MigratePlacement extends Command {
	public function __construct(
		private ShardingService $shardingService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:migrate:placement')
			->setDescription('Print where the users of a migration list will live (assigns nothing)')
			->addArgument('file', InputArgument::REQUIRED, 'users.json from the export');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->shardingService->isMaster()) {
			$output->writeln('<error>Run this on the master.</error>');
			return 1;
		}
		$list = json_decode((string)file_get_contents((string)$input->getArgument('file')), true);
		if (!is_array($list)) {
			$output->writeln('<error>Cannot read the list.</error>');
			return 1;
		}
		foreach ($list as $entry) {
			$userId = (string)($entry['uid'] ?? '');
			if ($userId === '') {
				continue;
			}
			$server = $this->shardingService->getUserServer($userId);
			$why = 'assigned';
			if ($server === null) {
				$server = $this->shardingService->chooseServer($userId, $byRegex);
				$why = $byRegex ? 'regex' : 'no regex (default node, or most free space)';
			}
			$output->writeln($userId . "\t" . ($server === null ? '' : rtrim($server->getUrl(), '/')) . "\t" . $why);
		}
		return 0;
	}
}
