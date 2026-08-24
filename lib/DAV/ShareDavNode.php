<?php

declare(strict_types=1);

namespace OCA\FilesSharding\DAV;

use OCP\Files\Node;

/**
 * Common surface of ShareFile/ShareDirectory (the /sharingin //sharingout DAV
 * wrappers) — what SharesPropsPlugin needs to serve etags and oc: props.
 *
 * NOTE the split into TWO classes is load-bearing: Sabre decides
 * {DAV:}resourcetype by `instanceof ICollection`, so a single class
 * implementing both ICollection and IFile reports EVERY node — files
 * included — as a collection, and the sync client then creates folders in
 * place of files (observed: hello.txt arrived as an empty directory).
 */
interface ShareDavNode {
	public function getNode(): Node;

	public function getETag(): string;

	public function isShareRoot(): bool;

	public function getName(): string;
}
