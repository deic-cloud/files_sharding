<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'files-sharding';
	}

	public function getName(): string {
		return $this->l->t('Access & certificates');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/user-admin.svg');
	}
}
