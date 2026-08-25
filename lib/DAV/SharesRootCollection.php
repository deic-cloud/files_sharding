<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCA\FilesSharding\Service\GroupShareFanoutService;
use OCA\FilesSharding\Service\ShardingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * Root DAV collection for /remote.php/sharingin/ and /remote.php/sharingout/.
 *
 * sharingin  — files/folders shared WITH $userId, grouped one directory per
 *              sharing owner (old-service model, sites/developer docs):
 *                /sharingin/<owner_id>/<shared folder or file>
 *              Sources BOTH kinds of received shares in the sharded cluster:
 *              local oc_share rows (same-silo owners, incl. usergroup children)
 *              AND oc_share_external mirrors (cross-silo / federated owners —
 *              in this cluster that is most of them; core's getSharedWith()
 *              never sees those, which is why the old flat implementation
 *              listed nothing).
 *
 * sharingout — files/folders $userId has shared with others, flat, one entry
 *              per distinct node (a folder shared with three people appears
 *              once). Group-share fan-out children are collapsed into their
 *              parent group share.
 *
 * Both are READ/WRITE inside the shared nodes — this is the collaboration
 * surface (see ShareFile/ShareDirectory). Deliberately NOT reached by the default-endpoint
 * conceal gate: syncing/mounting shares via these URLs is a conscious act.
 */
class SharesRootCollection implements ICollection {
	/** @var array<string, ICollection|ShareDavNode>|null */
	private ?array $childCache = null;

	public function __construct(
		private string                  $userId,
		private bool                    $incoming,   // true = sharingin, false = sharingout
		private IShareManager           $shareManager,
		private IRootFolder             $rootFolder,
		private IDBConnection           $db,
		private ShardingService         $shardingService,
		private GroupShareFanoutService $fanout,
	) {
	}

	public function getName(): string {
		return $this->incoming ? 'sharingin' : 'sharingout';
	}

	public function getChildren(): array {
		return array_values($this->buildChildren());
	}

	public function getChild($name) {
		$children = $this->buildChildren();
		if (!isset($children[$name])) {
			throw new NotFound("$name not found");
		}
		return $children[$name];
	}

	public function childExists($name): bool {
		return isset($this->buildChildren()[$name]);
	}

	// ── Read-only structure ───────────────────────────────────────────────────

	public function createFile($name, $data = null): never {
		throw new Forbidden('Files can only be created inside a shared folder');
	}

	public function createDirectory($name): never {
		throw new Forbidden('Folders can only be created inside a shared folder');
	}

	public function delete(): never {
		throw new Forbidden('This endpoint cannot be deleted');
	}

	public function setName($name): never {
		throw new Forbidden('This endpoint cannot be renamed');
	}

