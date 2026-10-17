<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Db\UserServer;
use OCA\FilesSharding\Service\CertificateService;
use OCA\FilesSharding\Service\GroupShareFanoutService;
use OCA\FilesSharding\Service\ShardingService;
use OCA\FilesSharding\Service\TokenService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		string                     $appName,
		IRequest                   $request,
		private ShardingService    $shardingService,
		private TokenService       $tokenService,
		private IUserSession       $userSession,
		private IUserManager       $userManager,
		private IConfig            $config,
		private CertificateService $certificateService,
		private ISession           $session,
		private GroupShareFanoutService $fanout,
		private \OCP\Share\IManager $shareManager,
		private \OCP\Files\IRootFolder $rootFolder,
		private \OCA\FilesSharding\Service\LinkPolicy $linkPolicy,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * "Require login" on the public link of $path (LinkPolicy): only account
	 * holders can open the link, and every access is logged with their username.
	 * Stored as the share attribute files_sharding:require_login.
	 */
	#[NoAdminRequired]
	public function setLinkRequireLogin(string $path, bool $requireLogin = true): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse(['message' => 'Not logged in'], 401);
		}
		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
		} catch (\Throwable) {
			return new DataResponse(['message' => 'File not found'], 404);
		}
		$shares = $this->shareManager->getSharesBy($uid, \OCP\Share\IShare::TYPE_LINK, $node, false, 1, 0);
		if ($shares === []) {
			return new DataResponse(['message' => 'No public link exists for this file — create one first'], 404);
		}
		$this->linkPolicy->setRequireLogin($shares[0], $requireLogin);
		return new DataResponse(['require_login' => $requireLogin, 'token' => $shares[0]->getToken()]);
	}

	/**
	 * Rename a public link share's token to a user-chosen name (old-service
	 * feature: "type any name not already used by someone else"). The link then
	 * answers at /s/<name> and /remote.php/public/<name>/. Uniqueness is
	 * CLUSTER-wide: checked locally, then via the public endpoint's own probe
	 * (asking the master, whose 404-handler fans out to every silo — or probing
	 * the silos directly when we ARE the master).
	 */
	#[NoAdminRequired]
	public function setLinkName(string $path, string $name): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse(['message' => 'Not logged in'], 401);
		}
		$name = trim($name);
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/', $name)) {
			return new DataResponse(['message' => 'Name must be 3-64 characters: letters, digits, dot, dash, underscore'], 400);
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
		} catch (\Throwable) {
			return new DataResponse(['message' => 'File not found'], 404);
		}
		$shares = $this->shareManager->getSharesBy($uid, \OCP\Share\IShare::TYPE_LINK, $node, false, 1, 0);
		if ($shares === []) {
			return new DataResponse(['message' => 'No public link exists for this file — create one first'], 404);
		}
		$share = $shares[0];
		if ($share->getToken() === $name) {
			return new DataResponse(['token' => $name, 'url' => $this->linkUrl($name)]);
		}

		// Taken locally?
		try {
			$this->shareManager->getShareByToken($name);
			return new DataResponse(['message' => 'That name is already in use'], 409);
		} catch (\OCP\Share\Exceptions\ShareNotFound) {
		}
		// Taken anywhere else in the cluster?
		if ($this->linkNameTakenElsewhere($name)) {
			return new DataResponse(['message' => 'That name is already in use'], 409);
		}

		$share->setToken($name);
		$this->shareManager->updateShare($share);
		return new DataResponse(['token' => $name, 'url' => $this->linkUrl($name)]);
	}

	/**
	 * One-click "Add to my ScienceData": mount a PUBLIC LINK share into the
	 * logged-in visitor's own files. Mints a federated share
	 * owner → visitor@master — the same mechanism the group-share fan-out uses —
	 * which rides the mirror machinery to the visitor's home silo and appears
	 * under "Shared with you"/sharingin. Live view (owner can revoke, storage
	 * stays the owner's); copying items out of the mount into the home dir is
	 * the supported way to take a snapshot.
	 */
	#[NoAdminRequired]
	public function saveShare(string $token): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse(['message' => 'Not logged in'], 401);
		}
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (\OCP\Share\Exceptions\ShareNotFound) {
			return new DataResponse(['message' => 'Share not found'], 404);
		}
		if ($share->getShareType() !== \OCP\Share\IShare::TYPE_LINK) {
			return new DataResponse(['message' => 'Not a public link'], 400);
		}
		$owner = (string)$share->getShareOwner();
		if ($owner === $uid) {
			return new DataResponse(['status' => 'own']);
		}
		try {
			$node = $share->getNode();
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Shared content unavailable'], 404);
		}

		$masterHost = (string)preg_replace('#^https?://#', '', rtrim($this->shardingService->masterUrl(), '/'));
		if ($masterHost === '') {
			return new DataResponse(['message' => 'Cluster not configured'], 500);
		}
		$recipient = $uid . '@' . $masterHost;

		// Dedupe: an identical federated share already exists.
		try {
			foreach ($this->shareManager->getSharesBy($owner, \OCP\Share\IShare::TYPE_REMOTE, $node, false, -1, 0) as $existing) {
				if ((string)$existing->getSharedWith() === $recipient) {
					return new DataResponse(['status' => 'exists']);
				}
			}
		} catch (\Throwable) {
		}

		try {
			$new = $this->shareManager->newShare();
			$new->setNode($node)
				->setShareType(\OCP\Share\IShare::TYPE_REMOTE)
				->setSharedWith($recipient)
				->setSharedBy($owner)
				->setPermissions(\OCP\Constants::PERMISSION_READ);
			$this->shareManager->createShare($new);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => 'Could not add: ' . $e->getMessage()], 500);
		}
		return new DataResponse(['status' => 'added']);
	}

	/**
	 * "Copy to my files": a real copy of a public link's file or folder into the
	 * visitor's own files (top level, name of the shared item, "(2)" when taken).
	 * The counterpart of saveShare() for what a visitor wants to work on and
	 * save - notebooks above all; a received share is read-only. The content is
	 * read from the share itself when it is on this node, else over the link's
	 * public WebDAV on the cluster node that answers for it. Folders are copied
	 * recursively, up to COPY_MAX_FILES files / COPY_MAX_BYTES.
	 */
	#[NoAdminRequired]
	public function copyShare(string $token, string $path = '', string $name = ''): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse(['message' => 'Not logged in'], 401);
		}
		if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $token)) {
			return new DataResponse(['message' => 'Invalid link'], 400);
		}
		$path = trim(str_replace('\\', '/', $path), '/');
		if (str_contains($path, '..')) {
			return new DataResponse(['message' => 'Invalid path'], 400);
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			// On this node: copy the node tree directly.
			$share = $this->shareManager->getShareByToken($token);
			if ((string)$share->getShareOwner() === $uid) {
				return new DataResponse(['status' => 'own']);
			}
			$src = $share->getNode();
			if ($path !== '' && $src instanceof \OCP\Files\Folder) {
				$src = $src->get($path);
			}
			$target = $this->freeName($userFolder, $src->getName());
			$n = $this->countFiles($src);
			if ($n > self::COPY_MAX_FILES || $src->getSize() > self::COPY_MAX_BYTES) {
				return new DataResponse(['message' => 'Too large to copy; use "Share with me" instead'], 413);
			}
			$src->copy($userFolder->getPath() . '/' . $target);
			return new DataResponse(['status' => 'copied', 'path' => $target, 'files' => $n]);
		} catch (\OCP\Share\Exceptions\ShareNotFound) {
			// not here: fall through to the cluster
		} catch (\Throwable $e) {
			return new DataResponse(['message' => 'Could not copy: ' . $e->getMessage()], 500);
		}
		// Another node holds it: public WebDAV with the token as user name.
		$host = '';
		$client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
		$opts = ['auth' => [$token, ''], 'timeout' => 60, 'verify' => false, 'nextcloud' => ['allow_local_address' => true]];
		$probed = [];
		// Candidate nodes: the master (canonical link host), the node the master
		// redirects /index.php/s/<token> to (ShareLinkResolveMiddleware knows the
		// owner's node), and, on the master itself, every registered server.
		$bases = [];
		$master = rtrim((string)$this->shardingService->masterUrl(), '/');
		if ($master !== '') {
			$bases[] = $master;
			try {
				$h = $client->head($master . '/index.php/s/' . rawurlencode($token),
					['timeout' => 20, 'verify' => false, 'allow_redirects' => false, 'nextcloud' => ['allow_local_address' => true]]);
				$loc = (string)($h->getHeader('Location') ?? '');
				if ($loc !== '' && ($u = parse_url($loc)) && !empty($u['host'])) {
					$bases[] = ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
				}
			} catch (\Throwable) {
			}
		}
		foreach ($this->shardingService->getAllServers() as $server) {
			$bases[] = rtrim((string)$server->getUrl(), '/');
		}
		if ($bases === []) {
			$bases[] = rtrim((string)\OCP\Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');
		}
		foreach (array_values(array_unique($bases)) as $serverBase) {
			$base = $serverBase . '/public.php/webdav/';
			try {
				$r = $client->request('PROPFIND', $base, $opts + ['headers' => ['Depth' => '0']]);
				$probed[] = $base . ' ' . $r->getStatusCode();
				if ($r->getStatusCode() === 207) {
					$host = $base;
					break;
				}
			} catch (\Throwable $e) {
				$probed[] = $base . ' ' . $e->getMessage();
			}
		}
		if ($host === '') {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('files_sharding copyShare: no node answers for ' . $token . ': ' . implode('; ', $probed));
			return new DataResponse(['message' => 'Share not found'], 404);
		}
		try {
			$enc = static fn (string $p): string => implode('/', array_map('rawurlencode', array_filter(explode('/', $p), 'strlen')));
			// list what is behind the link (Depth: infinity is refused by default; walk level by level)
			$items = $this->davWalk($client, $host, $path, $opts);   // [['path'=>rel, 'dir'=>bool, 'size'=>int], ...]
			$files = array_filter($items, static fn ($i) => !$i['dir']);
			$bytes = array_sum(array_map(static fn ($i) => $i['size'], $files));
			if (count($files) > self::COPY_MAX_FILES || $bytes > self::COPY_MAX_BYTES) {
				return new DataResponse(['message' => 'Too large to copy; use "Share with me" instead'], 413);
			}
			$rootName = $path !== '' ? basename($path) : ($this->davName($client, $host, $opts) ?: (basename(str_replace('\\', '/', $name)) ?: 'Copy'));
			$target = $this->freeName($userFolder, $rootName);
			$isDir = count($items) !== 1 || $items[0]['dir'] || $items[0]['path'] !== '';
			if (!$isDir) {
				$body = (string)$client->get($host . $enc($path), $opts)->getBody();
				$userFolder->newFile($target, $body);
			} else {
				$dst = $userFolder->newFolder($target);
				foreach ($items as $i) {
					if ($i['path'] === '') {
						continue;
					}
					if ($i['dir']) {
						$dst->newFolder($i['path']);
					} else {
						$body = (string)$client->get($host . $enc(($path !== '' ? $path . '/' : '') . $i['path']), $opts)->getBody();
						$dst->newFile($i['path'], $body);
					}
				}
			}
			return new DataResponse(['status' => 'copied', 'path' => $target, 'files' => count($files)]);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => 'Could not copy: ' . $e->getMessage()], 500);
		}
	}

	private const COPY_MAX_FILES = 2000;
	private const COPY_MAX_BYTES = 5 * 1024 * 1024 * 1024;

	/** A top-level name not yet taken in the user's files: name, "name (2)", ... */
	private function freeName(\OCP\Files\Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		$stem = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
		for ($i = 2; $i < 1000; $i++) {
			$cand = $stem . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
			if (!$folder->nodeExists($cand)) {
				return $cand;
			}
		}
		throw new \RuntimeException('No free name');
	}

	private function countFiles(\OCP\Files\Node $node): int {
		if ($node instanceof \OCP\Files\File) {
			return 1;
		}
		$n = 0;
		foreach ($node->getDirectoryListing() as $child) {
			$n += $this->countFiles($child);
			if ($n > self::COPY_MAX_FILES) {
				break;
			}
		}
		return $n;
	}

	/** Name of the item behind a public link (its displayname at the WebDAV root). */
	private function davName(\OCP\Http\Client\IClient $client, string $host, array $opts): string {
		try {
			$xml = (string)$client->request('PROPFIND', $host, $opts + [
				'headers' => ['Depth' => '0', 'Content-Type' => 'text/xml'],
				'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
			])->getBody();
			if (preg_match('#<d:displayname>([^<]*)</d:displayname>#i', $xml, $m) && trim($m[1]) !== '') {
				return html_entity_decode(trim($m[1]));
			}
		} catch (\Throwable) {
		}
		return '';
	}

	/**
	 * Walk a public WebDAV tree level by level.
	 * @return list<array{path: string, dir: bool, size: int}> relative to $path; the root itself has path ''.
	 */
	private function davWalk(\OCP\Http\Client\IClient $client, string $host, string $path, array $opts): array {
		$enc = static fn (string $p): string => implode('/', array_map('rawurlencode', array_filter(explode('/', $p), 'strlen')));
		$out = [];
		$queue = [''];
		$seen = 0;
		while ($queue !== []) {
			$rel = array_shift($queue);
			$url = $host . $enc(($path !== '' ? $path . '/' : '') . $rel) . ($rel !== '' || $path !== '' ? '/' : '');
			$xml = (string)$client->request('PROPFIND', rtrim($url, '/') . ($rel === '' && $path === '' ? '' : '/'), $opts + ['headers' => ['Depth' => '1']])->getBody();
			preg_match_all('#<d:response>(.*?)</d:response>#is', $xml, $resps);
			$first = true;
			foreach ($resps[1] as $r) {
				if (!preg_match('#<d:href>([^<]+)</d:href>#i', $r, $h)) {
					continue;
				}
				$href = rawurldecode(html_entity_decode($h[1]));
				$isDir = (bool)preg_match('#<d:collection\s*/?>#i', $r);
				$size = preg_match('#<d:getcontentlength>(\d+)</d:getcontentlength>#i', $r, $sz) ? (int)$sz[1] : 0;
				$name = basename(rtrim($href, '/'));
				if ($first) {
					// the collection (or file) itself
					$first = false;
					if ($rel === '') {
						$out[] = ['path' => '', 'dir' => $isDir, 'size' => $size];
						if (!$isDir) {
							return $out;
						}
					}
					continue;
				}
				$child = ($rel !== '' ? $rel . '/' : '') . $name;
				$out[] = ['path' => $child, 'dir' => $isDir, 'size' => $size];
				if ($isDir) {
					$queue[] = $child;
				}
				if (++$seen > self::COPY_MAX_FILES) {
					return $out;
				}
			}
		}
		return $out;
	}

	/** Canonical public-link URL: <master>/shared/<token> (see ShardingService::publicLinkUrl). */
	private function linkUrl(string $token): string {
		return $this->shardingService->publicLinkUrl($token);
	}

	private function linkNameTakenElsewhere(string $name): bool {
		$probe = function (string $baseUrl) use ($name): bool {
			$ch = curl_init(rtrim($baseUrl, '/') . '/remote.php/public/' . rawurlencode($name) . '/');
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
				CURLOPT_HTTPHEADER     => ['Depth: 0'],
				CURLOPT_NOBODY         => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_TIMEOUT        => 5,
			]);
			curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			return $code !== 0 && $code !== 404; // 207/401/… all mean "exists"
		};
		if ($this->shardingService->isMaster()) {
			foreach ($this->shardingService->getAllServers() as $server) {
				if (!$this->shardingService->isSelf($server) && $probe($server->getUrl())) {
					return true;
				}
			}
			return false;
		}
		$master = $this->shardingService->masterUrl();
		return $master !== '' && $probe($master); // master's 404-handler probes every silo
	}

	private function currentUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * The current user's group-share fan-out CHILD share ids — the per-member
	 * federated shares the sidebar hides so a group share shows as one row.
	 * Consumed by js/group-share-hide.js.
	 */
	#[NoAdminRequired]
	public function groupFanoutShares(): DataResponse {
		$uid = $this->currentUserId();
		if ($uid === '') {
			return new DataResponse(['ids' => []]);
		}
		return new DataResponse(['ids' => $this->fanout->fanoutShareIdsForOwner($uid)]);
	}


	// ── Servers (admin only) ──────────────────────────────────────────────────

	public function getServers(): DataResponse {
		$servers = array_map(fn($s) => $s->jsonSerialize(), $this->shardingService->getAllServers());
		return new DataResponse(['servers' => $servers]);
	}

	public function addServer(
		string $url,
		string $internalUrl  = '',
		string $x509Dn       = '',
		string $site         = '',
		string $description  = '',
		string $userRegex    = '',
	): DataResponse {
		if (trim($url) === '') {
			return new DataResponse(['message' => 'url is required'], 400);
		}
		if ($userRegex !== '' && @preg_match($userRegex, '') === false) {
			return new DataResponse(['message' => 'user_regex is not a valid PCRE pattern'], 400);
		}
		$server = $this->shardingService->addServer($url, $internalUrl, $x509Dn, $site, $description, $userRegex);
		return new DataResponse(['server' => $server->jsonSerialize()]);
	}

	public function updateServer(
		int    $id,
		string $url          = '',
		string $internalUrl  = '',
		string $x509Dn       = '',
		string $site         = '',
		string $description  = '',
		string $userRegex    = '__UNSET__',
	): DataResponse {
		// user_regex may legitimately be set to empty string (clearing it), so we
		// use a sentinel to distinguish "not supplied" from "set to empty".
		$fields = [];
		if ($url !== '')          $fields['url']          = $url;
		if ($internalUrl !== '')  $fields['internal_url'] = $internalUrl;
		if ($x509Dn !== '')       $fields['x509_dn']      = $x509Dn;
		if ($site !== '')         $fields['site']         = $site;
		if ($description !== '')  $fields['description']  = $description;
		if ($userRegex !== '__UNSET__') {
			if ($userRegex !== '' && @preg_match($userRegex, '') === false) {
				return new DataResponse(['message' => 'user_regex is not a valid PCRE pattern'], 400);
			}
			$fields['user_regex'] = $userRegex;
		}
		$ok = $this->shardingService->updateServer($id, $fields);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Server not found'], 404);
	}

	public function deleteServer(int $id): DataResponse {
		$ok = $this->shardingService->deleteServer($id);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Server not found'], 404);
	}

	/** Update free space — admin use; silos use /internal/servers/{id}/free instead. */
	public function updateFree(int $id, int $freeGb): DataResponse {
		$ok = $this->shardingService->updateFree($id, $freeGb);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Server not found'], 404);
	}

	// ── User→silo assignment (admin only) ─────────────────────────────────────

	/**
	 * Returns paginated list of all user→silo assignments.
	 * Query params: server_id (optional filter), limit (default 100), offset (default 0).
	 */
	public function listUsers(int $serverId = 0, int $limit = 100, int $offset = 0): DataResponse {
		$result = $this->shardingService->listAssignments(
			$serverId > 0 ? $serverId : null,
			min($limit, 500),
			max($offset, 0),
		);
		return new DataResponse($result);
	}

	public function getUserServer(string $userId): DataResponse {
		$server = $this->shardingService->getUserServer($userId);
		if ($server === null) {
			return new DataResponse(['message' => 'No server assigned'], 404);
		}
		return new DataResponse(['server' => $server->jsonSerialize()]);
	}

	public function setUserServer(string $userId, int $serverId, int $access = UserServer::ACCESS_READWRITE): DataResponse {
		$ok = $this->shardingService->setUserServer($userId, $serverId, $access);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Server not found'], 404);
	}

	public function getUserAccess(string $userId): DataResponse {
		$access = $this->shardingService->getUserAccess($userId);
		return new DataResponse(['access' => $access]);
	}

	public function setUserAccess(string $userId, int $access): DataResponse {
		$server = $this->shardingService->getUserServer($userId);
		if ($server === null) {
			return new DataResponse(['message' => 'No server assigned for user'], 404);
		}
		$ok = $this->shardingService->setUserServer($userId, $server->getId(), $access);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Update failed'], 500);
	}

	// ── Login token (called internally / by trusted silos) ───────────────────

	/**
	 * Issue a one-time login token for $userId. Only callable on the master,
	 * authenticated via shared secret (checked by middleware).
	 */
	public function issueToken(string $userId): DataResponse {
		if (!$this->shardingService->isMaster()) {
			return new DataResponse(['message' => 'Only the master issues tokens'], 403);
		}
		if (!$this->userManager->userExists($userId)) {
			return new DataResponse(['message' => 'User not found'], 404);
		}
		$token = $this->tokenService->issue($userId);
		return new DataResponse(['token' => $token]);
	}

	/**
	 * Validate a token and return the user record so the silo can create/update
	 * the local account. Single-use — consumes the token.
	 */
	public function validateToken(string $token): DataResponse {
		if (!$this->shardingService->isMaster()) {
			return new DataResponse(['message' => 'Only the master validates tokens'], 403);
		}
		$userId = $this->tokenService->consume($token);
		if ($userId === null) {
			return new DataResponse(['message' => 'Invalid or expired token'], 401);
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new DataResponse(['message' => 'User not found'], 404);
		}
		return new DataResponse([
			'user_id'      => $user->getUID(),
			'display_name' => $user->getDisplayName(),
			'email'        => $user->getEMailAddress() ?? '',
			'quota'        => $user->getQuota(),
		]);
	}

	// ── Federation lookup (no admin required — called by NC sharing layer) ───

	/**
	 * Resolve a userId to its stable federated identity (user@masterUrl) and
	 * confirm which silo currently hosts it. Used by the share autocomplete
	 * provider and the federation proxy.
	 */
	#[NoAdminRequired]
	public function federationLookup(string $userId): DataResponse {
		if (!$this->userManager->userExists($userId)) {
			return new DataResponse(['message' => 'User not found'], 404);
		}
		$masterUrl = $this->shardingService->masterUrl();
		$server    = $this->shardingService->getUserServer($userId);
		return new DataResponse([
			'user_id'       => $userId,
			'federated_id'  => $userId . '@' . parse_url($masterUrl, PHP_URL_HOST),
			'silo_url'      => $server?->getUrl(),
			'silo_id'       => $server?->getId(),
		]);
	}

	// ── Folder visibility rules (no admin required — own user only) ──────────

	#[NoAdminRequired]
	public function getFolders(): DataResponse {
		$folders = array_map(fn($f) => $f->jsonSerialize(), $this->shardingService->getFolders($this->currentUserId()));
		return new DataResponse(['folders' => $folders]);
	}

	#[NoAdminRequired]
	public function addFolder(string $folder, string $onlyFrom = '', bool $hideFromClients = false): DataResponse {
		if (trim($folder) === '') {
			return new DataResponse(['message' => 'folder is required'], 400);
		}
		$f = $this->shardingService->addFolder($this->currentUserId(), $folder, $onlyFrom, $hideFromClients);
		return new DataResponse(['folder' => $f->jsonSerialize()]);
	}

	#[NoAdminRequired]
	public function updateFolder(int $id, ?string $onlyFrom = null, ?bool $hideFromClients = null): DataResponse {
		$ok = $this->shardingService->updateFolder($id, $onlyFrom, $hideFromClients);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Folder rule not found'], 404);
	}

	#[NoAdminRequired]
	public function deleteFolder(int $id): DataResponse {
		$ok = $this->shardingService->deleteFolder($id);
		return $ok ? new DataResponse(['success' => true]) : new DataResponse(['message' => 'Folder rule not found'], 404);
	}

	// ── X.509 client-certificate DNs (personal — own user only) ─────────────

	/** Returns all stored X.509 DNs for the current user. */
	#[NoAdminRequired]
	public function getX509Dns(): DataResponse {
		$userId = $this->currentUserId();
		$dns = [];
		for ($i = 0; $i < 10; $i++) {
			$dn = $this->config->getUserValue($userId, 'files_sharding', "x509_dn_{$i}", '');
			if ($dn !== '') {
				$dns[] = ['index' => $i, 'dn' => $dn];
			}
		}
		return new DataResponse(['dns' => $dns]);
	}

	/** Stores a new X.509 DN for the current user (up to 10). */
	#[NoAdminRequired]
	public function addX509Dn(string $dn): DataResponse {
		$dn = trim($dn);
		if ($dn === '') {
			return new DataResponse(['message' => 'dn is required'], 400);
		}
		$userId = $this->currentUserId();
		for ($i = 0; $i < 10; $i++) {
			$existing = $this->config->getUserValue($userId, 'files_sharding', "x509_dn_{$i}", '');
			if ($existing === '') {
				$this->config->setUserValue($userId, 'files_sharding', "x509_dn_{$i}", $dn);
				return new DataResponse(['index' => $i, 'dn' => $dn]);
			}
		}
		return new DataResponse(['message' => 'Maximum 10 DNs per user reached'], 400);
	}

	/** Removes the X.509 DN at the given index for the current user. */
	#[NoAdminRequired]
	public function deleteX509Dn(int $index): DataResponse {
		if ($index < 0 || $index >= 10) {
			return new DataResponse(['message' => 'Invalid index'], 400);
		}
		$this->config->deleteUserValue($this->currentUserId(), 'files_sharding', "x509_dn_{$index}");
		return new DataResponse(['success' => true]);
	}

	// ── Certificate generation ───────────────────────────────────────────────

	/** Generate (or renew) the user's personal RSA-4096 certificate. */
	#[NoAdminRequired]
	public function generateCert(int $days = 365): DataResponse {
		$userId = $this->currentUserId();
		$result = $this->certificateService->generateCertificate($userId, $days);
		if ($result === false) {
			return new DataResponse(['message' => 'Certificate generation failed — check server logs'], 500);
		}
		return new DataResponse($result);
	}

	/** Return info about the current user's certificate (subject, expiry). */
	#[NoAdminRequired]
	public function getCertInfo(): DataResponse {
		$userId = $this->currentUserId();
		$info = $this->certificateService->getCertInfo($userId);
		if ($info === null) {
			return new DataResponse(['exists' => false]);
		}
		// key_ok false = the key file exists but cannot be opened (it is encrypted
		// with the instance secret, so this happens after a secret change); the
		// certificate is then unusable until regenerated.
		$info['key_ok'] = $this->certificateService->getKeyPem($userId) !== '';
		$info['ca_name'] = $this->certificateService->caName();
		return new DataResponse(array_merge(['exists' => true], $info));
	}

	/** Delete the current user's certificate and private key files. */
	#[NoAdminRequired]
	public function deleteCertKey(): DataResponse {
		$userId = $this->currentUserId();
		$ok = $this->certificateService->deleteCertificate($userId);
		return $ok
			? new DataResponse(['success' => true])
			: new DataResponse(['message' => 'No certificate found'], 404);
	}

	// ── Sudo (master-login identity confirmation) ────────────────────────────

	/** Returns whether the session has a valid password-confirmation window open. */
	#[NoAdminRequired]
	public function sudoStatus(): DataResponse {
		$hasSessionPw = ($this->session->get('fsh_session_password') ?? '') !== '';
		$token = $this->session->get('fsh_sudo_token');
		$at    = (int)$this->session->get('fsh_sudo_token_at');
		if ($token !== null && $token !== '' && (time() - $at) <= 300) {
			return new DataResponse(['confirmed' => true, 'expires_in' => 300 - (time() - $at), 'has_session_pw' => $hasSessionPw]);
		}
		// Also report regular-mode status (last-password-confirm within 30 min).
		$lastConfirm = (int)$this->session->get('last-password-confirm');
		if ($lastConfirm > 0 && (time() - $lastConfirm) < 1800) {
			return new DataResponse(['confirmed' => true, 'expires_in' => 1800 - (time() - $lastConfirm), 'has_session_pw' => $hasSessionPw]);
		}
		return new DataResponse(['confirmed' => false, 'expires_in' => 0, 'has_session_pw' => $hasSessionPw]);
	}

	/**
	 * Returns the current sudo token so the frontend can auto-fill NC's strict
	 * password-confirmation dialog (Authorization: Basic user:<token>).
	 */
	#[NoAdminRequired]
	public function sudoToken(): DataResponse {
		$token = $this->session->get('fsh_sudo_token');
		$at    = (int)$this->session->get('fsh_sudo_token_at');
		if ($token === null || $token === '' || (time() - $at) > 300) {
			return new DataResponse(['token' => '']);
		}
		return new DataResponse(['token' => (string)$token]);
	}
}
