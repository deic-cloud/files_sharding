<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002Date20260422000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_sharding_tokens')) {
			$t = $schema->createTable('files_sharding_tokens');
			$t->addColumn('id',         Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token',      Types::STRING,  ['notnull' => true, 'length' => 64]);
			$t->addColumn('user_id',    Types::STRING,  ['notnull' => true, 'length' => 255]);
			$t->addColumn('expires_at', Types::BIGINT,  ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token'],      'fsh_tokens_token');
			$t->addIndex(['expires_at'],       'fsh_tokens_expires');
		}

		return $schema;
	}
}
