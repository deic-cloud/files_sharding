<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Util;

/**
 * Hide "Settings → Security → Password" for everyone — CONFIG-GATED, no core change.
 *
 * In a cluster with institutional (SAML) login, accounts on the master are
 * user_saml-backend and have no password form; accounts on silos are Database
 * accounts (created by the login exchange) and do. The deployment decided
 * (2026-09-10) on ONE story for all: no user-chosen service password — web
 * login via the institution (or ORCID), everything else via device/app
 * passwords from "Devices & sessions", which also log in on the web form.
 * With `files_sharding_hide_password_change` true, personal-settings pages get
 * a stylesheet hiding the password form so master- and silo-homed users see
 * the same Security page. Unset (default), nothing changes.
 *
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class HidePasswordChangeListener implements IEventListener {
	public function __construct(
		private IConfig  $config,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent) || !$event->isLoggedIn()) {
			return;
		}
		if (!$this->config->getSystemValueBool('files_sharding_hide_password_change', false)) {
			return;
		}
		if (!str_contains($this->request->getPathInfo() ?: '', '/settings/user')) {
			return;
		}
		Util::addStyle('files_sharding', 'hide-password-change');
	}
}
