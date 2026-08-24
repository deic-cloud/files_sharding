<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesSharding\DAV\FolderFilterPlugin;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<SabrePluginAddEvent> */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private ShardingService $shardingService,
		private IRequest        $request,
		private IUserSession    $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent)) {
			return;
		}
		$event->getServer()->addPlugin(
			new FolderFilterPlugin($this->shardingService, $this->request, $this->userSession, $this->logger)
		);
	}
}
