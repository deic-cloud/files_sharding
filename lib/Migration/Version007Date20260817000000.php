<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `files_sharding_group_shares` — the master's authoritative registry of
 * GROUP shares (one row per group share; the resolver expands it per member on
 * demand). See README, "Sharing model".
 *
 * NOTE: this deliberately does NOT touch oc_share_external. Core's ExternalShareMapper
 * does `SELECT *` from that table into a strict ExternalShare Entity, so any extra
 * column there throws "… is not a valid attribute" and breaks core's OCM unshare
 * handling — hence a dedicated, files_sharding-owned table that core never reads.
 *
 * The ScienceData deploy copies files + reloads fpm and does NOT run migrations, so
 * on existing clusters this table is created via SQL / baked into firstboot; this
 * migration covers fresh app-store installs.
 */
class Version007Date20260817000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_sharding_group_shares')) {
			return null;
		}

		$t = $schema->createTable('files_sharding_group_shares');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$t->addColumn('gid', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
		$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
		$t->addColumn('owner_url', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => '']);
		$t->addColumn('share_token', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 4000, 'default' => '']);
		$t->addColumn('remote_id', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
		$t->addColumn('permissions', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['gid'], 'fs_gshares_gid');

		return $schema;
	}
}
