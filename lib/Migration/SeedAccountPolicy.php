<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use OCA\FilesSharding\Service\AccountPolicy;
use OCA\FilesSharding\Service\ShardingService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Master, on upgrade:
 *  - if the restricted-account list was never set: start it with today's
 *    admin-group members (the accounts the old rule covered) and the VLAN
 *    trusted user ('cloud'), so nothing becomes looser;
 *  - mark accounts created by user_saml as having logged in through WAYF.
 */
class SeedAccountPolicy implements IRepairStep {
	public function __construct(
		private ShardingService $sharding,
		private AccountPolicy $policy,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IGroupManager $groupManager,
	) {
	}

	public function getName(): string {
		return 'Account policy: restricted accounts and WAYF logins';
	}

	public function run(IOutput $output): void {
		if (!$this->sharding->isMaster()) {
			return;
		}
		if (!$this->appConfig->hasKey('files_sharding', AccountPolicy::KEY_RESTRICTED)) {
			$users = [];
			foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $u) {
				$users[] = $u->getUID();
			}
			$vlan = trim((string)$this->config->getSystemValue('vlantrusteduser', ''));
			if ($vlan !== '') {
				$users[] = $vlan;
			}
			$users = $this->policy->setRestrictedUsers($users);
			$output->info('Restricted accounts: ' . implode(', ', $users));
		}
		$n = $this->policy->backfillWayfLogins();
		$output->info("WAYF logins recorded for $n existing account(s)");
	}
}
