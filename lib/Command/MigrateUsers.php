<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Db\Server;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\LocalUserService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create the accounts of a migration list (users.json from the export) on the
 * master, each the way files_sharding:user:prepare does it, with the home node
 * from the list (files_sharding:migrate:placement made it, by the first-login rule)
 * or, where the list has none, by that rule now - and with the old password hash:
 *
 * - the account (SAML backend, as a first WAYF login would create it; a plain
 *   Database account where user_saml is not installed, e.g. the test pods);
 * - the old password hash, stored as is on the master and on the home node, so
 *   the few users who set a password keep it (WAYF users have a random one);
 * - the assignment to the home node and the local account there.
 *
 * Safe to re-run: what is in place is left alone. A user who already has a
 * different home - or exists without one - is reported, not moved. Every home,
 * the master included, must be a registered server. Run on the master.
 */
class MigrateUsers extends Command {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private LocalUserService  $localUsers,
		private IUserManager      $userManager,
		private IAppManager       $appManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:migrate:users')
			->setDescription('Create the accounts of a migration list on the master and their home nodes')
			->addArgument('file', InputArgument::REQUIRED, 'users.json from the export')
			->addOption('hashes', null, InputOption::VALUE_REQUIRED, 'Tab-separated uid and password hash, one per line')
			->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated uids: import only these')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be done');
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
		$hashes = [];
		if ($input->getOption('hashes')) {
			foreach (file((string)$input->getOption('hashes'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
				[$uid, $hash] = array_pad(explode("\t", $line, 2), 2, '');
				if ($uid !== '' && $hash !== '') {
					$hashes[$uid] = $hash;
				}
			}
		}
		$only = $input->getOption('only') ? array_flip(explode(',', (string)$input->getOption('only'))) : null;
		$dry = (bool)$input->getOption('dry-run');

		$servers = [];
		foreach ($this->shardingService->getAllServers() as $server) {
			$servers[rtrim($server->getUrl(), '/')] = $server;
		}

		$count = ['created' => 0, 'existing' => 0, 'conflict' => 0, 'failed' => 0];
		foreach ($list as $entry) {
			$userId = (string)($entry['uid'] ?? '');
			if ($userId === '' || ($only !== null && !isset($only[$userId]))) {
				continue;
			}
			// The master is registered as a server too, and its residents are assigned
			// to it: an unassigned user is a new one, whom the first login places
			// (PostLoginListener → autoAssign).
			// No home in the list: the same rule as a first login (regex, default node).
			$home = rtrim((string)($entry['home'] ?? ''), '/');
			if ($home === '') {
				$chosen = $this->shardingService->getUserServer($userId) ?? $this->shardingService->chooseServer($userId);
				$home = $chosen === null ? '' : rtrim($chosen->getUrl(), '/');
			}
			$server = $servers[$home] ?? null;
			if ($server === null) {
				$output->writeln("<error>{$userId}: home {$home} is not a registered server</error>");
				$count['failed']++;
				continue;
			}

			$current = $this->shardingService->getUserServer($userId);
			if ($current !== null ? $current->getId() !== $server->getId() : $this->exists($userId)) {
				$output->writeln("<comment>{$userId}: already lives on " . ($current?->getUrl() ?? 'the master') . ", not {$home} - left as is</comment>");
				$count['conflict']++;
				continue;
			}
			if ($dry) {
				$output->writeln("{$userId} -> {$home}" . ($this->exists($userId) ? ' (exists)' : ''));
				continue;
			}

			try {
				$user = $this->ensureAccount($userId, $created);
				$this->applyAttributes($user, (string)($entry['display_name'] ?? ''), (string)($entry['email'] ?? ''));
				$hash = $hashes[$userId] ?? '';
				if ($hash !== '') {
					$this->localUsers->storePasswordHash($userId, $hash);
				}
				$this->placeOn($user, $server, $hash);
				$count[$created ? 'created' : 'existing']++;
				$output->writeln(($created ? 'created ' : 'updated ') . "{$userId} -> {$home}", OutputInterface::VERBOSITY_VERBOSE);
			} catch (\Throwable $e) {
				$output->writeln("<error>{$userId}: {$e->getMessage()}</error>");
				$count['failed']++;
			}
		}
		$output->writeln(sprintf('created %d, already there %d, other home (left as is) %d, failed %d',
			$count['created'], $count['existing'], $count['conflict'], $count['failed']));
		return $count['failed'] > 0 ? 1 : 0;
	}

	/** Strict uid match: get() also finds users by e-mail address. */
	private function exists(string $userId): bool {
		$user = $this->userManager->get($userId);
		return $user !== null && $user->getUID() === $userId;
	}

	private function ensureAccount(string $userId, ?bool &$created): IUser {
		$created = false;
		if (!$this->exists($userId)) {
			if ($this->appManager->isEnabledForAnyone('user_saml') && class_exists(\OCA\User_SAML\UserBackend::class)) {
				\OCP\Server::get(\OCA\User_SAML\UserBackend::class)->createUserIfNotExists($userId);
			} else {
				$this->userManager->createUser($userId, bin2hex(random_bytes(32)));
			}
			$created = true;
		}
		$user = $this->userManager->get($userId);
		if ($user === null || $user->getUID() !== $userId) {
			throw new \RuntimeException('could not create the account');
		}
		return $user;
	}

	private function applyAttributes(IUser $user, string $displayName, string $email): void {
		if ($displayName !== '' && $user->getDisplayName() !== $displayName) {
			$user->setDisplayName($displayName);
		}
		if ($email !== '' && $user->getEMailAddress() !== $email) {
			$user->setEMailAddress($email);
		}
	}

	/** Assign the user to $server and create the local account there, with the old hash. */
	private function placeOn(IUser $user, Server $server, string $hash): void {
		$userId = $user->getUID();
		if ($this->shardingService->getUserServer($userId) === null
			&& !$this->shardingService->setUserServer($userId, $server->getId())) {
			throw new \RuntimeException('could not assign to ' . $server->getUrl());
		}
		if ($this->shardingService->isSelf($server)) {
			return; // the account made above is the local one
		}
		$base = $this->shardingService->apiUrlForServer($server);
		$result = $this->client->postDirect($base, 'internal/users/' . rawurlencode($userId) . '/provision',
			['display_name' => $user->getDisplayName(), 'email' => (string)$user->getEMailAddress()]);
		if ($result === null || empty($result['success'])) {
			throw new \RuntimeException('assigned to ' . $server->getUrl() . ', but the local account there failed');
		}
		if ($hash !== '') {
			$result = $this->client->postDirect($base, 'internal/users/' . rawurlencode($userId) . '/pwhash', ['hash' => $hash]);
			if ($result === null || empty($result['success'])) {
				throw new \RuntimeException('local account on ' . $server->getUrl() . ' made, but storing the hash there failed');
			}
		}
	}
}
