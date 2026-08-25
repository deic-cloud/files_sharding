<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * Sabre DIRECTORY node for the anonymous public-share DAV endpoint — see
 * PublicFile. Every operation is gated by the SHARE's permission mask.
 */
class PublicDirectory implements ICollection {
	public function __construct(
		private Folder $node,
		private string $name,
		private int    $mask,
	) {
	}

	public static function wrap(Node $node, string $name, int $mask): PublicDirectory|PublicFile {
		if ($node instanceof File) {
			return new PublicFile($node, $name, $mask);
		}
		/** @var Folder $node */
		return new self($node, $name, $mask);
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

	public function getChildren(): array {
		if (!($this->mask & Constants::PERMISSION_READ)) {
			return []; // upload-only (file-drop) share: no listing
		}
		return array_values(array_map(
			fn (Node $c) => self::wrap($c, $c->getName(), $this->mask),
			$this->node->getDirectoryListing()
		));
	}

	public function getChild($name) {
		if (!($this->mask & Constants::PERMISSION_READ)) {
			throw new Forbidden('This share does not allow browsing');
		}
		try {
			$c = $this->node->get($name);
			return self::wrap($c, $c->getName(), $this->mask);
		} catch (\Throwable) {
			throw new NotFound($name);
		}
	}

	public function childExists($name): bool {
		if (!($this->mask & Constants::PERMISSION_READ)) {
			return false;
		}
		return $this->node->nodeExists($name);
	}

	public function createFile($name, $data = null): ?string {
		if (!($this->mask & Constants::PERMISSION_CREATE)) {
			throw new Forbidden('This share does not allow uploads');
		}
		$file = $this->node->newFile($name);
		if ($data !== null) {
			$file->putContent($data);
		}
		return '"' . $file->getEtag() . '"';
	}

	public function createDirectory($name): void {
		if (!($this->mask & Constants::PERMISSION_CREATE)) {
			throw new Forbidden('This share does not allow creating folders');
		}
		$this->node->newFolder($name);
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
