<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Adds registered silo URLs to the master's form-action CSP directive so that
 * Safari (and other strict browsers) allow the post-login redirect to a silo.
 *
 * @implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener {
	public function __construct(private ShardingService $shardingService) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}
		if (!$this->shardingService->isMaster()) {
			return;
		}

		$policy = new EmptyContentSecurityPolicy();
		foreach ($this->shardingService->getAllServers() as $server) {
			$parsed = parse_url($server->getUrl());
			if (!$parsed || empty($parsed['host'])) {
				continue;
			}
			$origin = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
			if (!empty($parsed['port'])) {
				$origin .= ':' . $parsed['port'];
			}
			$policy->addAllowedFormActionDomain($origin);
		}
		$event->addPolicy($policy);
	}
}
