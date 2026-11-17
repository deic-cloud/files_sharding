<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OC\Files\Filesystem;
use OCA\FilesSharding\Auth\IpAuthBackend;
use OCA\FilesSharding\Auth\X509Backend;
use OCA\FilesSharding\Files\ConcealedGrantStorage;
use OCA\FilesSharding\Service\ShardingService;
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
		private ShardingService $shardingService,
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
		// The user's top-level folders that a visibility rule hides from this
		// client (e.g. Containers from sync clients): concealed from listings too,
		// not only refused on access (FolderFilterPlugin) - a client shown a folder
		// it then cannot open logs errors or takes it for deleted on the server.
		$sharding  = $this->shardingService;
		$clientIp  = $this->request->getRemoteAddress();
		$userAgent = $this->request->getHeader('User-Agent');
		Filesystem::addStorageWrapper(
			'files_sharding_conceal_grants',
			static function (string $mountPoint, IStorage $storage) use ($sharding, $clientIp, $userAgent) {
				if (!$storage->instanceOfStorage(IHomeStorage::class)) {
					return $storage;
				}
				$extra = [];
				$parts = array_values(array_filter(explode('/', $mountPoint)));
				if (count($parts) === 1) {
					try {
						foreach ($sharding->getFolders($parts[0]) as $rule) {
							$folder = trim((string)$rule->getFolder(), '/');
							if ($folder !== '' && !str_contains($folder, '/')
								&& !$sharding->isFolderVisibleFrom($parts[0], '/' . $folder, $clientIp, $userAgent)) {
								$extra[] = 'files/' . $folder;
							}
						}
					} catch (\Throwable) {
						// rules unavailable: grant root only
					}
				}
				return new ConcealedGrantStorage(['storage' => $storage, 'extraRoots' => $extra]);
			},
		);
	}
}
