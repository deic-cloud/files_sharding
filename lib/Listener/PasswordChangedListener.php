<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\User\Events\PasswordUpdatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Propagates a user's password to the master when they change it on their silo,
 * so one password works on both nodes: the user can still log in when WAYF is
 * down (password verified on the master) or when the master is down (password
 * verified directly on the silo).
 *
 * Silo -> master only. The password HASH is sent (never plaintext) over the
 * shared-secret internal channel; the master upserts it into its own oc_users,
 * creating a Database-backed account for the otherwise SAML-backed user so it can
 * password-verify them (with allow_multiple_user_back_ends). The random password
 * set at silo account-creation does NOT fire PasswordUpdatedEvent (Database::
 * createUser inserts the hash directly), so only real, user-chosen passwords
 * propagate.
 *
 * @implements IEventListener<PasswordUpdatedEvent>
 */
class PasswordChangedListener implements IEventListener {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $interServerClient,
		private IConfig           $config,
		private IDBConnection     $db,
		private IRequest          $request,
		private LoggerInterface   $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof PasswordUpdatedEvent)) {
			return;
		}
		// Silo -> master only; the master doesn't push passwords down.
		if ($this->shardingService->isMaster()) {
			return;
		}
		// Skip if this change arrived via an internal propagation call (loop guard).
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret !== '' && $this->request->getHeader('Authorization') === 'Bearer ' . $secret) {
			return;
		}

		$userId = $event->getUser()->getUID();
		$hash   = $this->currentHash($userId);
		if ($hash === '') {
			// User isn't Database-backed here / no hash to propagate.
			return;
		}

		$masterUrl = $this->shardingService->masterInternalUrl();
		if ($masterUrl === '') {
			return;
		}

		$result = $this->interServerClient->postDirect(
			$masterUrl,
			'internal/users/' . urlencode($userId) . '/pwhash',
			['hash' => $hash],
		);
		if ($result === null) {
			$this->logger->error("files_sharding: failed to propagate password for {$userId} to master");
		}
	}

	/** The stored password hash for $userId in the local oc_users, or '' if none. */
	private function currentHash(string $userId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('password')->from('users')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		return $row ? (string)$row['password'] : '';
	}
}
