<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * Root DAV collection for /remote.php/sharingin/ and /remote.php/sharingout/.
 *
 * sharingin  — files/folders shared WITH $userId (incoming shares).
 * sharingout — files/folders shared BY $userId with others (outgoing shares).
 *
 * Both are read-only. The collection is flat: each accepted share's root
 * node appears as a direct child, named by the share's node name. If two
 * shares have the same name the later one gets "(2)" appended.
 */
class SharesRootCollection implements ICollection {
	/** @var array<string, ShareNode>|null */
	private ?array $childCache = null;

	private const INCOMING_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_REMOTE,
	];

	public function __construct(
		private string        $userId,
		private bool          $incoming,   // true = sharingin, false = sharingout
		private IShareManager $shareManager,
	) {
	}

	public function getName(): string {
		return $this->incoming ? 'sharingin' : 'sharingout';
	}

	/** @return ShareNode[] */
	public function getChildren(): array {
		return array_values($this->buildChildren());
	}

	public function getChild(string $name): ShareNode {
		$children = $this->buildChildren();
		if (!isset($children[$name])) {
			throw new NotFound("$name not found");
		}
		return $children[$name];
	}

	public function childExists(string $name): bool {
		return isset($this->buildChildren()[$name]);
	}

	// ── Read-only stubs ───────────────────────────────────────────────────────

	public function createFile(string $name, $data = null): string {
		throw new Forbidden('sharingin/sharingout is read-only');
	}

	public function createDirectory(string $name): void {
		throw new Forbidden('sharingin/sharingout is read-only');
	}

	public function delete(): void {
		throw new Forbidden('sharingin/sharingout is read-only');
	}

	public function setName(string $name): void {
		throw new Forbidden('sharingin/sharingout is read-only');
	}

	public function getLastModified(): int {
		return 0;
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/** @return array<string, ShareNode> */
	private function buildChildren(): array {
		if ($this->childCache !== null) {
			return $this->childCache;
		}

		$shares = $this->fetchShares();
		$children = [];
		foreach ($shares as $share) {
			try {
				$node = $share->getNode();
			} catch (\Throwable) {
				continue;
			}
			$name = $this->uniqueName($node->getName(), array_keys($children));
			$children[$name] = new ShareNode($node, $name, $this->incoming);
		}
		$this->childCache = $children;
		return $children;
	}

	/** @return IShare[] */
	private function fetchShares(): array {
		$shares = [];
		if ($this->incoming) {
			foreach (self::INCOMING_TYPES as $type) {
				try {
					$batch = $this->shareManager->getSharedWith(
						$this->userId, $type, null, -1, 0
					);
					foreach ($batch as $share) {
						// Only accepted shares
						if ($share->getStatus() === IShare::STATUS_ACCEPTED) {
							$shares[] = $share;
						}
					}
				} catch (\Throwable) {
				}
			}
		} else {
			foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_REMOTE] as $type) {
				try {
					$shares = array_merge(
						$shares,
						$this->shareManager->getSharesBy($this->userId, $type, null, false, -1, 0)
					);
				} catch (\Throwable) {
				}
			}
		}
		return $shares;
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
