<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\AccountPolicy;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Master: remember that an account has logged in through WAYF (SAML). user_saml
 * keeps the assertion's attributes in the session ('user_saml.samlUserData')
 * when the login comes back from the IdP. See AccountPolicy.
 *
 * @template-implements IEventListener<UserLoggedInEvent>
 */
class SamlLoginListener implements IEventListener {
	public function __construct(
		private ShardingService $sharding,
		private AccountPolicy $policy,
		private ISession $session,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedInEvent || !$this->sharding->isMaster()) {
			return;
		}
		if ($this->session->get('user_saml.samlUserData')) {
			$this->policy->recordWayfLogin($event->getUser()->getUID());
		}
	}
}
