<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Db\UserServer;
use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListUsers extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:user:list')
			->setDescription('List user → silo assignments')
			->addOption('server', 's', InputOption::VALUE_REQUIRED, 'Filter by server ID')
			->addOption('limit',  'l', InputOption::VALUE_REQUIRED, 'Page size', '100')
			->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Page offset', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$serverId = $input->getOption('server') !== null ? (int)$input->getOption('server') : null;
		$limit    = (int)($input->getOption('limit')  ?? 100);
		$offset   = (int)($input->getOption('offset') ?? 0);

		$result = $this->shardingService->listAssignments($serverId, $limit, $offset);

		if (empty($result['assignments'])) {
			$output->writeln('<comment>No assignments found.</comment>');
			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['User ID', 'Server ID', 'Server URL', 'Site', 'Access']);
		foreach ($result['assignments'] as $a) {
			$table->addRow([
				$a['user_id'],
				$a['server_id'],
				$a['server_url'] ?: '—',
				$a['site'] ?: '—',
				$a['access'] === UserServer::ACCESS_READONLY ? 'read-only' : 'read-write',
			]);
		}
		$table->render();
		$output->writeln(sprintf('<info>Showing %d – %d of %d total</info>',
			$offset + 1,
			$offset + count($result['assignments']),
			$result['total'],
		));
		return 0;
	}
}
