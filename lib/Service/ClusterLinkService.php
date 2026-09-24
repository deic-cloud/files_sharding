<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Links to a file that work for whoever opens them, wherever they live.
 *
 * Nextcloud's own internal link, /index.php/f/<id>, names a node and a file id
 * that exist only on the owner's silo: anyone living elsewhere is asked to log
 * in there, which they cannot. A cluster link names the OWNER instead, and goes
 * through the master, which always knows where the owner and the visitor live:
 *
 *   https://<master>/index.php/apps/files_sharding/f/<owner>/<file id>/<path>
 *
 * A node's own internal link, opened by someone not signed in there, is sent
 * on as <master>/index.php/apps/files_sharding/fid/<file id>?node=<node>: no
 * owner or path in it, so probing ids tells an anonymous caller nothing.
 *
 * The file id finds the file after a rename, the path finds it after the owner
 * has been moved to another silo (a move gives files new ids), so the link fails
 * only if both have happened. The chain, for bob opening alice's file:
 *
 *  1. master `f`: bob is sent through the existing `dispatch` hop, which signs
 *     him in and lands him on his own silo, at `open`;
 *  2. bob's silo asks the master (`resolve`), the master asks alice's current
 *     silo (`resolveShared`): which of alice's shares reaching bob cover the
 *     file, and where it sits inside each. Only the master's registry knows
 *     where alice lives, so no silo ever takes a server address from a browser;
 *  3. bob's silo maps each answer through its own table of received shares,
 *     which records alice's silo, her share id and bob's mount point, and
 *     redirects bob to his local copy — or says plainly he has no access.
 */
