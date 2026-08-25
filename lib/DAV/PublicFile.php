<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Constants;
use OCP\Files\File;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;

/**
 * Sabre FILE node for the anonymous public-share DAV endpoint
 * (/remote.php/public/<token>/…). The wrapped node is the OWNER's node and
 * carries the owner's permissions, so every operation is gated by the SHARE's
 * permission mask instead. Files and directories are separate classes (Sabre
 * decides resourcetype by `instanceof ICollection`).
 */
class PublicFile implements IFile {
	public function __construct(
		private File   $node,
		private string $name,
		private int    $mask,
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

	public function get() {
		if (!($this->mask & Constants::PERMISSION_READ)) {
			throw new Forbidden('This share does not allow downloads');
		}
		return $this->node->fopen('r');
	}

	public function put($data): ?string {
		if (!($this->mask & Constants::PERMISSION_UPDATE)) {
			throw new Forbidden('This share is read-only');
		}
		$this->node->putContent($data);
		return '"' . $this->node->getEtag() . '"';
	}

	public function getSize(): int {
		return $this->node->getSize();
	}

	public function getContentType(): string {
		return $this->node->getMimetype();
	}

	public function delete(): void {
		if (!($this->mask & Constants::PERMISSION_DELETE)) {
			throw new Forbidden('This share does not allow deleting');
		}
		$this->node->delete();
	}

	public function setName($name): void {
		if (!($this->mask & Constants::PERMISSION_UPDATE)) {
			throw new Forbidden('This share does not allow renaming');
		}
		$parent = dirname($this->node->getPath());
		$this->node->move($parent . '/' . $name);
		$this->name = $name;
	}
}
