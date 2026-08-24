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
use Sabre\DAV\IFile;

/**
 * Sabre node wrapping an \OCP\Files\Node reached through /sharingin or
 * /sharingout.
 *
 * READ/WRITE by design: shared data directories are the platform's core
 * collaboration surface (one party uploads data, others consume, process and
 * upload results). Enforcement is delegated to the underlying node — the mount
 * carries the share's permission mask, so a read-only share throws
 * NotPermittedException, which maps to DAV 403. No permission logic is
 * duplicated here.
 *
 * The node presented at the share root ($isShareRoot) refuses rename/delete:
 * its name is the share's presented name (and its backing node is a mountpoint
 * or the owner's own folder), so renaming/deleting it is share management, not
 * file collaboration — that belongs to the sharing UI/API.
 */
class ShareNode implements ICollection, IFile {
	public function __construct(
		private Node   $node,
		private string $name,
		private bool   $isShareRoot = false,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	/** The wrapped OCP node (for SharesPropsPlugin). */
	public function getNode(): Node {
		return $this->node;
	}

	public function isShareRoot(): bool {
		return $this->isShareRoot;
	}

	public function getLastModified(): int {
		return $this->node->getMTime();
	}

	public function getETag(): string {
		return '"' . $this->node->getEtag() . '"';
	}

	// ── IFile ─────────────────────────────────────────────────────────────────

	public function get() {
		if (!($this->node instanceof File)) {
			throw new Forbidden('Not a file');
		}
		try {
			return $this->node->fopen('r');
		} catch (NotPermittedException) {
			throw new Forbidden('No read permission');
		}
	}

	public function put($data): ?string {
		if (!($this->node instanceof File)) {
			throw new Forbidden('Not a file');
		}
		try {
			$this->node->putContent($data);
		} catch (NotPermittedException) {
			throw new Forbidden('No write permission on this share');
		}
		return '"' . $this->node->getEtag() . '"';
	}

	public function getSize(): int {
		return $this->node->getSize();
	}

	public function getContentType(): string {
		return $this->node->getMimetype();
	}

	// ── ICollection ───────────────────────────────────────────────────────────

	/** @return ShareNode[] */
	public function getChildren(): array {
		if (!($this->node instanceof Folder)) {
			return [];
		}
		return array_values(array_map(
			fn (Node $child) => new ShareNode($child, $child->getName()),
			$this->node->getDirectoryListing()
		));
	}

	public function getChild($name): ShareNode {
		if (!($this->node instanceof Folder)) {
			throw new NotFound($name);
		}
		try {
			$child = $this->node->get($name);
			return new ShareNode($child, $child->getName());
		} catch (\Throwable) {
			throw new NotFound($name);
		}
	}

	public function childExists($name): bool {
		if (!($this->node instanceof Folder)) {
			return false;
		}
		return $this->node->nodeExists($name);
	}

	public function createFile($name, $data = null): ?string {
		if (!($this->node instanceof Folder)) {
			throw new Forbidden('Not a folder');
		}
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
		if (!($this->node instanceof Folder)) {
			throw new Forbidden('Not a folder');
		}
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