	public function getLastModified(): int {
		return 0;
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/** @return array<string, ICollection|ShareDavNode> */
	private function buildChildren(): array {
		if ($this->childCache !== null) {
			return $this->childCache;
		}
		$this->childCache = $this->incoming ? $this->buildIncoming() : $this->buildOutgoing();
		return $this->childCache;
	}

	/** @return array<string, OwnerCollection> owner id => collection */
	private function buildIncoming(): array {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);

		// owner id => [share name => ShareDavNode]
		$byOwner = [];
		$add = function (string $owner, string $name, \OCP\Files\Node $node) use (&$byOwner): void {
			$name = $this->uniqueName($name, array_keys($byOwner[$owner] ?? []));
			$byOwner[$owner][$name] = ShareDirectory::wrap($node, $name, true);
		};

		// (1) Federated mirrors — cross-silo (and genuinely external) owners.
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner', 'remote', 'name', 'mountpoint')
		   ->from('share_external')
		   ->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)))
		   ->andWhere($qb->expr()->eq('accepted', $qb->createNamedParameter(IShare::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		$cur = $qb->executeQuery();
		$rows = $cur->fetchAll();
		$cur->closeCursor();

		foreach ($rows as $row) {
			if (str_starts_with((string)$row['mountpoint'], '/.uga_sponsored~')) {
				continue; // sponsored-folder system share — its surface is /sponsoredfolders
			}
			try {
				$node = $userFolder->get(ltrim((string)$row['mountpoint'], '/'));
			} catch (\Throwable) {
				continue; // mount not materialised — skip rather than 500 the listing
			}
			$owner  = (string)$row['owner'];
			$remote = (string)$row['remote'];
			if (!$this->shardingService->isClusterServer($remote)) {
				// External federation partner: qualify the owner with their host so
				// bob@uni-x.eu and bob@uni-y.eu don't merge.
				$owner .= '@' . preg_replace('#^https?://#', '', rtrim($remote, '/'));
			}
			// Present the share's own name, not the (possibly "(2)"-suffixed)
			// local mountpoint — inside an owner directory the original name is
			// almost always unique.
			$name = trim((string)$row['name'], '/') ?: $node->getName();
			$add($owner, $name, $node);
		}

		// (2) Local shares — owners co-resident on this node (direct or via a
		// group). Deduplicate by node: a folder reachable through both a user
		// share and a group share appears once.
		$seenNodes = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP] as $type) {
			try {
				$batch = $this->shareManager->getSharedWith($this->userId, $type, null, -1, 0);
			} catch (\Throwable) {
				continue;
			}
			foreach ($batch as $share) {
				if ($share->getStatus() !== IShare::STATUS_ACCEPTED) {
					continue;
				}
				// getSharedWith(TYPE_GROUP) returns a member's OWN shares to the
				// group too — sharingin is what others share with ME; skip self.
				if ((string)$share->getShareOwner() === $this->userId
					|| (string)$share->getSharedBy() === $this->userId) {
					continue;
				}
				// Sponsored-folder system shares live on /sponsoredfolders, not here.
				if (str_starts_with((string)$share->getTarget(), '/.uga_sponsored~')) {
					continue;
				}
				try {
					$node = $share->getNode();
				} catch (\Throwable) {
					continue;
				}
				if (isset($seenNodes[$node->getId()])) {
					continue;
				}
				$seenNodes[$node->getId()] = true;
				$add((string)$share->getShareOwner(), $node->getName(), $node);
			}
		}

		ksort($byOwner);
		$out = [];
		foreach ($byOwner as $owner => $children) {
			ksort($children);
			$out[$owner] = new OwnerCollection($owner, $children);
		}
		return $out;
	}

	/** @return array<string, ShareDavNode> name => node (flat, deduped by node) */
	private function buildOutgoing(): array {
		// Collapse group fan-out children (owner→member@master TYPE_REMOTE rows)
		// into their parent group share.
		$fanoutIds = array_fill_keys($this->fanout->fanoutShareIdsForOwner($this->userId), true);

		$children  = [];
		$seenNodes = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK, IShare::TYPE_REMOTE] as $type) {
			try {
				$batch = $this->shareManager->getSharesBy($this->userId, $type, null, false, -1, 0);
			} catch (\Throwable) {
				continue;
			}
			foreach ($batch as $share) {
				if (isset($fanoutIds[(int)$share->getId()])) {
					continue;
				}
				// Sponsored-folder system share (member's grant-folder ROOT → group
				// owner): app plumbing, not something the member shared. Local ones
				// carry the parked target; federated ones are recognized by the node
				// being a grant-folder root. (A member's deliberate share of a grant
				// SUBfolder — bill's case — has a deeper path and is unaffected.)
				if (str_starts_with((string)$share->getTarget(), '/.uga_sponsored~')) {
					continue;
				}
				try {
					$node = $share->getNode();
				} catch (\Throwable) {
					continue;
				}
				if (preg_match('#/files/\.uga_grants/[^/]+$#', $node->getPath())) {
					continue;
				}
				if (isset($seenNodes[$node->getId()])) {
					continue;
				}
				$seenNodes[$node->getId()] = true;
				$name = $this->uniqueName($node->getName(), array_keys($children));
				$children[$name] = ShareDirectory::wrap($node, $name, true);
			}
		}
		ksort($children);
		return $children;
	}

	private function uniqueName(string $base, array $existing): string {
		if (!in_array($base, $existing, true)) {
			return $base;
		}
		$i = 2;
		while (in_array("$base ($i)", $existing, true)) {
			$i++;
		}
		return "$base ($i)";
	}
}
