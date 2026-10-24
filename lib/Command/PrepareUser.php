<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prepare a user's account before their first login, so their data can be placed
 * where they will live (migration): the account exists (SAML backend, as a first
 * WAYF login would create it), the user is assigned by the same rule a first login
 * uses (autoAssign: user regex, then free space), and the home node has its local
 * account (LocalUserService, as the login exchange would create it). Prints the
 * home node; an existing assignment is kept. Run on the master.
 */
class PrepareUser extends Command {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private IUserManager      $userManager,
		private IAppManager       $appManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:user:prepare')
			->setDescription('Create, assign and provision a user ahead of their first login (migration); prints the home node')
			->addArgument('user', InputArgument::REQUIRED, 'User ID (as the IdP provides it)')
			->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name', '')
			->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail address', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->shardingService->isMaster()) {
			$output->writeln('<error>Run this on the master.</error>');
			return 1;
		}
		$userId = (string)$input->getArgument('user');
		$displayName = (string)$input->getOption('display-name');
		$email = (string)$input->getOption('email');

		// Strict uid match: get() also finds users by e-mail address.
		$user = $this->userManager->get($userId);
		if ($user === null || $user->getUID() !== $userId) {
			if (!$this->appManager->isEnabledForAnyone('user_saml') || !class_exists(\OCA\User_SAML\UserBackend::class)) {
				$output->writeln("<error>No account {$userId}, and no SAML backend to create it in.</error>");
				return 1;
			}
			\OCP\Server::get(\OCA\User_SAML\UserBackend::class)->createUserIfNotExists($userId);
			$user = $this->userManager->get($userId);
			if ($user === null || $user->getUID() !== $userId) {
				$output->writeln("<error>Could not create the account {$userId}.</error>");
				return 1;
			}
			$output->writeln("Created SAML account {$userId}");
		}
		if ($displayName !== '' && $user->getDisplayName() !== $displayName) {
			$user->setDisplayName($displayName);
		}
		if ($email !== '' && $user->getEMailAddress() !== $email) {
			$user->setEMailAddress($email);
		}

		$server = $this->shardingService->getUserServer($userId) ?? $this->shardingService->autoAssign($userId);
		if ($server === null) {
			$output->writeln("{$userId} -> master (no silo assignment)");
			return 0;
		}
		if (!$this->shardingService->isSelf($server)) {
			$result = $this->client->postDirect(
				$this->shardingService->apiUrlForServer($server),
				'internal/users/' . rawurlencode($userId) . '/provision',
				['display_name' => $user->getDisplayName(), 'email' => (string)$user->getEMailAddress()],
			);
			if ($result === null || empty($result['success'])) {
				$output->writeln("<error>{$userId}: assigned to {$server->getUrl()}, but provisioning the account there failed.</error>");
				return 1;
			}
			$output->writeln("Local account on {$server->getUrl()}: " . (!empty($result['created']) ? 'created' : 'already there'));
		}
		$output->writeln("{$userId} -> {$server->getUrl()} (server {$server->getId()})");
		return 0;
	}
}
