<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OC\Files\Filesystem;
use OCA\FilesSharding\Auth\IpAuthBackend;
use OCA\FilesSharding\Auth\X509Backend;
use OCA\FilesSharding\Files\ConcealedGrantStorage;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;

/**
 * The DAV-client conceal gate's condition, plus half of its effect (see
 * Application::concealSharesFromDavClients for the whole story): hides the
 * grant-folder root inside home storages from DAV clients that authenticate
 * with an Authorization header on the user's own tree. Storage wrappers must
 * be added while the filesystem is being set up (core logs "was not registered
 * via the 'OC_Filesystem - preSetup' hook" for every wrapper added at any other
 * time), hence a BeforeFileSystemSetupEvent listener rather than a boot-time
 * call. The share mount filter stays in boot (mount filters have no such
 * timing rule) and uses applies() too, so both halves always agree.
 *
 * @template-implements IEventListener<BeforeFileSystemSetupEvent>
 */
class ConcealGrantsWrapperListener implements IEventListener {
	public const GATE_URI_PATTERN = '#^(/apps/files_sharding/appinfo/legacydav\.php)?/(remote\.php/(webdav|dav|sddav|files|grid)|files|grid)(/|$)#';

	public function __construct(
		private IRequest      $request,
		private IpAuthBackend $ipAuth,
		private X509Backend   $x509,
	) {
	}

	/**
	 * Does the conceal gate apply to this request? True for a user's own
	 * external client (sync client, curl, WebDAV mount) on the own-files DAV
	 * surfaces — the requests that must see only the user's own data.
	 *
	 * Not for the browser (cookie session, no Authorization header), and not
	 * for our own infrastructure acting on the user's behalf: credential-less
	 * requests from a pod on the user-pod VLAN (IpAuthBackend) and trusted
	 * X.509 daemons impersonating a user (X509Backend), e.g. the PDF-signing
	 * service fetching a PDF the user picked in the web UI — that file may sit
	 * in a received share or a grant folder, and a daemon is not a laptop the
	 * data would silently replicate to.
	 */
	public function applies(): bool {
		if (preg_match(self::GATE_URI_PATTERN, $this->request->getRequestUri()) !== 1) {
			return false;
		}
		if ($this->request->getHeader('Authorization') === '') {
			return false;
		}
		try {
			if ($this->x509->isTrustedDaemonRequest() || $this->ipAuth->isSessionActive()) {
				return false;
			}
		} catch (\Throwable) {
			// backend lookup failed → treat as an ordinary client (conceal)
		}
		return true;
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeFileSystemSetupEvent) || !$this->applies()) {
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
