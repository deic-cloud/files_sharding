<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Event;

use OCP\EventDispatcher\Event;

/**
 * A federated share from inside the cluster has just been mirrored onto this
 * silo for one of its users, and auto-accepted — i.e. the mount now exists in
 * that user's tree at $mountPoint.
 *
 * Core has no event for this: the mount is created by our own mirror of the
 * master's share table (ShareSyncService), not by a user accepting anything.
 * Apps that care where a received folder lands — markdown_notes puts a shared
 * notebook into the recipient's Notes folder — listen for this instead of
 * polling. The share's own node is the only place that knows the recipient's
 * conventions (their notes folder name is per-user, per-instance config), which
 * is why the decision belongs here and not on the sharing side.
 */
class ExternalShareMountedEvent extends Event {
	public function __construct(
		private string $userId,
		private string $mountPoint,
		private string $remote,
		private string $owner,
	) {
		parent::__construct();
	}

	public function getUserId(): string {
		return $this->userId;
	}

	/** Mount point relative to the user's files root, e.g. "GroupLab". */
	public function getMountPoint(): string {
		return trim($this->mountPoint, '/');
	}

	public function getRemote(): string {
		return $this->remote;
	}

	public function getOwner(): string {
		return $this->owner;
	}
}
