<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Files;

use OC\Files\Cache\Wrapper\CacheWrapper;
use OCP\Files\Cache\ICache;

/**
 * Cache wrapper that hides the grant-folder root (files/.uga_grants) - and the
 * user's folders that a folder visibility rule hides from this client, e.g.
 * Containers from sync clients - from listings and lookups. Applied to home storages ONLY for concealed requests
 * (external WebDAV clients on the default endpoints — see Application::boot).
 *
 * DAV listings are served from the filecache (Folder::getDirectoryListing →
 * Cache::getFolderContentsById), NOT from opendir(), so the cache is the one
 * place a listing filter actually takes effect. Direct path access resolves
 * through Cache::get(), so returning false there yields a clean 404.
 *
 * Grant folders have their own WebDAV endpoint (/remote.php/user_group_admin/
 * {gid}/) — this only removes them from the DEFAULT endpoint, where a sync
 * client would otherwise pick them up (the dotfile convention hides them from
 * default sync excludes, but not from raw PROPFIND or configured clients).
 */
class ConcealedGrantCache extends CacheWrapper {
	private const GRANT_ROOT = 'files/.uga_grants';

	/** @var string[] storage paths of concealed folders (files/...) */
	private array $roots;

	/** @param string[] $extraRoots further storage paths to conceal, e.g. files/Containers */
	public function __construct(ICache $cache, array $extraRoots = []) {
		parent::__construct($cache);
		$this->roots = array_values(array_unique(array_merge([self::GRANT_ROOT], $extraRoots)));
	}

	private function isConcealed(string $path): bool {
		foreach ($this->roots as $root) {
			if ($path === $root || str_starts_with($path, $root . '/')) {
				return true;
			}
		}
		return false;
	}

	public function get($file) {
		if (is_string($file) && $this->isConcealed($file)) {
			return false;
		}
		$entry = parent::get($file);
		if ($entry && $this->isConcealed((string)$entry->getPath())) {
			// fileid-based lookup reaching into the grant tree
			return false;
		}
		return $entry;
	}

	public function getFolderContents(string $folder, ?string $mimeTypeFilter = null): array {
		return array_values(array_filter(
			parent::getFolderContents($folder, $mimeTypeFilter),
			fn ($e) => !$this->isConcealed((string)$e->getPath()),
		));
	}

	public function getFolderContentsById(int $fileId, ?string $mimeTypeFilter = null) {
		return array_values(array_filter(
			parent::getFolderContentsById($fileId, $mimeTypeFilter),
			fn ($e) => !$this->isConcealed((string)$e->getPath()),
		));
	}
}
