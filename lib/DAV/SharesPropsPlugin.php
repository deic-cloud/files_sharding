<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\File;
use OCP\Files\FileInfo;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Sync-client properties for the /sharingin //sharingout DAV trees.
 *
 * Sabre's core only serves {DAV:}getetag for files (IFile::getETag); it never
 * etags collections, and it knows nothing of the oc:/nc: namespace. The NC
 * desktop client REQUIRES an etag on every collection to track changes — a
 * folder whose getetag prop comes back 404 is reported as "Folder is not
 * accessible on the server" and the sync aborts (observed with client 3.x
 * against /sharingin). It also wants oc:id/oc:fileid (move detection),
 * oc:permissions (read-only vs writable decisions) and oc:size.
 *
 * ShareFile/ShareDirectory wrap a real OCP node → serve the node's own etag/fileid/perms.
 * OwnerCollection and the root are STRUCTURAL (no backing file) → synthesize:
 * their etag is the md5 of their children's etags, so a change anywhere below
 * bubbles up and depth-0 polling works; their id is a stable hash of the path.
 */
class SharesPropsPlugin extends ServerPlugin {
	private string $instanceId;

	public function __construct(string $instanceId) {
		$this->instanceId = $instanceId;
	}

	public function initialize(Server $server): void {
		$server->on('propFind', [$this, 'propFind']);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		if ($node instanceof ShareDavNode) {
			$fileNode = $node->getNode();
			$propFind->handle('{DAV:}getetag', fn () => $node->getETag());
			$propFind->handle('{http://owncloud.org/ns}fileid', fn () => (string)$fileNode->getId());
			$propFind->handle('{http://owncloud.org/ns}id',
				fn () => sprintf('%08d', $fileNode->getId()) . $this->instanceId);
			$propFind->handle('{http://owncloud.org/ns}size', fn () => (string)$fileNode->getSize());
			$propFind->handle('{http://owncloud.org/ns}permissions', fn () => $this->davPermissions($node));
			return;
		}

		if ($node instanceof OwnerCollection || $node instanceof SharesRootCollection) {
			$propFind->handle('{DAV:}getetag', fn () => $this->syntheticEtag($node));
			$propFind->handle('{http://owncloud.org/ns}fileid', fn () => $this->syntheticId($node));
			$propFind->handle('{http://owncloud.org/ns}id', fn () => $this->syntheticId($node));
			// Structural containers: readable, nothing else (shares can't be
			// created by MKCOL/PUT here).
			$propFind->handle('{http://owncloud.org/ns}permissions', fn () => 'G');
		}
	}

	/**
	 * NC-style permission letters from the wrapped node's permission mask.
	 * S/R (shared/shareable) omitted — share management doesn't happen on this
	 * surface. 'M' (mounted/external) DELIBERATELY omitted everywhere: the
	 * desktop client's default "confirm external storages" setting silently
	 * EXCLUDES M-flagged folders from sync until manually confirmed — exactly
	 * the green-but-empty failure observed. This endpoint presents shares as
	 * plain directories, like the old service did.
	 */
	private function davPermissions(ShareDavNode $node): string {
		$fileNode = $node->getNode();
		$mask = $fileNode->getPermissions();
		$isFile = $fileNode instanceof File;
		$p = '';
		if ($mask & \OCP\Constants::PERMISSION_READ) {
			$p .= 'G';
		}
		if (!$isFile && ($mask & \OCP\Constants::PERMISSION_CREATE)) {
			$p .= 'CK';
		}
		if ($isFile && ($mask & \OCP\Constants::PERMISSION_UPDATE)) {
			$p .= 'W';
		}
		// Share roots refuse rename/delete on this surface (ShareFile/ShareDirectory guards).
		if (!$node->isShareRoot()) {
			if ($mask & \OCP\Constants::PERMISSION_DELETE) {
				$p .= 'D';
			}
			if ($mask & \OCP\Constants::PERMISSION_UPDATE) {
				$p .= 'NV';
			}
		}
		return $p;
	}

	private function syntheticEtag(INode $node): string {
		$parts = [];
		foreach ($node->getChildren() as $child) {
			if ($child instanceof ShareDavNode) {
				$parts[] = $child->getName() . ':' . $child->getETag();
			} elseif ($child instanceof OwnerCollection) {
				$parts[] = $child->getName() . ':' . $this->syntheticEtag($child);
			}
		}
		return '"' . md5(implode('|', $parts)) . '"';
	}

	private function syntheticId(INode $node): string {
		return 'fsh-' . md5(get_class($node) . ':' . $node->getName()) . $this->instanceId;
	}
}
