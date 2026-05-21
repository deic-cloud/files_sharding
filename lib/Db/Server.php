<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int    getId()
 * @method string getUrl()
 * @method void   setUrl(string $url)
 * @method string getInternalUrl()
 * @method void   setInternalUrl(string $internalUrl)
 * @method string getX509Dn()
 * @method void   setX509Dn(string $x509Dn)
 * @method string getSite()
 * @method void   setSite(string $site)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method int    getTotalGb()
 * @method void   setTotalGb(int $totalGb)
 * @method int    getFreeGb()
 * @method void   setFreeGb(int $freeGb)
 * @method string getUserRegex()
 * @method void   setUserRegex(string $userRegex)
 */
class Server extends Entity {
	public string $url         = '';
	public string $internalUrl = '';
	public string $x509Dn      = '';
	public string $site        = '';
	public string $description = '';
	public int    $totalGb     = 0;
	public int    $freeGb      = 0;
	public string $userRegex   = '';

	public function __construct() {
		$this->addType('url',         Types::STRING);
		$this->addType('internalUrl', Types::STRING);
		$this->addType('x509Dn',      Types::STRING);
		$this->addType('site',        Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('totalGb',     Types::INTEGER);
		$this->addType('freeGb',      Types::INTEGER);
		$this->addType('userRegex',   Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id'           => $this->id,
			'url'          => $this->url,
			'internal_url' => $this->internalUrl,
			'x509_dn'      => $this->x509Dn,
			'site'         => $this->site,
			'description'  => $this->description,
			'total_gb'     => $this->totalGb,
			'free_gb'      => $this->freeGb,
			'user_regex'   => $this->userRegex,
		];
	}
}
