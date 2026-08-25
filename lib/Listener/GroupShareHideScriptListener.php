<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads js/group-share-hide.js on the Files page (every node). It collapses a
 * group share back to a single "shared with <group>" row in the sharing sidebar by
 * hiding the per-member federated CHILD shares the fan-out creates (model A).
 *
 * Federated shares carry no label/attributes we could tag, so the children can't
 * be filtered server-side without a core patch; instead the script fetches the
 * current user's fan-out child ids (api#groupFanoutShares) and strips those entries
 * from the OCS shares response before the sidebar renders them. Suppression is
 * cosmetic — WebDAV and any /sharingout listing enumerate files, not share rows —
 * so hiding in the web UI is sufficient.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
class GroupShareHideScriptListener implements IEventListener {
	public function __construct(
		private \OCP\AppFramework\Services\IInitialState $initialState,
		private \OCP\IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addScript('files_sharding', 'group-share-hide');
		// Public-link creation popup (warning + URL with editable name + catalog
		// checkbox), intercepting the stock sharing pane's "+". The warning text
		// is deployment config (brand-neutral app default lives in the JS).
		$this->initialState->provideInitialState('link_warning',
			(string)$this->config->getSystemValue('files_sharding_link_warning', ''));
		Util::addScript('files_sharding', 'public-link-popup');
	}
}
