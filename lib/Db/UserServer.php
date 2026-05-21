<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int    getId()
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getServerId()
 * @method void   setServerId(int $serverId)
 * @method int    getAccess()
 * @method void   setAccess(int $access)
 */
class UserServer extends Entity {
	public const ACCESS_READWRITE = 0;
	public const ACCESS_READONLY  = 1;

	public string $userId   = '';
	public int    $serverId = 0;
	public int    $access   = self::ACCESS_READWRITE;

	public function __construct() {
		$this->addType('userId',   Types::STRING);
		$this->addType('serverId', Types::INTEGER);
		$this->addType('access',   Types::INTEGER);
	}

	public function jsonSerialize(): array {
		return [
			'user_id'   => $this->userId,
			'server_id' => $this->serverId,
			'access'    => $this->access,
		];
	}
}
