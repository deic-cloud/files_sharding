<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

/**
 * In-memory single-use store for the post-login silo redirect URL.
 *
 * PostLoginListener writes here; RedirectMiddleware reads and clears it.
 * Both run in the same PHP request, so no session or cache needed.
 * Registered as a shared service so both get the same instance.
 */
class RedirectState {
	private ?string $url = null;

	public function set(string $url): void {
		$this->url = $url;
	}

	public function peek(): ?string {
		return $this->url;
	}

	public function consume(): ?string {
		$url       = $this->url;
		$this->url = null;
		return $url;
	}
}
