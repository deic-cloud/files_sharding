<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OC\Files\Filesystem;
use OCA\FilesSharding\Files\ConcealedGrantStorage;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;

/**
 * Half of the DAV-client conceal gate (see Application::concealSharesFromDavClients
 * for the whole story): hides the grant-folder root inside home storages from
 * DAV clients that authenticate with an Authorization header on the user's own
 * tree. Storage wrappers must be added while the filesystem is being set up
 * (core logs "was not registered via the 'OC_Filesystem - preSetup' hook" for
 * every wrapper added at any other time), hence a BeforeFileSystemSetupEvent
 * listener rather than a boot-time call. The gate condition is the same as
 * for the share mount filter, which stays in boot (mount filters have no such
 * timing rule).
 *
 * @template-implements IEventListener<BeforeFileSystemSetupEvent>
 */
class ConcealGrantsWrapperListener implements IEventListener {
	public const GATE_URI_PATTERN = '#^(/apps/files_sharding/appinfo/legacydav\.php)?/(remote\.php/(webdav|dav|sddav|files|grid)|files|grid)(/|$)#';

	public function __construct(
		private IRequest $request,
	) {
	}

	public static function applies(IRequest $request): bool {
		return preg_match(self::GATE_URI_PATTERN, $request->getRequestUri()) === 1
			&& $request->getHeader('Authorization') !== '';
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeFileSystemSetupEvent) || !self::applies($this->request)) {
			return;
		}
		Filesystem::addStorageWrapper(
			'files_sharding_conceal_grants',
			static function (string $mountPoint, IStorage $storage) {
				if ($storage->instanceOfStorage(IHomeStorage::class)) {
					return new ConcealedGrantStorage(['storage' => $storage]);
				}
				return $storage;
			},
		);
	}
}
