<?php

declare(strict_types=1);

namespace OCA\FilesSharding\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCA\FilesSharding\Auth\IpAuthBackend;
use OCA\FilesSharding\Auth\X509Backend;
use OCA\FilesSharding\Files\ConcealedGrantStorage;
use OCA\FilesSharding\Listener\CspListener;
use OCA\FilesSharding\Listener\ExternalShareScanWarmer;
use OCA\FilesSharding\Listener\GroupMembershipListener;
use OCA\FilesSharding\Listener\GroupShareHideScriptListener;
use OCA\FilesSharding\Listener\GroupShareListener;
use OCA\FilesSharding\Listener\PostLoginListener;
use OCA\FilesSharding\Listener\PostLogoutListener;
use OCA\FilesSharding\Listener\ProxyShareAcceptanceListener;
use OCA\FilesSharding\Listener\ShareCreatedListener;
use OCA\FilesSharding\Listener\SabrePluginListener;
use OCA\FilesSharding\Listener\PasswordChangedListener;
use OCA\FilesSharding\Listener\SudoScriptListener;
use OCA\FilesSharding\Listener\SyncExternalSharesListener;
use OCA\FilesSharding\Listener\UserChangedListener;
use OCA\FilesSharding\Listener\UserDeletedListener;
use OCA\FilesSharding\Middleware\AdminIpMiddleware;
use OCA\FilesSharding\Middleware\OcmShareReceivedMiddleware;
use OCA\FilesSharding\Middleware\RedirectMiddleware;
use OCA\FilesSharding\Middleware\SudoPasswordMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\IUserManager;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\User\Events\PasswordUpdatedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use OCP\User\Events\UserLoggedOutEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_sharding';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
		$context->registerEventListener(UserLoggedInEvent::class, PostLoginListener::class);
		$context->registerEventListener(UserLoggedInWithCookieEvent::class, PostLoginListener::class);
		$context->registerEventListener(UserLoggedInEvent::class, SyncExternalSharesListener::class);
		$context->registerEventListener(UserLoggedInWithCookieEvent::class, SyncExternalSharesListener::class);
		// Also sync on every Files page load so a plain reload deterministically
		// reflects the user's current shares (closes the push-vs-reload race).
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, SyncExternalSharesListener::class);
		$context->registerEventListener(UserLoggedOutEvent::class, PostLogoutListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
		$context->registerEventListener(PasswordUpdatedEvent::class, PasswordChangedListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);
		$context->registerEventListener(FederatedShareAddedEvent::class, ProxyShareAcceptanceListener::class);
		// Warm the just-accepted external share's storage cache so RemoteController
		// returns real permissions on the next fetch instead of null (stock NC bug
		// that otherwise drops the share from "Shared with you" until a reload).
		$context->registerEventListener(FederatedShareAddedEvent::class, ExternalShareScanWarmer::class);
		// Share-consistency (2026-08-16): informational notice on local shares + a
		// notifier to render "X shared Y with you" (auto-accepted shares, action-less).
		// Negative priority so this runs AFTER core's files_sharing notification
		// listener (default 0) — we dismiss core's incoming_user_share, which must
		// therefore already exist when we run.
		$context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class, -100);
		// Cross-silo group sharing: register/deregister a group share in the master's
		// authoritative registry so the resolver can deliver it to members on any silo.
		$context->registerEventListener(ShareCreatedEvent::class, GroupShareListener::class);
		$context->registerEventListener(\OCP\Share\Events\ShareDeletedEvent::class, GroupShareListener::class);
		// Membership changes → the master reconciles the affected group's fan-out
		// across all nodes. user_group_admin's own event is the reliable signal (core
		// group events don't fire for its backend); core events cover plain groups.
		// The UGA event is referenced by name only — no hard dependency if it's absent.
		$context->registerEventListener('OCA\\UserGroupAdmin\\Event\\GroupMembersChangedEvent', GroupMembershipListener::class);
		$context->registerEventListener(UserAddedEvent::class, GroupMembershipListener::class);
		$context->registerEventListener(UserRemovedEvent::class, GroupMembershipListener::class);
		// Load the sidebar script that collapses a group share's fan-out children to
		// one "shared with <group>" row.
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, GroupShareHideScriptListener::class);
		$context->registerNotifierService(\OCA\FilesSharding\Notification\Notifier::class);
		$context->registerMiddleware(RedirectMiddleware::class, true);
		$context->registerMiddleware(\OCA\FilesSharding\Middleware\LogoutRedirectMiddleware::class, true);
		$context->registerMiddleware(OcmShareReceivedMiddleware::class, true);
		$context->registerMiddleware(SudoPasswordMiddleware::class, true);
		$context->registerMiddleware(AdminIpMiddleware::class, true);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, SudoScriptListener::class);
		$context->registerEventListener(BeforeLoginTemplateRenderedEvent::class, SudoScriptListener::class);
	}

	public function boot(IBootContext $context): void {
		$userManager = $context->getServerContainer()->get(IUserManager::class);
		// X509Backend first so the X.509 relay takes precedence; the IP backend
		// yields when a client-cert DN is present.
		$userManager->registerBackend($context->getServerContainer()->get(X509Backend::class));
		$userManager->registerBackend($context->getServerContainer()->get(IpAuthBackend::class));

		$this->concealSharesFromDavClients($context);
	}

	/**
	 * Keep received shares and grant folders OFF the default WebDAV surface for
	 * external clients (sync clients, curl, mounted drives) — the old-service
	 * model: HOME_URL/files is the user's OWN data; shares are reached through
	 * the dedicated /remote.php/sharingin//sharingout endpoints, and grant
	 * folders through /remote.php/user_group_admin/{gid}/.
	 *
	 * Why: a group owner sharing research data must be able to assume it does
	 * NOT silently replicate to every member's laptop via a sync client.
	 * Syncing a share is a CONSCIOUS act (configuring the dedicated URL), never
	 * a default. It also kills the mountpoint rename churn and the
	 * "unknown folder appeared → user deletes it" hazard on sync surfaces.
	 *
	 * Gate: only requests to the default DAV endpoints (/remote.php/webdav,
	 * /remote.php/dav) that carry an Authorization header. The web UI
	 * authenticates by session cookie (no Authorization header), so the Files
	 * app keeps showing shares ("All files" / "Shared with you") unchanged.
	 * Our /sharingin//sharingout/grants endpoints don't match the path gate,
	 * so the share mounts remain available THERE.
	 *
	 * Mechanics: received shares (local usergroup children AND federated
	 * mirrors) are mounts implementing OCA\Files_Sharing\ISharedMountPoint →
	 * one mount filter drops both kinds from the filesystem for this request.
	 * Grant folders are plain home-storage folders, so they're hidden at the
	 * filecache level (ConcealedGrantStorage/Cache) — DAV listings and path
	 * lookups are served from the cache, so this both strips the listing entry
	 * and 404s direct access.
	 */
	private function concealSharesFromDavClients(IBootContext $context): void {
		$server = $context->getServerContainer();
		try {
			$request = $server->get(\OCP\IRequest::class);
			$uri = $request->getRequestUri();
			if (!preg_match('#^/remote\.php/(webdav|dav)(/|$)#', $uri)) {
				return;
			}
			if ($request->getHeader('Authorization') === '') {
				return; // browser session (cookie auth) — web UI keeps shares
			}

			// Hide received-share mounts (local + federated).
			if (interface_exists(\OCA\Files_Sharing\ISharedMountPoint::class)) {
				$server->get(\OCP\Files\Config\IMountProviderCollection::class)
					->registerMountFilter(
						static fn (\OCP\Files\Mount\IMountPoint $mount, \OCP\IUser $user): bool
							=> !($mount instanceof \OCA\Files_Sharing\ISharedMountPoint)
					);
			}

			// Hide the grant-folder root inside home storages.
			\OC\Files\Filesystem::addStorageWrapper(
				'files_sharding_conceal_grants',
				static function (string $mountPoint, \OCP\Files\Storage\IStorage $storage) {
					if ($storage->instanceOfStorage(\OCP\Files\IHomeStorage::class)) {
						return new ConcealedGrantStorage(['storage' => $storage]);
					}
					return $storage;
				},
			);
		} catch (\Throwable $e) {
			// Concealment is a hardening layer — never break DAV over it.
			$server->get(\Psr\Log\LoggerInterface::class)
				->warning('files_sharding: conceal gate failed: ' . $e->getMessage());
		}
	}
}
