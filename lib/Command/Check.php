<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Command;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Is this node healthy as a member of the cluster? Read-only. Meant for after a
 * reinstall or restore, and for cron (exit code 1 when something FAILs).
 *
 * Checks what broke silently on the silo2 test pod after a database rebuild
 * (2026-10-28): values encrypted under another 'secret' (the OCM signing key ->
 * every federated share from the node failed), an empty trusted-servers list
 * (shares to the node stayed pending), background jobs never registered (nothing
 * repaired the trust) - plus config files not writable by the web server (every
 * request 503) and stale nightly backups.
 */
class Check extends Command {
	private int $fails = 0;
	private int $warns = 0;

	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private IConfig           $config,
		private IAppConfig        $appConfig,
		private IDBConnection     $db,
		private ICrypto           $crypto,
		private IJobList          $jobList,
		private IAppManager       $appManager,
		private IUserManager      $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_sharding:check')
			->setDescription('Check that this node is healthy as a cluster member (read-only; exit 1 on FAIL)')
			->addOption('users', null, InputOption::VALUE_NONE, 'Also check that every local account lives on this node, per the master (one master query per account)')
			->addOption('quiet-ok', null, InputOption::VALUE_NONE, 'Print only warnings and failures');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$quietOk = (bool)$input->getOption('quiet-ok');
		$report = function (string $status, string $what, string $hint = '') use ($output, $quietOk): void {
			if ($status === 'FAIL') {
				$this->fails++;
			} elseif ($status === 'WARN') {
				$this->warns++;
			} elseif ($quietOk) {
				return;
			}
			$tag = ['OK' => '<info>  OK</info>', 'WARN' => '<comment>WARN</comment>', 'FAIL' => '<error>FAIL</error>'][$status];
			$output->writeln("$tag  $what" . ($hint !== '' ? "\n        → $hint" : ''));
		};

		$self = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		$isMaster = $this->shardingService->isMaster();
		$output->writeln('Node ' . ($self ?: '(overwrite.cli.url unset)') . ($isMaster ? ' (master)' : ' (silo)'));

		$this->checkConfig($report, $self);
		$this->checkDecryption($report);
		$masterUrls = $this->checkMaster($report, $isMaster);
		$this->checkTrust($report, $isMaster, $masterUrls);
		$this->checkJobs($report);
		$this->checkBackups($report);
		if ($input->getOption('users')) {
			$this->checkUsers($report, $isMaster);
		}

