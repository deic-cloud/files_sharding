<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Files;

use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Files\Cache\ICache;
use OCP\Files\Storage\IStorage;

/**
 * Storage wrapper whose only job is to serve a ConcealedGrantCache, hiding the
 * grant-folder root from the default-DAV surface for external WebDAV clients.
 * Wrapped around home storages by Application::boot when the conceal gate
 * matches the request.
 */
class ConcealedGrantStorage extends Wrapper {
	/** @var string[] further storage paths to conceal (files/...) */
	private array $extraRoots;

	public function __construct(array $parameters) {
		parent::__construct($parameters);
		$this->extraRoots = $parameters['extraRoots'] ?? [];
	}

	public function getCache(string $path = '', ?IStorage $storage = null): ICache {
		if (!$storage) {
			$storage = $this;
		}
		return new ConcealedGrantCache(parent::getCache($path, $storage), $this->extraRoots);
	}
}
