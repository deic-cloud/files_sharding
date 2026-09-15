<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Util;

/**
 * Settings → Sharing: a one-line hint under the stock "Transfer ownership of a
 * file or folder" section, saying the recipient must be homed on this server
 * (TransferOwnershipMiddleware refuses the rest — and the stock dialog can only
 * show its generic error, so the explanation has to be on the page).
 *
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class TransferHintScriptListener implements IEventListener {
	public function __construct(
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent) || !$event->isLoggedIn()) {
			return;
		}
		if (!str_contains($this->request->getPathInfo() ?: '', '/settings/user/sharing')) {
			return;
		}
		Util::addScript('files_sharding', 'transfer-hint');
	}
}
