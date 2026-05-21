<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003Date20260423000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('files_sharding_servers');
		if (!$table->hasColumn('user_regex')) {
			$table->addColumn('user_regex', Types::STRING, [
				'notnull' => false,
				'length'  => 512,
				'default' => '',
			]);
		}

		return $schema;
	}
}