		$output->writeln(sprintf('%s: %d failure(s), %d warning(s)', $this->fails ? 'NOT HEALTHY' : 'Healthy', $this->fails, $this->warns));
		return $this->fails > 0 ? 1 : 0;
	}

	private function checkConfig(callable $report, string $self): void {
		foreach (['secret', 'passwordsalt', 'instanceid', 'files_sharding_shared_secret', 'files_sharding_master_url'] as $key) {
			if ((string)$this->config->getSystemValue($key, '') === '') {
				$report('FAIL', "config '$key' is not set");
			}
		}
		if ($self === '') {
			$report('FAIL', "config 'overwrite.cli.url' is not set", 'the node cannot recognise itself among the cluster servers');
		}
		// Nextcloud refuses every request when it cannot write its config (not read-only).
		$dir = \OC::$configDir;
		$bad = [];
		if (!is_writable($dir . 'config.php')) {
			$bad[] = 'config.php';
		}
		if (!is_writable($dir)) {
			$bad[] = 'config/';
		}
		$bad === []
			? $report('OK', 'config.php and config/ writable by the web server user')
			: $report('FAIL', 'not writable by this user: ' . implode(', ', $bad), 'chown www:www (every page answers "Cannot write into config directory")');
	}

	/** Everything encrypted with 'secret' must decrypt; core's fallback throws a ValueError otherwise. */
	private function checkDecryption(callable $report): void {
		$undecryptable = [];
		$try = function (string $label, string $cipher) use (&$undecryptable): void {
			try {
				$this->crypto->decrypt($cipher);
			} catch (\Throwable) {
				$undecryptable[] = $label;
			}
		};
		$dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
		$ipDir = $dataDir . '/appdata_' . $this->config->getSystemValue('instanceid', '') . '/identityproof';
		$keys = glob($ipDir . '/*/private') ?: [];
		foreach ($keys as $file) {
			$try('identityproof/' . basename(dirname($file)) . ' (signing key)', (string)file_get_contents($file));
		}
		foreach ($this->db->executeQuery("SELECT appid, configkey, configvalue FROM `*PREFIX*appconfig` WHERE configvalue LIKE ?", ['$AppConfigEncryption$%'])->fetchAll() as $r) {
			$try("appconfig {$r['appid']}/{$r['configkey']}", substr((string)$r['configvalue'], strlen('$AppConfigEncryption$')));
		}
		$userRows = $this->db->executeQuery("SELECT userid, appid, configkey, configvalue FROM `*PREFIX*preferences` WHERE configvalue LIKE ?", ['$UserConfigEncryption$%'])->fetchAll();
		foreach ($userRows as $r) {
			$try("user setting {$r['userid']} {$r['appid']}/{$r['configkey']}", substr((string)$r['configvalue'], strlen('$UserConfigEncryption$')));
		}
		foreach ($this->db->executeQuery("SELECT userid, configvalue FROM `*PREFIX*preferences` WHERE appid = 'files_sharding' AND configkey = 'session_pw'")->fetchAll() as $r) {
			$try("files_sharding session_pw of {$r['userid']}", (string)$r['configvalue']);
		}
		foreach ($this->db->executeQuery("SELECT user, identifier, credentials FROM `*PREFIX*storages_credentials`")->fetchAll() as $r) {
			$try("stored credentials {$r['user']} {$r['identifier']}", (string)$r['credentials']);
		}
		if ($undecryptable === []) {
			$report('OK', 'all encrypted values decrypt with this secret (' . count($keys) . ' signing key(s) included)');
			return;
		}
		$signing = array_filter($undecryptable, static fn ($l) => str_starts_with($l, 'identityproof/'));
		$report('FAIL', count($undecryptable) . ' value(s) encrypted under another secret: ' . implode('; ', array_slice($undecryptable, 0, 6)) . (count($undecryptable) > 6 ? '; …' : ''),
			$signing !== []
				? 'signing key: delete <datadirectory>/appdata_<instanceid>/identityproof/<folder> and restart the web server / PHP (a new key is made); federated sharing FROM this node fails until then'
				: 'they cannot be decrypted any more: delete them (occ config:app:delete / config:user:delete) and set them again where needed');
	}

	/** @return ?list<string> the cluster servers' URLs per the master, null if unknown */
	private function checkMaster(callable $report, bool $isMaster): ?array {
		if ($isMaster) {
			$urls = array_map(static fn ($s) => rtrim((string)$s->getUrl(), '/'), $this->shardingService->getAllServers());
			$report('OK', 'master: ' . count($urls) . ' registered server(s)');
			return $urls;
		}
		$data = $this->client->getDirect($this->shardingService->masterInternalUrl(), 'internal/servers');
		if (!is_array($data) || !isset($data['servers'])) {
			$report('FAIL', 'the master does not answer the internal API (' . $this->shardingService->masterInternalUrl() . ')',
				'network, or files_sharding_shared_secret differs from the master\'s');
			return null;
		}
		$urls = array_map(static fn ($s) => rtrim((string)($s['url'] ?? ''), '/'), $data['servers']);
		$report('OK', 'master answers the internal API (' . count($urls) . ' registered server(s))');
		return $urls;
	}

	/** Every other cluster node, and the master, must be a trusted server (auto-accept of incoming shares). */
	private function checkTrust(callable $report, bool $isMaster, ?array $serverUrls): void {
		if (!$this->appManager->isEnabledForAnyone('federation')) {
			$report('FAIL', 'the federation app is disabled', 'occ app:enable federation');
			return;
		}
		$trusted = array_map(static fn ($r) => rtrim((string)$r['url'], '/'),
			$this->db->executeQuery('SELECT url FROM `*PREFIX*trusted_servers`')->fetchAll());
		if ($serverUrls === null) {
			$report('WARN', 'trusted servers: ' . count($trusted) . ' (cluster list unknown - master unreachable)');
			return;
		}
		$expected = $serverUrls;
		if (!$isMaster) {
			$expected[] = $this->shardingService->masterUrl();
		}
		$others = array_filter(array_unique($expected), fn ($url) => $url !== '' && !$this->shardingService->isThisNode($url));
		$missing = array_values(array_filter($others, static fn ($url) => !in_array($url, $trusted, true)));
		$missing === []
			? $report('OK', 'all ' . count($others) . ' other cluster node(s) are trusted servers')
			: $report('FAIL', 'not trusted: ' . implode(', ', $missing),
				'incoming federated shares stay pending; run the SyncSiloTrustJob (or the master registers silos via addServer)');
		$autoAccept = $this->appConfig->getValueString('files_sharing', 'federatedTrustedShareAutoAccept', 'yes');
		if ($autoAccept !== '' && $autoAccept !== 'yes') {
			$report('FAIL', "files_sharing federatedTrustedShareAutoAccept = '$autoAccept'", 'occ config:app:delete files_sharing federatedTrustedShareAutoAccept');
		}
	}

	/** Every background job an enabled app declares must be registered, and cron must be running. */
	private function checkJobs(callable $report): void {
		$missing = [];
		foreach ($this->appManager->getEnabledApps() as $app) {
			$info = $this->appManager->getAppInfo($app) ?? [];
			foreach ((array)($info['background-jobs'] ?? []) as $job) {
				$job = ltrim((string)$job, '\\');
				if ($job !== '' && !$this->jobList->has($job, null)) {
					$missing[] = $job;
				}
			}
		}
		$missing === []
			? $report('OK', 'all background jobs of the enabled apps are registered')
			: $report('FAIL', 'background jobs not registered: ' . implode(', ', $missing),
				'IJobList->add(<class>) for each (or occ app:disable + app:enable the app)');
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		$last = $this->appConfig->getValueInt('core', 'lastcron', 0);
		if ($mode !== 'cron') {
			$report('WARN', "background jobs mode is '$mode', not cron");
		} elseif ($last < time() - 3600) {
			$report('FAIL', 'cron last ran ' . ($last ? date('Y-m-d H:i', $last) : 'never'), 'add cron.php to the www crontab');
		} else {
			$report('OK', 'cron ran ' . (int)((time() - $last) / 60) . ' min ago');
		}
	}

	/** zfs_backup.sh's nightly copies (physical boxes only - absent on test pods). */
	private function checkBackups(callable $report): void {
		$base = '/tank/data/backup';
		if (!is_dir($base)) {
			return;
		}
		$fresh = static fn (string $f): ?int => file_exists($f) ? (int)((time() - filemtime($f)) / 3600) : null;
		foreach ([
			'database copy' => "$base/mysql_backup/ibdata1",
			'system copy (config.php)' => "$base/root_backup/usr/local/www/nextcloud/config/config.php",
		] as $what => $file) {
			$age = $fresh($file);
			if ($age === null) {
				$report('WARN', "nightly $what: none ($file)");
			} elseif ($age > 26) {
				$report('FAIL', "nightly $what is {$age} h old", 'check /var/log/zfs_backup.log and the zfs_backup line in /etc/crontab');
			} else {
				$report('OK', "nightly $what is {$age} h old");
			}
		}
	}

	/** On a silo, a local account of a user the master places elsewhere captures shares meant for them. */
	private function checkUsers(callable $report, bool $isMaster): void {
		if ($isMaster) {
			$report('OK', 'accounts: skipped on the master (it holds a directory account for every user)');
			return;
		}
		$stray = [];
		$count = 0;
		$this->userManager->callForAllUsers(function ($user) use (&$stray, &$count): void {
			$count++;
			$uid = $user->getUID();
			$data = $this->client->getDirect($this->shardingService->masterInternalUrl(), 'internal/users/search', ['q' => $uid, 'limit' => 5]);
			foreach ((array)($data['users'] ?? []) as $u) {
				if ((string)($u['user_id'] ?? '') === $uid) {
					$home = (string)($u['silo_url'] ?? '');
					if ($home !== '' && !$this->shardingService->isThisNode($home)) {
						$stray[] = "$uid (home: $home)";
					}
				}
			}
		});
		$stray === []
			? $report('OK', "all $count local account(s) live here or are unknown to the master")
			: $report('WARN', 'local accounts the master places elsewhere: ' . implode(', ', $stray),
				'shares from this node to them would go to the stray account; the share dialog avoids it (MasterDirectory), but remove the account if unused');
	}
}
