<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\DAV\RequireLoginPlugin;
use OCA\FilesSharding\Service\LinkPolicy;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\IManager as IShareManager;

/** @template-implements IEventListener<BeforeSabrePubliclyLoadedEvent> */
class PublicSabrePluginListener implements IEventListener {
	public function __construct(
		private LinkPolicy    $policy,
		private IShareManager $shareManager,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeSabrePubliclyLoadedEvent)) {
			return;
		}
		$event->getServer()?->addPlugin(new RequireLoginPlugin($this->policy, $this->shareManager));
	}
}
