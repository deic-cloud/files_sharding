<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Settings;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function __construct(
		private ShardingService $shardingService,
		private IURLGenerator   $urlGenerator,
	) {}

	public function getForm(): TemplateResponse {
		Util::addScript('files_sharding', 'personal');
		$isMaster  = $this->shardingService->isMaster();
		$masterUrl = $this->shardingService->masterUrl();
		return new TemplateResponse('files_sharding', 'personal', [
			'is_master'        => $isMaster,
			'master_url'       => $masterUrl,
			'sudo_initiate_url' => (!$isMaster && $masterUrl !== '')
				? $this->urlGenerator->linkToRoute(
					'files_sharding.login.sudoInitiate',
					['returnTo' => '/settings/user/security']
				)
				: '',
		]);
	}

	public function getSection(): string {
		return 'files-sharding';
	}

	public function getPriority(): int {
		return 50;
	}
}
