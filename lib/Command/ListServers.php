<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListServers extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:server:list')
			->setDescription('List registered silo servers');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$servers = $this->shardingService->getAllServers();
		if (empty($servers)) {
			$output->writeln('<comment>No silo servers registered.</comment>');
			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'URL', 'Internal URL', 'Site', 'Free (GB)', 'User regex']);
		foreach ($servers as $s) {
			$table->addRow([
				$s->getId(),
				$s->getUrl(),
				$s->getInternalUrl() ?: '—',
				$s->getSite() ?: '—',
				$s->getFreeGb() !== 0 ? $s->getFreeGb() : '—',
				$s->getUserRegex() ?: '—',
			]);
		}
		$table->render();
		return 0;
	}
}
