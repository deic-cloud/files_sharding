<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Public links resolve cluster-wide on the master.
 *
 * A link's token exists only on the node that holds the data, but links are
 * published with whatever host the owner saw — and the OLD service's links
 * (sciencedata.dk/shared/<token>, in papers for 10+ years) all name the master,
 * which the image rewrites to /index.php/s/<token>. When the master does not
 * hold the token, probe the registered silos for it (any answer but 404 = it
 * is theirs, 401/403 included) and redirect the browser there, path and query
 * intact. Same rule appinfo/public.php applies to /remote.php/public/<token>.
 * Registered globally so it sees core's share controllers.
 */
class ShareLinkResolveMiddleware extends Middleware {
	private const CONTROLLERS = [
		'OCA\\Files_Sharing\\Controller\\ShareController',
		'OCA\\Files_Sharing\\Controller\\PublicPreviewController',
	];

	public function __construct(
		private ShardingService $shardingService,
		private IShareManager   $shareManager,
		private IRequest        $request,
		private ICacheFactory   $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!in_array(get_class($controller), self::CONTROLLERS, true) || !$this->shardingService->isMaster()) {
			return;
		}
		$token = (string)($this->request->getParam('token') ?? '');
		if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $token)) {
			return;
		}
		try {
			$this->shareManager->getShareByToken($token);
			return; // ours
		} catch (ShareNotFound) {
		}
		$home = $this->ownerNodeFor($token);
		if ($home === null) {
			return; // nobody has it — let core 404
		}
		$uri = $this->request->getRequestUri();
		throw new ShareLinkElsewhereException(rtrim($home, '/') . $uri);
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!($exception instanceof ShareLinkElsewhereException)) {
			throw $exception;
		}
		return new RedirectResponse($exception->url);
	}

	/** Public URL of the silo answering for $token, or null. Cached briefly per token. */
	private function ownerNodeFor(string $token): ?string {
		$cache = $this->cacheFactory->createLocal('files_sharding_linknode');
		$hit = $cache->get($token);
		if (is_string($hit)) {
			return $hit === '' ? null : $hit;
		}
		$found = null;
		foreach ($this->shardingService->getAllServers() as $server) {
			if ($this->shardingService->isSelf($server)) {
				continue;
			}
			$base = rtrim($server->getUrl(), '/');
			$ch = curl_init($base . '/index.php/s/' . rawurlencode($token));
			curl_setopt_array($ch, [
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_TIMEOUT        => 5,
			]);
			curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($code !== 0 && $code !== 404) {
				$found = $base;
				break;
			}
		}
		$cache->set($token, $found ?? '', 300);
		return $found;
	}
}

class ShareLinkElsewhereException extends \Exception {
	public function __construct(public readonly string $url) {
		parent::__construct('Share link lives on another node');
	}
}
