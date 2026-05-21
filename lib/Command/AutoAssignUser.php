<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AutoAssignUser extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:user:auto-assign')
			->setDescription('Auto-assign a user to the best matching silo (regex first, then free space)')
			->addArgument('user', InputArgument::REQUIRED, 'User ID')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-assign even if user already has a silo');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		$force  = $input->getOption('force');

		if (!$force) {
			$existing = $this->shardingService->getUserServer($userId);
			if ($existing !== null) {
				$output->writeln("<comment>{$userId} is already assigned to {$existing->getUrl()}. Use --force to reassign.</comment>");
				return 0;
			}
		}

		$server = $this->shardingService->autoAssign($userId);
		if ($server === null) {
			$output->writeln('<error>No silo servers registered — cannot auto-assign.</error>');
			return 1;
		}

		$output->writeln("<info>{$userId} → {$server->getUrl()}</info>");
		return 0;
	}
}
