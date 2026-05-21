<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001Date20260421000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_sharding_servers')) {
			$t = $schema->createTable('files_sharding_servers');
			$t->addColumn('id',           Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('url',          Types::STRING,  ['notnull' => true,  'length' => 256]);
			$t->addColumn('internal_url', Types::STRING,  ['notnull' => false, 'length' => 256, 'default' => '']);
			$t->addColumn('x509_dn',      Types::STRING,  ['notnull' => false, 'length' => 256, 'default' => '']);
			$t->addColumn('site',         Types::STRING,  ['notnull' => false, 'length' => 64,  'default' => '']);
			$t->addColumn('description',  Types::STRING,  ['notnull' => false, 'length' => 256, 'default' => '']);
			$t->addColumn('total_gb',     Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$t->addColumn('free_gb',      Types::INTEGER, ['notnull' => false, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['url'], 'fsh_servers_url');
			$t->addIndex(['site'],      'fsh_servers_site');
		}

		if (!$schema->hasTable('files_sharding_user_servers')) {
			$t = $schema->createTable('files_sharding_user_servers');
			$t->addColumn('id',        Types::INTEGER,  ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id',   Types::STRING,   ['notnull' => true, 'length' => 255]);
			$t->addColumn('server_id', Types::INTEGER,  ['notnull' => true]);
			$t->addColumn('access',    Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id'],            'fsh_user_servers_uid');
			$t->addIndex(['server_id'],                'fsh_user_servers_sid');
		}

		if (!$schema->hasTable('files_sharding_folders')) {
			$t = $schema->createTable('files_sharding_folders');
			$t->addColumn('id',        Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id',   Types::STRING,  ['notnull' => true, 'length' => 255]);
			$t->addColumn('folder',    Types::STRING,  ['notnull' => true, 'length' => 512]);
			$t->addColumn('only_from', Types::STRING,  ['notnull' => false, 'length' => 512, 'default' => '']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'],          'fsh_folders_uid');
			$t->addUniqueIndex(['user_id', 'folder'], 'fsh_folders_uid_path');
		}

		return $schema;
	}
}
