<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `target_group` to oc_share_external so the master can hold GROUP shares in
 * the same table it already uses for federated user shares (see README, "Sharing
 * model"): one registry row per group share, keyed by the group it targets, which
 * the resolver expands per member on demand. Empty for ordinary user shares.
 *
 * NOTE: the ScienceData deploy applies app updates by file copy + fpm reload, which
 * does NOT run migrations — so this column must also be added via SQL / baked into
 * firstboot on existing clusters. This migration covers fresh app-store installs.
 */
class Version007Date20260817000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('share_external')) {
			return null;
		}

		$t = $schema->getTable('share_external');
		if (!$t->hasColumn('target_group')) {
			$t->addColumn('target_group', Types::STRING, [
				'notnull' => false,
				'length'  => 255,
				'default' => '',
			]);
		}

		return $schema;
	}
}
