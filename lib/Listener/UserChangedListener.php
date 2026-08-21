<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<UserChangedEvent> */
class UserChangedListener implements IEventListener {
	/** Features the master pushes down to the silo. */
	private const MASTER_TO_SILO = ['displayName', 'eMailAddress', 'enabled', 'quota'];
	/**
	 * Features the silo pushes back up to the master. 'enabled' MUST be here: an
	 * external collaborator is deactivated on removal from their creating group on
	 * their HOME silo (GroupService::removeMember → setEnabled(false)); without
	 * propagating up, the master's directory copy stays enabled → master still lets
	 * the session through and redirects to the (disabled) home silo → login limbo.
	 */
	private const SILO_TO_MASTER = ['displayName', 'eMailAddress', 'enabled'];

	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $interServerClient,
		private IConfig           $config,
		private IRequest          $request,
		private LoggerInterface   $logger,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof UserChangedEvent)) return;

		// Skip if this change arrived via an internal propagation call — prevents
		// the master→silo→master ping-pong loop.
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret !== '' && $this->request->getHeader('Authorization') === 'Bearer ' . $secret) {
			return;
		}

		$userId  = $event->getUser()->getUID();
		$feature = $event->getFeature();

		if ($this->shardingService->isMaster()) {
			if (!in_array($feature, self::MASTER_TO_SILO, true)) return;
			$server = $this->shardingService->getUserServer($userId);
			if ($server === null) return;
			$this->propagate($server->getUrl(), $userId, $feature, $event->getValue());
		} else {
			if (!in_array($feature, self::SILO_TO_MASTER, true)) return;
			$masterUrl = $this->shardingService->masterInternalUrl();
			if ($masterUrl === '') return;
			$this->propagate($masterUrl, $userId, $feature, $event->getValue());
		}
	}

	private function propagate(string $baseUrl, string $userId, string $feature, mixed $value): void {
		$result = $this->interServerClient->postDirect(
			$baseUrl,
			'internal/users/' . rawurlencode($userId) . '/update',
			['feature' => $feature, 'value' => (string)$value],
		);
		if ($result === null) {
			$this->logger->error(
				"files_sharding: failed to propagate {$feature} change for {$userId} to {$baseUrl}"
			);
		}
	}
}
