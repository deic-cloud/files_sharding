<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\ClusterLinkService;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Browser side of cluster links (see ClusterLinkService for the whole chain).
 */
class ClusterLinkController extends Controller {
	public function __construct(
		string                     $appName,
		IRequest                   $request,
		private ClusterLinkService $links,
		private ShardingService    $sharding,
		private InterServerClient  $client,
		private IUserSession       $userSession,
		private IURLGenerator      $urlGenerator,
		private LoggerInterface    $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The link itself, on the master: sign the visitor in and send them to
	 * `open` on their own node. Public, so an anonymous click reaches the login.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function f(string $owner, int $fileid, string $path = ''): RedirectResponse {
		$open = '/index.php/apps/files_sharding/open?' . http_build_query([
			'owner' => $owner, 'fileid' => $fileid, 'path' => $path,
		]);
		$base = $this->sharding->isMaster() ? '' : rtrim($this->sharding->masterUrl(), '/');
		return new RedirectResponse($base . '/index.php/apps/files_sharding/dispatch?target=' . urlencode($open));
	}

	/**
	 * A node's internal link (/index.php/f/<id>) opened by someone not signed in
	 * there (ClusterLinkMiddleware): the same hop, naming the node, not the owner.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function fid(int $fileid, string $node = ''): RedirectResponse {
		$open = '/index.php/apps/files_sharding/open?' . http_build_query(['fileid' => $fileid, 'node' => $node]);
		$base = $this->sharding->isMaster() ? '' : rtrim($this->sharding->masterUrl(), '/');
		return new RedirectResponse($base . '/index.php/apps/files_sharding/dispatch?target=' . urlencode($open));
	}

	/** The visitor's own node: find their copy of the file and go there. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function open(string $owner = '', int $fileid = 0, string $path = '', string $node = ''): RedirectResponse|TemplateResponse {
		$viewer = $this->userSession->getUser()?->getUID() ?? '';
		$path = trim($path, '/');
		if ($owner === '' ? ($fileid <= 0 || $node === '') : ($fileid <= 0 && $path === '')) {
			return $this->noAccess($owner, $path);
		}
		$recipient = $this->links->clusterIdOf($viewer);
		if ($this->sharding->isMaster()) {
			$answer = $this->links->resolve($owner, $fileid, $path, $recipient, $node);
		} else {
			$data = $this->client->postDirect($this->sharding->masterInternalUrl(), 'internal/cluster-link/resolve', [
				'owner' => $owner, 'fileid' => $fileid, 'path' => $path, 'recipient' => $recipient, 'node' => $node,
			]);
			$answer = (is_array($data) && isset($data['silo'], $data['matches']) && is_array($data['matches']))
				? ['silo' => (string)$data['silo'], 'matches' => $this->links->cleanMatches($data['matches'])]
				: null;
		}
		$id = null;
		if ($answer !== null) {
			$id = $this->sharding->isThisNode($answer['silo'])
				? $this->links->localFileIdSameNode($viewer, $owner, $fileid, $path)
				: $this->links->localFileId($viewer, $answer['silo'], $answer['matches']);
		}
		if ($id === null) {
			$this->logger->info("files_sharding: cluster link {$owner}/{$fileid}/{$path}: no copy for {$viewer}", ['app' => 'files_sharding']);
			return $this->noAccess($owner, $path);
		}
		return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.showFile', ['fileid' => $id]));
	}

	private function noAccess(string $owner, string $path): TemplateResponse {
		$response = new TemplateResponse('files_sharding', 'cluster_link_denied', [
			'owner' => $owner,
			'name'  => $path === '' ? '' : basename($path),
			'home'  => $this->urlGenerator->linkToDefaultPageUrl(),
		], 'guest');
		$response->setStatus(404);
		return $response;
	}
}
