<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int    getId()
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getFolder()
 * @method void   setFolder(string $folder)
 * @method string getOnlyFrom()
 * @method void   setOnlyFrom(string $onlyFrom)
 * @method bool   getHideFromClients()
 * @method void   setHideFromClients(bool $hideFromClients)
 */
class DataFolder extends Entity {
	public string $userId          = '';
	public string $folder          = '';
	public string $onlyFrom        = '';
	public bool   $hideFromClients = false;

	public function __construct() {
		$this->addType('userId',          Types::STRING);
		$this->addType('folder',          Types::STRING);
		$this->addType('onlyFrom',        Types::STRING);
		$this->addType('hideFromClients', Types::BOOLEAN);
	}

	public function jsonSerialize(): array {
		return [
			'id'                => $this->id,
			'user_id'           => $this->userId,
			'folder'            => $this->folder,
			'only_from'         => $this->onlyFrom,
			'hide_from_clients' => $this->hideFromClients,
		];
	}
}
