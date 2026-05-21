<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version005Date20260425000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('files_sharding_folders');
		if (!$table->hasColumn('hide_from_clients')) {
			$table->addColumn('hide_from_clients', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		}
		return $schema;
	}
}
