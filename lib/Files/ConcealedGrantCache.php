<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Files;

use OC\Files\Cache\Wrapper\CacheWrapper;

/**
 * Cache wrapper that hides the grant-folder root (files/.uga_grants) from
 * listings and lookups. Applied to home storages ONLY for concealed requests
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

	private static function isConcealed(string $path): bool {
		return $path === self::GRANT_ROOT || str_starts_with($path, self::GRANT_ROOT . '/');
	}

	public function get($file) {
		if (is_string($file) && self::isConcealed($file)) {
			return false;
		}
		$entry = parent::get($file);
		if ($entry && self::isConcealed((string)$entry->getPath())) {
			// fileid-based lookup reaching into the grant tree
			return false;
		}
		return $entry;
	}

	public function getFolderContents(string $folder, ?string $mimeTypeFilter = null): array {
		return array_values(array_filter(
			parent::getFolderContents($folder, $mimeTypeFilter),
			static fn ($e) => !self::isConcealed((string)$e->getPath()),
		));
	}

	public function getFolderContentsById(int $fileId, ?string $mimeTypeFilter = null) {
		return array_values(array_filter(
			parent::getFolderContentsById($fileId, $mimeTypeFilter),
			static fn ($e) => !self::isConcealed((string)$e->getPath()),
		));
	}
}