class ClusterLinkService {
	public function __construct(
		private ShardingService $sharding,
		private IRootFolder     $rootFolder,
		private IUserManager    $userManager,
		private IShareManager   $shareManager,
		private IDBConnection   $db,
		private InterServerClient $client,
		private IUserMountCache $mountCache,
		private \OCP\IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** The cluster link for a file of $owner. */
	public function linkFor(string $owner, int $fileId, string $path): string {
		$base = rtrim($this->sharding->masterUrl(), '/');
		$segments = array_map('rawurlencode', array_values(array_filter(explode('/', trim($path, '/')), 'strlen')));
		return $base . '/index.php/apps/files_sharding/f/' . rawurlencode($owner) . '/' . $fileId
			. ($segments !== [] ? '/' . implode('/', $segments) : '');
	}

	/** How the master names a user in cluster shares: uid@<master authority>. */
	public function clusterIdOf(string $uid): string {
		$p = parse_url($this->sharding->masterUrl());
		return $uid . '@' . strtolower(($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
	}

	// ── Any node: a link, for a user ─────────────────────────────────────────

	/**
	 * What a file link names: a cluster link (owner, file id, path) or a node's
	 * own /index.php/f/<id> (file id, and the node it came from). Null for
	 * anything else.
	 *
	 * @return array{owner: string, fileid: int, path: string, node: string}|null
	 */
	public function parse(string $url): ?array {
		$p = parse_url(html_entity_decode($url));
		if ($p === false) {
			return null;
		}
		$path = (string)($p['path'] ?? '');
		$origin = isset($p['host'])
			? ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')
			: rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
		if (preg_match('#/index\.php/apps/files_sharding/f/([^/]+)/(\d+)(?:/(.*))?$#', $path, $m)) {
			return ['owner' => rawurldecode($m[1]), 'fileid' => (int)$m[2],
				'path' => implode('/', array_map('rawurldecode', explode('/', trim($m[3] ?? '', '/')))), 'node' => ''];
		}
		if (preg_match('#/index\.php/f/(\d+)$#', $path, $m)) {
			return ['owner' => '', 'fileid' => (int)$m[1], 'path' => '', 'node' => $origin];
		}
		return null;
	}

	/**
	 * $viewer's own file id for what a link names, wherever owner and viewer
	 * live — the lookup `open` does for a browser, for server-side callers too
	 * (e.g. a notes app hashing the files a note links). Null: not theirs to open.
	 */
	public function localIdFor(string $viewer, string $owner, int $fileId, string $path, string $node = ''): ?int {
		$path = trim($path, '/');
		if ($owner === '' ? ($fileId <= 0 || $node === '') : ($fileId <= 0 && $path === '')) {
			return null;
		}
		$recipient = $this->clusterIdOf($viewer);
		if ($this->sharding->isMaster()) {
			$answer = $this->resolve($owner, $fileId, $path, $recipient, $node);
		} else {
			$data = $this->client->postDirect($this->sharding->masterInternalUrl(), 'internal/cluster-link/resolve', [
				'owner' => $owner, 'fileid' => $fileId, 'path' => $path, 'recipient' => $recipient, 'node' => $node,
			]);
			$answer = (is_array($data) && isset($data['silo'], $data['matches']) && is_array($data['matches']))
				? ['silo' => (string)$data['silo'], 'matches' => $this->cleanMatches($data['matches'])]
				: null;
		}
		if ($answer === null) {
			return null;
		}
		return $this->sharding->isThisNode($answer['silo'])
			? $this->localFileIdSameNode($viewer, $owner, $fileId, $path)
			: $this->localFileId($viewer, $answer['silo'], $answer['matches']);
	}

	/** $viewer's own node for a file link, or null. */
	public function nodeForLink(string $viewer, string $url): ?Node {
		$l = $this->parse($url);
		if ($l === null) {
			return null;
		}
		$id = $this->localIdFor($viewer, $l['owner'], $l['fileid'], $l['path'], $l['node']);
		if ($id === null) {
			return null;
		}
		\OC_Util::setupFS($viewer);
		return $this->rootFolder->getUserFolder($viewer)->getFirstNodeById($id);
	}

	// ── Master ───────────────────────────────────────────────────────────────

	/**
	 * Master: where $owner lives, and the owner node's answer for $recipient.
	 *
	 * @return array{silo: string, matches: list<array{share_id: string, subpath: string}>}|null
	 *         null when the owner is unknown or their node did not answer
	 */
	public function resolve(string $owner, int $fileId, string $path, string $recipient, string $node = ''): ?array {
		if ($owner === '') {
			// A node's own internal link (/index.php/f/<id>): the node named it,
			// and only that node can say whose file the id is.
			$server = $this->serverAt($node);
			if ($server === null && !$this->sharding->isThisNode($node)) {
				return null;
			}
		} else {
			$server = $this->sharding->getUserServer($owner);
		}
		if ($server === null || $this->sharding->isSelf($server)) {
			if ($owner === '') {
				return ['silo' => $this->sharding->masterUrl(),
					'matches' => $this->resolveShared('', $fileId, '', $recipient)];
			}
			$user = $this->userManager->get($owner);
			if ($user === null || strcasecmp($user->getUID(), $owner) !== 0) {
				return null;
			}
			return ['silo' => $this->sharding->masterUrl(),
				'matches' => $this->resolveShared($owner, $fileId, $path, $recipient)];
		}
		$data = $this->client->postDirect($this->sharding->apiUrlForServer($server), 'internal/cluster-link/shares', [
			'owner' => $owner, 'fileid' => $fileId, 'path' => $path, 'recipient' => $recipient,
		]);
		if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
			return null;
		}
		return ['silo' => rtrim($server->getUrl(), '/'), 'matches' => $this->cleanMatches($data['matches'])];
	}

	/** The registered node whose public URL has the authority of $url. */
	private function serverAt(string $url): ?\OCA\FilesSharding\Db\Server {
		$target = $this->sharding->authority($url);
		if ($target === ':') {
			return null;
		}
		foreach ($this->sharding->getAllServers() as $s) {
			if ($this->sharding->authority($s->getUrl()) === $target) {
				return $s;
			}
		}
		return null;
	}

	/** @return list<array{share_id: string, subpath: string}> */
	public function cleanMatches(array $raw): array {
		$out = [];
		foreach ($raw as $m) {
			if (is_array($m) && isset($m['share_id']) && preg_match('/^\d+$/', (string)$m['share_id'])) {
				$sub = trim((string)($m['subpath'] ?? ''), '/');
				if (!in_array('..', explode('/', $sub), true)) {
					$out[] = ['share_id' => (string)$m['share_id'], 'subpath' => $sub];
				}
			}
		}
		return $out;
	}

	/**
	 * Recipient's node: the cluster link for a file inside a share $viewer
	 * received from another node, naming the file as its OWNER has it, so the
	 * link works for everyone the owner shared it with — not just $viewer.
	 * $mountPoint is the share's mount point in $viewer's files ('/Notes/X'),
	 * $subpath the file's path inside it. Null when the owner's node cannot say.
	 */
	public function linkForReceived(string $viewer, string $mountPoint, string $subpath): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner', 'remote_id')
			->from('share_external')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($viewer)))
			->andWhere($qb->expr()->eq('mountpoint_hash', $qb->createNamedParameter(md5('/' . trim($mountPoint, '/')))))
			->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			return null;
		}
		$body = ['owner' => (string)$row['owner'], 'share_id' => (string)$row['remote_id'],
			'subpath' => trim($subpath, '/'), 'recipient' => $this->clusterIdOf($viewer)];
		$data = $this->sharding->isMaster()
			? $this->ownerFileOnBehalf($body)
			: $this->client->postDirect($this->sharding->masterInternalUrl(), 'internal/cluster-link/owner-file', $body);
		if (!is_array($data) || empty($data['owner']) || !isset($data['fileid'], $data['path'])) {
			return null;
		}
		return $this->linkFor((string)$data['owner'], (int)$data['fileid'], (string)$data['path']);
	}

	/** Master: forward an owner-file question to the owner's node (or answer it, when that is us). */
	public function ownerFileOnBehalf(array $body): ?array {
		$server = $this->sharding->getUserServer((string)($body['owner'] ?? ''));
		if ($server === null || $this->sharding->isSelf($server)) {
			return $this->ownerFile((string)$body['owner'], (string)$body['share_id'], (string)$body['subpath'], (string)$body['recipient']);
		}
		$data = $this->client->postDirect($this->sharding->apiUrlForServer($server), 'internal/cluster-link/owner-file-local', $body);
		return is_array($data) ? $data : null;
	}

	/**
	 * Owner's node: the file at $subpath inside $owner's share $shareId, as the
	 * owner has it — only if that share really goes to $recipient.
	 *
	 * @return array{owner: string, fileid: int, path: string}|null
	 */
	public function ownerFile(string $owner, string $shareId, string $subpath, string $recipient): ?array {
		if (!preg_match('/^\d+$/', $shareId) || in_array('..', explode('/', $subpath), true)) {
			return null;
		}
		try {
			$share = $this->shareManager->getShareById('ocFederatedSharing:' . $shareId);
		} catch (\Throwable) {
			return null;
		}
		if (strcasecmp((string)$share->getSharedWith(), $recipient) !== 0
			|| strcasecmp((string)$share->getShareOwner(), $owner) !== 0) {
			return null;
		}
		try {
			\OC_Util::setupFS($owner);
			$node = $share->getNode();
			if ($subpath !== '') {
				$node = $node->get($subpath);
			}
			$ownerFolder = $this->rootFolder->getUserFolder($owner);
			$path = $ownerFolder->getRelativePath($node->getPath());
		} catch (\Throwable) {
			return null;
		}
		return $path === null ? null : ['owner' => $owner, 'fileid' => (int)$node->getId(), 'path' => ltrim($path, '/')];
	}

	// ── Owner's silo ─────────────────────────────────────────────────────────

	/**
	 * Which shares made from $owner's files reach $recipient and cover this
	 * file, and where the file sits inside each. Called by the visitor's silo.
	 *
	 * @return list<array{share_id: string, subpath: string}>
	 */
	public function resolveShared(string $owner, int $fileId, string $path, string $recipient): array {
		if ($owner === '') {
			[$owner, $path] = $this->ownerOf($fileId) ?? ['', ''];
			if ($owner === '') {
				return [];
			}
		}
		$node = $this->findOwnersNode($owner, $fileId, $path);
		if ($node === null) {
			return [];
		}
		$userFolder = $this->rootFolder->getUserFolder($owner);
		$rootPath = rtrim($userFolder->getPath(), '/');
		$matches = [];
		// Walk up from the file: a share of any enclosing folder covers it.
		for ($n = $node; $n !== null; $n = $this->parentWithin($n, $rootPath)) {
			try {
				$shares = $this->shareManager->getSharesBy($owner, IShare::TYPE_REMOTE, $n, true, -1, 0);
			} catch (\Throwable) {
				$shares = [];
			}
			foreach ($shares as $share) {
				if (strcasecmp((string)$share->getSharedWith(), $recipient) !== 0) {
					continue;
				}
				$sub = substr($node->getPath(), strlen(rtrim($n->getPath(), '/')));
				$matches[] = ['share_id' => (string)$share->getId(), 'subpath' => trim($sub, '/')];
			}
		}
		return $matches;
	}

	/**
	 * Whose file $fileId is on this node, and its path in their files: the user
	 * whose home mount holds it.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public function ownerOf(int $fileId): ?array {
		if ($fileId <= 0) {
			return null;
		}
		foreach ($this->mountCache->getMountsForFileId($fileId) as $mount) {
			$uid = $mount->getUser()->getUID();
			$internal = $mount->getInternalPath();
			if ($mount->getMountPoint() === '/' . $uid . '/' && ($internal === 'files' || str_starts_with($internal, 'files/'))) {
				return [$uid, (string)substr($internal, strlen('files/'))];
			}
		}
		return null;
	}

	private function findOwnersNode(string $owner, int $fileId, string $path): ?Node {
		$user = $this->userManager->get($owner);
		if ($user === null || strcasecmp($user->getUID(), $owner) !== 0) {
			return null; // strict: core would otherwise match an e-mail address
		}
		\OC_Util::setupFS($owner);
		$userFolder = $this->rootFolder->getUserFolder($owner);
		if ($fileId > 0) {
			$byId = $userFolder->getFirstNodeById($fileId);
			if ($byId !== null) {
				return $byId;
			}
		}
		$path = trim($path, '/');
		if ($path !== '') {
			try {
				return $userFolder->get($path);
			} catch (\Throwable) {
			}
		}
		return null;
	}

	private function parentWithin(Node $node, string $rootPath): ?Node {
		if (rtrim($node->getPath(), '/') === $rootPath) {
			return null;
		}
		try {
			$parent = $node->getParent();
		} catch (\Throwable) {
			return null;
		}
		return str_starts_with($parent->getPath() . '/', $rootPath . '/') ? $parent : null;
	}

	// ── Visitor's silo ───────────────────────────────────────────────────────

	/**
	 * The visitor's own file id for the file, given the owner silo's answer:
	 * each match names one of the owner's share ids, which this silo's table of
	 * received shares maps to a mount point in the visitor's files.
	 *
	 * @param list<array{share_id: string, subpath: string}> $matches
	 */
	public function localFileId(string $viewer, string $ownerSilo, array $matches): ?int {
		if ($matches === []) {
			return null;
		}
		$ids = array_values(array_unique(array_map(static fn ($m) => (string)$m['share_id'], $matches)));
		$qb = $this->db->getQueryBuilder();
		$qb->select('remote', 'remote_id', 'mountpoint')
			->from('share_external')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($viewer)))
			->andWhere($qb->expr()->in('remote_id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		$ownerAuthority = $this->sharding->authority($ownerSilo);
		\OC_Util::setupFS($viewer);
		$userFolder = $this->rootFolder->getUserFolder($viewer);
		foreach ($matches as $m) {
			foreach ($rows as $row) {
				if ((string)$row['remote_id'] !== (string)$m['share_id']
					|| $this->sharding->authority((string)$row['remote']) !== $ownerAuthority) {
					continue;
				}
				$local = trim((string)$row['mountpoint'], '/') . ($m['subpath'] !== '' ? '/' . $m['subpath'] : '');
				try {
					return $userFolder->get($local)->getId();
				} catch (\Throwable $e) {
					$this->logger->info('files_sharding: cluster link: ' . $local . ' not reachable for ' . $viewer
						. ': ' . $e->getMessage(), ['app' => 'files_sharding']);
				}
			}
		}
		return null;
	}

	/**
	 * The visitor's own file id when owner and visitor share a node: Nextcloud
	 * answers directly, from every mount the visitor has, shares included.
	 */
	public function localFileIdSameNode(string $viewer, string $owner, int $fileId, string $path): ?int {
		if ($owner === '') {
			[$owner, $path] = $this->ownerOf($fileId) ?? ['', ''];
			if ($owner === '') {
				return null;
			}
		}
		$node = $this->findOwnersNode($owner, $fileId, $path);
		if ($node === null) {
			return null;
		}
		\OC_Util::setupFS($viewer);
		return $this->rootFolder->getUserFolder($viewer)->getFirstNodeById($node->getId())?->getId();
	}
}
