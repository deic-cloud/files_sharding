<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N         $l,
		private IURLGenerator $urlGenerator,
	) {}

	public function getID(): string {
		return 'files-sharding';
	}

	public function getName(): string {
		return $this->l->t('Trusted federation');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/shared.svg');
	}
}
