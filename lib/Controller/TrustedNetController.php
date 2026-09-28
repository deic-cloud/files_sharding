<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Lookups for infrastructure services on the trusted infra net (system config
 * 'trustednet', e.g. "10.0.") — e.g. the container service, which must know a
 * user's home server to attach the user's storage to a container. No session,
 * no shared secret: the caller is trusted by its source address, as on the old
 * service. Read-only, and answered by the master only (it holds the user→server
 * assignments).
 */
class TrustedNetController extends Controller {
	public function __construct(
		string                   $appName,
		IRequest                 $request,
		private ShardingService  $shardingService,
		private IConfig          $config,
	) {
		parent::__construct($appName, $request);
	}

	private function onTrustedNet(): bool {
		$ip = $this->request->getRemoteAddress();
		foreach (preg_split('/\s+/', trim((string)$this->config->getSystemValue('trustednet', ''))) ?: [] as $net) {
			if ($net !== '' && !str_starts_with($net, 'TRUSTED_') && str_starts_with($ip, $net)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A user's home server, in the JSON of the old service's get_user_server:
	 * {url, id, status}. internal=yes (default) gives the internal URL, no/false
	 * the public one.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function userServer(string $user_id = '', string $internal = 'yes'): JSONResponse {
		if (!$this->onTrustedNet()) {
			return new JSONResponse(['status' => 'error', 'message' => 'Network not trusted'], 401);
		}
		if (!$this->shardingService->isMaster()) {
			return new JSONResponse(['status' => 'error', 'message' => 'Ask the master'], 404);
		}
		$server = $user_id === '' ? null : $this->shardingService->getUserServer($user_id);
		if ($server === null) {
			return new JSONResponse(['url' => '', 'id' => null, 'status' => 'error: no server for user'], 404);
		}
		$public = in_array($internal, ['no', 'false'], true);
		$url = $public ? (string)$server->getUrl() : ((string)$server->getInternalUrl() ?: (string)$server->getUrl());
		return new JSONResponse(['url' => $url, 'id' => $server->getId(), 'status' => 'success']);
	}
}
