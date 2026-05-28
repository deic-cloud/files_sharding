<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006Date20260527000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('files_sharding_folders')) {
			return null;
		}

		$t = $schema->getTable('files_sharding_folders');
		if (!$t->hasColumn('locked_by')) {
			$t->addColumn('locked_by', Types::STRING, [
				'notnull' => false,
				'length'  => 128,
				'default' => '',
			]);
		}

		return $schema;
	}
}
