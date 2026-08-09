<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\ShardingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AddServer extends Command {
	public function __construct(private ShardingService $shardingService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:server:add')
			->setDescription('Register a new silo server')
			->addArgument('url', InputArgument::REQUIRED, 'Public URL of the silo (e.g. https://silo.example.org)')
			->addArgument('id',  InputArgument::OPTIONAL, 'Explicit server ID — a stable per-silo id to keep in the silo\'s TFTP rc.conf (upserts if it already exists). Omit to let the DB auto-assign.')
			->addOption('internal-url', 'i', InputOption::VALUE_REQUIRED, 'Internal URL for server-to-server calls', '')
			->addOption('site',         's', InputOption::VALUE_REQUIRED, 'Site label (e.g. Copenhagen)', '')
			->addOption('description',  'd', InputOption::VALUE_REQUIRED, 'Free-text description', '')
			->addOption('x509-dn',      null, InputOption::VALUE_REQUIRED, 'X.509 Subject DN for mutual TLS auth', '')
			->addOption('user-regex',   'r', InputOption::VALUE_REQUIRED, 'PCRE regex matched against user IDs for auto-assignment (e.g. /@dtu\\.dk$/)', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$url      = rtrim((string)$input->getArgument('url'), '/');
		$internal = (string)$input->getOption('internal-url');
		$site     = (string)$input->getOption('site');
		$desc     = (string)$input->getOption('description');
		$dn       = (string)$input->getOption('x509-dn');
		$regex    = (string)$input->getOption('user-regex');
		$idArg    = $input->getArgument('id');
		$id       = ($idArg === null || $idArg === '') ? null : (int)$idArg;

		if ($regex !== '' && @preg_match($regex, '') === false) {
			$output->writeln('<error>--user-regex is not a valid PCRE pattern.</error>');
			return 1;
		}
		if ($id !== null && $id < 1) {
			$output->writeln('<error>id must be a positive integer.</error>');
			return 1;
		}

		$server = $this->shardingService->addServer($url, $internal, $dn, $site, $desc, $regex, $id);
		$output->writeln('<info>Server registered with ID ' . $server->getId() . '</info>');
		$output->writeln('  URL:          ' . $server->getUrl());
		if ($internal !== '') $output->writeln('  Internal URL: ' . $server->getInternalUrl());
		if ($site     !== '') $output->writeln('  Site:         ' . $server->getSite());
		if ($regex    !== '') $output->writeln('  User regex:   ' . $server->getUserRegex());
		$output->writeln('');
		$output->writeln('On that silo, set its server id (survives reinstall via the TFTP rc.conf):');
		$output->writeln('  rc.conf:  files_sharding_server_id="' . $server->getId() . '"');
		$output->writeln('  or occ:   config:system:set files_sharding_server_id --value=' . $server->getId() . ' --type=integer');
		return 0;
	}
}
