<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;

/**
 * Sabre FILE node for /sharingin //sharingout, wrapping an \OCP\Files\File.
 * Read/write per the share's permission mask (enforced by the node API —
 * NotPermittedException maps to 403). See ShareDavNode for why files and
 * directories are separate classes.
 */
class ShareFile implements IFile, ShareDavNode {
	public function __construct(
		private File   $node,
		private string $name,
		private bool   $isShareRoot = false,
	) {
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

	public function get() {
		try {
			return $this->node->fopen('r');
		} catch (NotPermittedException) {
			throw new Forbidden('No read permission');
		}
	}

	public function put($data): ?string {
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
