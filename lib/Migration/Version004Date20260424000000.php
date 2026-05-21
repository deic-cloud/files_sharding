<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make cross-silo federated shares appear under "Internal shares" in the
 * Sharing tab, since users are not expected to know or care which silo their
 * peers are on. Requires that the sharer search returns isTrustedServer=true
 * for cross-silo peers (already done in MasterUserSearch).
 */
class Version004Date20260424000000 extends SimpleMigrationStep {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->appConfig->setValueBool('files_sharing', 'show_federated_shares_to_trusted_servers_as_internal', true);
	}
}
