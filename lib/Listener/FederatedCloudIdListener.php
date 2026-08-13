<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * On silos, the stock "Federated Cloud" personal-settings field shows the user's
 * cloud ID built against the SILO host — Nextcloud computes that inline in
 * User::getCloudId() and it can't be overridden from an app (the way ICloudIdManager
 * can, see MasterCloudIdManager). Our federation identity is master-tied, so on the
 * sharing settings page we hand the (silo, master) pair to a small script that
 * corrects the displayed value and the copy-button output. Purely cosmetic — the
 * share flow itself already uses the master-tied ID.
 *
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class FederatedCloudIdListener implements IEventListener {
	public function __construct(
		private ShardingService $shardingService,
		private IUserSession    $userSession,
		private IInitialState   $initialState,
		private IRequest        $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent) || !$event->isLoggedIn()) {
			return;
		}
		// Master's own host IS the canonical host — nothing to correct there.
		if ($this->shardingService->isMaster() || $this->shardingService->masterUrl() === '') {
			return;
		}
		// Only the personal settings pages carry the field.
		if (!str_contains((string)$this->request->getRequestUri(), '/settings/user')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$wrong = $user->getCloudId(); // silo-host — what the stock field shows
		$masterHost = preg_replace('#^https?://#', '', $this->shardingService->masterUrl());
		$right = $user->getUID() . '@' . $masterHost; // master-host — what shares actually use
		if ($wrong === $right || $masterHost === '') {
			return;
		}

		$this->initialState->provideInitialState('federatedCloudId', ['wrong' => $wrong, 'right' => $right]);
		Util::addScript('files_sharding', 'federated-cloudid');
	}
}
