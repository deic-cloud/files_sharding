<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('files_sharding', 'admin');
		return new TemplateResponse('files_sharding', 'admin', []);
	}

	public function getSection(): string {
		return 'files-sharding';
	}

	public function getPriority(): int {
		return 50;
	}
}
