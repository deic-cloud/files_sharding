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
 * @method string getLockedBy()
 * @method void   setLockedBy(string $lockedBy)
 */
class DataFolder extends Entity {
	public string $userId          = '';
	public string $folder          = '';
	public string $onlyFrom        = '';
	public bool   $hideFromClients = false;
	public string $lockedBy        = '';

	public function __construct() {
		$this->addType('userId',          Types::STRING);
		$this->addType('folder',          Types::STRING);
		$this->addType('onlyFrom',        Types::STRING);
		$this->addType('hideFromClients', Types::BOOLEAN);
		$this->addType('lockedBy',        Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id'                => $this->id,
			'user_id'           => $this->userId,
			'folder'            => $this->folder,
			'only_from'         => $this->onlyFrom,
			'hide_from_clients' => $this->hideFromClients,
			'locked_by'         => $this->lockedBy,
		];
	}
}
