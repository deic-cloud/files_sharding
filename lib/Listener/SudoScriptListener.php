<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @implements IEventListener<BeforeTemplateRenderedEvent|BeforeLoginTemplateRenderedEvent> */
class SudoScriptListener implements IEventListener {
	public function __construct(
		private ShardingService $shardingService,
	) {}

	public function handle(Event $event): void {
		// Only inject on silos with a master configured.
		if ($this->shardingService->isMaster()) return;
		if ($this->shardingService->masterUrl() === '') return;

		if ($event instanceof BeforeLoginTemplateRenderedEvent) {
			// login.js adds a "Login via master server" link on the silo login page.
			Util::addScript('files_sharding', 'login');
			return;
		}
		if ($event instanceof BeforeTemplateRenderedEvent && $event->isLoggedIn()) {
			Util::addScript('files_sharding', 'sudo');
		}
	}
}
