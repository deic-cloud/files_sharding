<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * How to reach your files over WebDAV, said plainly where people look: the
 * Files app's settings (replacing core's WebDAV section, whose URL embeds the
 * username URL-encoded and whose help link is about mounting) and the Security
 * page next to "Create new app password".
 *
 * Shown: the server address (system value `files_sharding_webdav_url`, a path
 * like '/files/' or a full URL; default core's /remote.php/dav/files/<user>/),
 * the username as it must be typed, and that the password is a device password.
 * `files_sharding_webdav_docs_url` (optional) links the deployment's own guide.
 *
 * @implements IEventListener<Event>
 */
class WebDavInfoListener implements IEventListener {
	public function __construct(
		private IConfig       $config,
		private IRequest      $request,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof LoadAdditionalScriptsEvent) {
			$this->provide();
			Util::addScript('files_sharding', 'webdav-info');
			Util::addScript('files_sharding', 'webdav-settings');
			Util::addStyle('files_sharding', 'webdav-settings');
			return;
		}
		if ($event instanceof BeforeTemplateRenderedEvent && $event->isLoggedIn()
			&& str_contains($this->request->getPathInfo() ?: '', '/settings/user/security')) {
			$this->provide();   // device-password.js shows it on the Security page
			Util::addScript('files_sharding', 'webdav-info');
			Util::addStyle('files_sharding', 'webdav-settings');
		}
	}

	private function provide(): void {
		$this->initialState->provideInitialState('webdav', [
			'url'         => trim((string)$this->config->getSystemValue('files_sharding_webdav_url', '')),
			'docsUrl'     => trim((string)$this->config->getSystemValue('files_sharding_webdav_docs_url', '')),
			'securityUrl' => $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'security']),
		]);
	}
}
