<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cross-silo GROUP sharing, delivery model A (per-member federated fan-out).
 *
 * A group share is delivered by minting one real federated (TYPE_REMOTE) child
 * share `owner→member@master` per REMOTE member, which rides the proven user
 * federated path (native mount, proper token — no public link). Federated shares
 * do NOT persist a label or attributes (see FederatedShareProvider::addShareToDB),
 * so we cannot tag the children on oc_share itself. This table is the reliable
 * marker: it records each fan-out child so reconcile can diff desired-vs-existing
 * (self-healing, no stray shares) and the owner's UI can hide the children (they
 * collapse into the single "shared with <group>" row).
 *
 * One row per (group_share_id, recipient). Local to each owner node.
 */
class Version008Date20260818000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('files_sharding_group_fanout')) {
			return null;
		}

		$table = $schema->createTable('files_sharding_group_fanout');
		$table->addColumn('id', 'bigint', [
			'autoincrement' => true,
			'notnull'       => true,
			'length'        => 20,
		]);
		$table->addColumn('gid', 'string', ['notnull' => true, 'length' => 255]);
		// The parent TYPE_GROUP oc_share.id on this node.
		$table->addColumn('group_share_id', 'bigint', ['notnull' => true, 'length' => 20]);
		// fileid of the shared node (for orphan/path correlation).
		$table->addColumn('node_id', 'bigint', ['notnull' => true, 'length' => 20, 'default' => 0]);
		// Recipient cloud id (member@master-host).
		$table->addColumn('recipient', 'string', ['notnull' => true, 'length' => 255]);
		// The created federated (TYPE_REMOTE) oc_share.id — the child to hide/prune.
		$table->addColumn('remote_share_id', 'bigint', ['notnull' => true, 'length' => 20]);
		$table->addColumn('owner', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['gid'], 'fs_gfo_gid');
		$table->addIndex(['group_share_id'], 'fs_gfo_gshare');
		$table->addIndex(['remote_share_id'], 'fs_gfo_remote');
		$table->addIndex(['owner'], 'fs_gfo_owner');

		return $schema;
	}
}
