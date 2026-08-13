<?php

declare(strict_types=1);

namespace OCA\FilesSharding\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FederatedFileSharing\Events\FederatedShareAddedEvent;
use OCA\FilesSharding\Auth\IpAuthBackend;
use OCA\FilesSharding\Auth\X509Backend;
use OCA\FilesSharding\Listener\CspListener;
use OCA\FilesSharding\Listener\FederatedCloudIdListener;
use OCA\FilesSharding\Listener\PostLoginListener;
use OCA\FilesSharding\Listener\PostLogoutListener;
use OCA\FilesSharding\Listener\ProxyShareAcceptanceListener;
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
		$context->registerEventListener(UserLoggedOutEvent::class, PostLogoutListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
		$context->registerEventListener(PasswordUpdatedEvent::class, PasswordChangedListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);
		$context->registerEventListener(FederatedShareAddedEvent::class, ProxyShareAcceptanceListener::class);
		$context->registerMiddleware(RedirectMiddleware::class, true);
		$context->registerMiddleware(\OCA\FilesSharding\Middleware\LogoutRedirectMiddleware::class, true);
		$context->registerMiddleware(OcmShareReceivedMiddleware::class, true);
		$context->registerMiddleware(SudoPasswordMiddleware::class, true);
		$context->registerMiddleware(AdminIpMiddleware::class, true);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, SudoScriptListener::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, FederatedCloudIdListener::class);
		$context->registerEventListener(BeforeLoginTemplateRenderedEvent::class, SudoScriptListener::class);
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();

		$userManager = $server->get(IUserManager::class);
		// X509Backend first so the X.509 relay takes precedence; the IP backend
		// yields when a client-cert DN is present.
		$userManager->registerBackend($server->get(X509Backend::class));
		$userManager->registerBackend($server->get(IpAuthBackend::class));

		// Re-bind the core cloud-id manager to our decorator so that, on silos, a
		// local user's federated identity is master-tied (stable across silo moves).
		// This MUST be done here (boot, against the SERVER container) and NOT via
		// $context->registerService() in register(): app registerService() binds only
		// into the app's OWN container, so core + federatedfilesharing consumers —
		// which resolve ICloudIdManager from the server container — would never see it.
		// SimpleContainer::registerService unsets-then-rebinds, so this overrides the
		// existing binding cleanly. It is a DI-level override (public OCP\IContainer
		// API), not a core patch — see MasterCloudIdManager. The inner (real) manager
		// is fetched by its concrete class name, which the container autowires.
		$server->registerService(\OCP\Federation\ICloudIdManager::class, function ($c): \OCA\FilesSharding\Federation\MasterCloudIdManager {
			return new \OCA\FilesSharding\Federation\MasterCloudIdManager(
				$c->get(\OC\Federation\CloudIdManager::class),
				$c->get(\OCA\FilesSharding\Service\ShardingService::class),
				$c->get(\OCP\IConfig::class),
			);
		});
	}
}
