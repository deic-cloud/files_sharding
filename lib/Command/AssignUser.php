<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Db\UserServer;
use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AssignUser extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:user:assign')
			->setDescription('Assign or move a user to a silo, or unassign them back to the master')
			->addArgument('user',   InputArgument::REQUIRED, 'User ID')
			->addArgument('server', InputArgument::OPTIONAL, 'Silo server ID (see files_sharding:server:list)')
			->addOption('readonly', 'r', InputOption::VALUE_NONE, 'Grant read-only access instead of read-write')
			->addOption('unassign', null, InputOption::VALUE_NONE, 'Remove silo assignment (user stays on master)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');

		if ($input->getOption('unassign')) {
			$this->shardingService->unassignUser($userId);
			$output->writeln("<info>{$userId} unassigned — will stay on master.</info>");
			return 0;
		}

		$serverArg = $input->getArgument('server');
		if ($serverArg === null) {
			$output->writeln('<error>Provide a server ID, or use --unassign to remove the assignment.</error>');
			return 1;
		}

		$serverId = (int)$serverArg;
		$access   = $input->getOption('readonly') ? UserServer::ACCESS_READONLY : UserServer::ACCESS_READWRITE;

		$ok = $this->shardingService->setUserServer($userId, $serverId, $access);
		if (!$ok) {
			$output->writeln("<error>Server ID {$serverId} not found.</error>");
			return 1;
		}

		$server = $this->shardingService->getUserServer($userId);
		$output->writeln("<info>{$userId} → {$server->getUrl()} (" . ($access === UserServer::ACCESS_READONLY ? 'read-only' : 'read-write') . ')</info>');
		return 0;
	}
}
