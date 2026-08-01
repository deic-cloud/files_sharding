<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

/** Thrown by AdminIpMiddleware to bounce an admin coming from a non-whitelisted IP. */
class AdminIpBlockedException extends \Exception {
}
