<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stock "Transfer ownership of a file or folder" (Settings → Sharing) moves
 * files into the recipient's home ON THIS NODE. In the cluster every user is
 * offered by the picker (the sharee search lists all cluster users as
 * internal), and a remote-homed recipient usually has a directory account
 * here too — so a transfer to them would "succeed" into a home they never log
 * into, and the data would vanish for both parties. Refuse transfers unless
 * the recipient's home node is this node (fail-closed when the master cannot
 * be asked). Same-node transfers stay stock.
 */
class TransferOwnershipMiddleware extends Middleware {
	public function __construct(
		private ShardingService   $shardingService,
		private InterServerClient $client,
		private IRequest          $request,
		private IUserSession      $userSession,
		private LoggerInterface   $logger,
	) {
	}

	private function applies(Controller $controller, string $methodName): bool {
		return $methodName === 'transfer'
			&& get_class($controller) === 'OCA\\Files\\Controller\\TransferOwnershipController';
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->applies($controller, $methodName)) {
			return;
		}
		$recipient = trim((string)$this->request->getParam('recipient', ''));
		if ($recipient === '') {
			return; // the controller answers 400 itself
		}
		$homedHere = $this->recipientHomedHere($recipient);
		if ($homedHere === true) {
			return;
		}
		$sender = $this->userSession->getUser()?->getUID() ?? '?';
		$this->logger->info('files_sharding: refused ownership transfer from ' . $sender . ' to ' . $recipient
			. ($homedHere === null ? ' (recipient home node unknown — master not reachable)' : ' (recipient is homed on another node)'));
		throw new CrossNodeTransferException($recipient);
	}

	/**
	 * Is the recipient's home node this node? Master: from the authoritative
	 * user→silo map. Silo: the map is silent here, and a local account proves
	 * nothing (directory accounts exist for visitors too), so ask the master.
	 * null = could not determine.
	 */
	private function recipientHomedHere(string $recipient): ?bool {
		if ($this->shardingService->isMaster()) {
			return $this->shardingService->isResidentHere($recipient);
		}
		$master = $this->shardingService->masterInternalUrl();
		if ($master === '') {
			return null;
		}
		try {
			$data = $this->client->getDirect($master, 'internal/users/search', ['q' => $recipient, 'limit' => 20]);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: transfer guard could not ask the master: ' . $e->getMessage());
			return null;
		}
		if (!is_array($data) || !isset($data['users'])) {
			return null;
		}
		foreach ($data['users'] as $u) {
			if (strcasecmp((string)($u['user_id'] ?? ''), $recipient) === 0) {
				// Empty silo_url = master-homed, never this silo.
				$url = (string)($u['silo_url'] ?? '');
				return $url !== '' && $this->shardingService->isThisNode($url);
			}
		}
		return false; // unknown to the master: not a cluster user homed here
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof CrossNodeTransferException) {
			return new DataResponse(
				['message' => 'Ownership can only be transferred to users whose home is on this server.'],
				Http::STATUS_BAD_REQUEST,
			);
		}
		throw $exception;
	}
}
