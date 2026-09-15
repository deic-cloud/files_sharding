<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

/** Thrown by TransferOwnershipMiddleware for a recipient homed on another node. */
class CrossNodeTransferException extends \Exception {
	public function __construct(string $recipient) {
		parent::__construct('Ownership transfer to ' . $recipient . ' refused: recipient is not homed on this node.');
	}
}
