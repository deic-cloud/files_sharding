<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * Sabre DIRECTORY node for /sharingin //sharingout, wrapping an
 * \OCP\Files\Folder. Read/write per the share's permission mask. See
 * ShareDavNode for why files and directories are separate classes.
 */
class ShareDirectory implements ICollection, ShareDavNode {
	public function __construct(
		private Folder $node,
		private string $name,
		private bool   $isShareRoot = false,
	) {
	}

	/** Wrap an OCP node in the matching Sabre node class. */
	public static function wrap(Node $node, string $name, bool $isShareRoot = false): ShareDavNode {
		if ($node instanceof File) {
			return new ShareFile($node, $name, $isShareRoot);
		}
		/** @var Folder $node */
		return new self($node, $name, $isShareRoot);
	}

	public function getNode(): Node {
		return $this->node;
	}

	public function isShareRoot(): bool {
		return $this->isShareRoot;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLastModified(): int {
		return $this->node->getMTime();
	}

	public function getETag(): string {
		return '"' . $this->node->getEtag() . '"';
	}

	/** @return array<ShareDavNode> */
	public function getChildren(): array {
		return array_values(array_map(
			fn (Node $child) => self::wrap($child, $child->getName()),
			$this->node->getDirectoryListing()
		));
	}

	public function getChild($name) {
		try {
			$child = $this->node->get($name);
			return self::wrap($child, $child->getName());
		} catch (\Throwable) {
			throw new NotFound($name);
		}
	}

	public function childExists($name): bool {
		return $this->node->nodeExists($name);
	}

	public function createFile($name, $data = null): ?string {
		try {
			$file = $this->node->newFile($name);
			if ($data !== null) {
				$file->putContent($data);
			}
			return '"' . $file->getEtag() . '"';
		} catch (NotPermittedException) {
			throw new Forbidden('No create permission on this share');
		}
	}

	public function createDirectory($name): void {
		try {
			$this->node->newFolder($name);
		} catch (NotPermittedException) {
			throw new Forbidden('No create permission on this share');
		}
	}

	public function delete(): void {
		if ($this->isShareRoot) {
			throw new Forbidden('The share itself cannot be deleted here — unshare it instead');
		}
		try {
			$this->node->delete();
		} catch (NotPermittedException) {
			throw new Forbidden('No delete permission on this share');
		}
	}

	public function setName($name): void {
		if ($this->isShareRoot) {
			throw new Forbidden('The share itself cannot be renamed here');
		}
		try {
			$parent = dirname($this->node->getPath());
			$this->node->move($parent . '/' . $name);
			$this->name = $name;
		} catch (NotPermittedException) {
			throw new Forbidden('No rename permission on this share');
		}
	}
}
