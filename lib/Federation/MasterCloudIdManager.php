<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Federation;

use OCA\FilesSharding\Service\ShardingService;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Federation\ICloudIdResolver;
use OCP\IConfig;

/**
 * Decorates the core ICloudIdManager so that, ON SILOS, a local user's federated
 * cloud ID is built against the MASTER host instead of the silo's own host.
 *
 * Why: a user's canonical federation identity must be STABLE across silo moves —
 * external collaborators' shares (in and out) are recorded against it, so a silo
 * hostname baked into the ID breaks the moment the user is reassigned. The core
 * manager derives the host from THIS instance's own URL (see
 * \OC\Federation\CloudIdManager::getCloudId), which on a silo leaks the silo host.
 * Here we substitute the master host for the "local user" case and delegate
 * everything else verbatim.
 *
 * This is a DI-level service override (files_sharding re-binds ICloudIdManager in
 * Application::register), NOT a core patch: it depends only on the public OCP
 * interface and delegates to the real manager, so it stays upgrade-safe. The one
 * path it CANNOT reach is \OC\User\User::getCloudId(), which computes the host
 * directly and bypasses this service (Settings display, CalDAV federation) — those
 * are cosmetic/deferred and handled separately.
 */
class MasterCloudIdManager implements ICloudIdManager {
	public function __construct(
		private ICloudIdManager $inner,
		private ShardingService $sharding,
		private IConfig $config,
	) {
	}

	public function getCloudId(string $user, ?string $remote): ICloudId {
		// Only silos rewrite; on the master the own host IS the canonical host.
		if (!$this->sharding->isMaster()) {
			$master = $this->sharding->masterUrl();
			if ($master !== '' && $this->isLocalRemote($remote)) {
				return $this->inner->getCloudId($user, $master);
			}
		}
		return $this->inner->getCloudId($user, $remote);
	}

	/** True when $remote denotes THIS instance: null, or same host:port as our own URL. */
	private function isLocalRemote(?string $remote): bool {
		if ($remote === null) {
			return true;
		}
		$own = (string)$this->config->getSystemValue('overwrite.cli.url', '');
		return $own !== '' && $this->authority($remote) === $this->authority($own);
	}

	private function authority(string $url): string {
		$p = parse_url($url);
		return strtolower(($p['host'] ?? '') . ':' . ($p['port'] ?? ''));
	}

	// ── everything below is verbatim delegation to the real manager ──────────

	public function resolveCloudId(string $cloudId): ICloudId {
		return $this->inner->resolveCloudId($cloudId);
	}

	public function isValidCloudId(string $cloudId): bool {
		return $this->inner->isValidCloudId($cloudId);
	}

	public function removeProtocolFromUrl(string $url, bool $httpsOnly = false): string {
		return $this->inner->removeProtocolFromUrl($url, $httpsOnly);
	}

	public function createCloudId(string $id, string $user, string $remote, ?string $displayName = null): ICloudId {
		return $this->inner->createCloudId($id, $user, $remote, $displayName);
	}

	public function registerCloudIdResolver(ICloudIdResolver $resolver): void {
		$this->inner->registerCloudIdResolver($resolver);
	}

	public function unregisterCloudIdResolver(ICloudIdResolver $resolver): void {
		$this->inner->unregisterCloudIdResolver($resolver);
	}

	/**
	 * The concrete \OC\Federation\CloudIdManager exposes public methods beyond the
	 * ICloudIdManager interface — notably getDisplayNameFromContact(), which CloudId
	 * value objects call back into for lazy display-name resolution, plus the
	 * UserChanged/CardUpdated event handlers. Forward any method we don't explicitly
	 * override to the real manager so those callbacks keep working.
	 *
	 * @param array<mixed> $arguments
	 */
	public function __call(string $name, array $arguments): mixed {
		return $this->inner->$name(...$arguments);
	}
}
