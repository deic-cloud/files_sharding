<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;
use Sabre\DAV\IFile;

/**
 * Sabre node wrapping an \OCP\Files\Node from a share.
 *
 * For files: implements IFile (read-only for incoming, full for outgoing).
 * For folders: implements ICollection, recursively wrapping children.
 *
 * Read-only flag is inherited for sharingin; sharingout is also read-only
 * here (it is an audit view, not a write path).
 */
class ShareNode implements ICollection, IFile {
	public function __construct(
		private Node   $node,
		private string $name,
		private bool   $readOnly = true,
	) {
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

	// ── IFile ─────────────────────────────────────────────────────────────────

	public function get() {
		if (!($this->node instanceof File)) {
			throw new Forbidden('Not a file');
		}
		return $this->node->fopen('r');
	}

	public function put($data): string {
		throw new Forbidden('Read-only');
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
			fn(Node $child) => new ShareNode($child, $child->getName(), $this->readOnly),
			$this->node->getDirectoryListing()
		));
	}

	public function getChild($name): ShareNode {
		if (!($this->node instanceof Folder)) {
			throw new NotFound($name);
		}
		try {
			$child = $this->node->get($name);
			return new ShareNode($child, $child->getName(), $this->readOnly);
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

	// ── Write stubs ───────────────────────────────────────────────────────────

	public function createFile($name, $data = null): string {
		if ($this->readOnly || !($this->node instanceof Folder)) {
			throw new Forbidden('Read-only');
		}
		$file = $this->node->newFile($name);
		if ($data !== null) {
			$file->putContent($data);
		}
		return '"' . $file->getEtag() . '"';
	}

	public function createDirectory($name): void {
		if ($this->readOnly || !($this->node instanceof Folder)) {
			throw new Forbidden('Read-only');
		}
		$this->node->newFolder($name);
	}

	public function delete(): void {
		if ($this->readOnly) {
			throw new Forbidden('Read-only');
		}
		$this->node->delete();
	}

	public function setName($name): void {
		throw new Forbidden('Read-only');
	}
}
