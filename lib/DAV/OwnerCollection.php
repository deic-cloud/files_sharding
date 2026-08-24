<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * One directory per sharing owner under /remote.php/sharingin/ — the
 * old-service model (documented at sciencedata.dk/sites/developer):
 *
 *   HOME_URL/sharingin/<owner_id>/<folder-or-file>
 *
 * "sharingin contains a directory for each person sharing files with you."
 * Grouping by owner also dissolves the name-collision problem: two owners can
 * each share a "data" folder without any "(2)" rename churn.
 *
 * The collection itself is structural (you cannot create a share by MKCOL);
 * writes happen INSIDE the shared nodes, governed by each share's permissions.
 */
class OwnerCollection implements ICollection {
	/** @param array<string, ShareNode> $children share-name => root node */
	public function __construct(
		private string $ownerId,
		private array  $children,
	) {
	}

	public function getName(): string {
		return $this->ownerId;
	}

	/** @return ShareNode[] */
	public function getChildren(): array {
		return array_values($this->children);
	}

	public function getChild($name): ShareNode {
		if (!isset($this->children[$name])) {
			throw new NotFound("$name not found");
		}
		return $this->children[$name];
	}

	public function childExists($name): bool {
		return isset($this->children[$name]);
	}

	public function getLastModified(): int {
		$max = 0;
		foreach ($this->children as $child) {
			$max = max($max, $child->getLastModified());
		}
		return $max;
	}

	public function createFile($name, $data = null): never {
		throw new Forbidden('Files can only be created inside a shared folder');
	}

	public function createDirectory($name): never {
		throw new Forbidden('Folders can only be created inside a shared folder');
	}

	public function delete(): never {
		throw new Forbidden('Unshare through the sharing UI/API instead');
	}

	public function setName($name): never {
		throw new Forbidden('Owner directories cannot be renamed');
	}
}
