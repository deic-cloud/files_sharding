<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop files_sharding_group_shares — the GroupShareRegistry table.
 *
 * It backed the abandoned "master-authority + derived-cache" group-share model
 * (a single companion link-token per group share, resolved on read). Delivery
 * model A (per-member federated fan-out) superseded it: group shares now arrive
 * as ordinary per-member federated rows, so exportExternalShares stopped reading
 * the registry and nothing writes it any more. The table is dead weight; remove it
 * along with GroupShareRegistry + its register/deregister endpoints.
 */
class Version009Date20260823000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_sharding_group_shares')) {
			return null;
		}
		$schema->dropTable('files_sharding_group_shares');
		return $schema;
	}
}
