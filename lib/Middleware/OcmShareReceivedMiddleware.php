<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\CloudFederationAPI\Controller\RequestHandlerController;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Runs on the master after every OCM addShare request.
 *
 * When Bob shares a file with alice@master-host, the master stores the share
 * and creates a bell notification — but Alice's silo knows nothing until her
 * next login. This middleware detects the successful OCM reception and
 * immediately calls the silo's sync endpoint so Alice sees the pending share
 * (and its notification) without having to log out and back in.
 */
class OcmShareReceivedMiddleware extends Middleware {
	public function __construct(
		private ShardingService $shardingService,
		private InterServerClient $client,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!$this->shardingService->isMaster()) {
			return $response;
		}

		if (!($controller instanceof RequestHandlerController) || $methodName !== 'addShare') {
			return $response;
		}

		if ($response->getStatus() !== Http::STATUS_CREATED) {
			return $response;
		}

		if (!($response instanceof JSONResponse)) {
			return $response;
		}

		$data   = $response->getData();
		$userId = (string)($data['recipientUserId'] ?? '');
		if ($userId === '') {
			return $response; // group share — skip for now
		}

		$server = $this->shardingService->getUserServer($userId);
		if ($server === null) {
			return $response; // user lives on the master itself
		}

		$siloUrl = $this->shardingService->apiUrlForServer($server);
		$result  = $this->client->postDirect($siloUrl, 'internal/users/' . rawurlencode($userId) . '/sync-shares', []);

		if ($result === null) {
			$this->logger->warning("files_sharding: OcmShareReceivedMiddleware: failed to push sync for {$userId} to {$siloUrl}");
		} else {
			$inserted = (int)($result['inserted'] ?? 0);
			$this->logger->info("files_sharding: OcmShareReceivedMiddleware: pushed sync for {$userId} to {$siloUrl} ({$inserted} inserted)");
		}

		// The recipient lives on a silo and only ever acts on their silo, where the
		// share is mirrored (auto-accepted for cluster origins, with our own clean
		// notice; pending with accept/decline for external partners). The stock
		// files_sharing notification core just created here on the MASTER — a moot
		// remote_share/incoming_user_share the user never sees in the right place —
		// is pure relay noise (and would email them). Dismiss it.
		try {
			$dismiss = $this->notificationManager->createNotification();
			$dismiss->setApp('files_sharing')->setUser($userId);
			$this->notificationManager->markProcessed($dismiss);
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: OcmShareReceivedMiddleware: could not clear master-side notice for ' . $userId . ': ' . $e->getMessage());
		}

		return $response;
	}
}
