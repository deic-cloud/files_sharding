<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteServer extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:server:delete')
			->setDescription('Remove a silo server registration')
			->addArgument('id', InputArgument::REQUIRED, 'Server ID (see files_sharding:server:list)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (int)$input->getArgument('id');

		try {
			$server = $this->shardingService->getServer($id);
		} catch (DoesNotExistException) {
			$output->writeln('<error>Server ' . $id . ' not found.</error>');
			return 1;
		}

		if (!$input->getOption('force')) {
			$output->writeln('About to delete server ' . $id . ': ' . $server->getUrl());
			$output->write('Type "yes" to confirm: ');
			$answer = trim((string)fgets(STDIN));
			if ($answer !== 'yes') {
				$output->writeln('<comment>Aborted.</comment>');
				return 0;
			}
		}

		$this->shardingService->deleteServer($id);
		$output->writeln('<info>Server ' . $id . ' deleted.</info>');
		return 0;
	}
}
