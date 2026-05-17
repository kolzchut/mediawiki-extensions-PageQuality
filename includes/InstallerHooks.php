<?php

namespace MediaWiki\Extension\PageQuality;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Installer-time hook handlers. Kept separate from {@see Hooks} because
 * LoadExtensionSchemaUpdates fires before the service container is
 * wired, and HookContainer rejects handlers declaring `services:` for
 * such hooks since ~MW 1.41.
 */
class InstallerHooks implements LoadExtensionSchemaUpdatesHook {
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = __DIR__ . '/../sql';

		$updater->addExtensionTable( 'pq_issues', "$dir/pq_issues.sql" );
		$updater->addExtensionTable( 'pq_settings', "$dir/pq_settings.sql" );
		$updater->addExtensionTable( 'pq_score', "$dir/pq_score.sql" );
		$updater->addExtensionTable( 'pq_score_log', "$dir/pq_score_log.sql" );
		$updater->addExtensionField( 'pq_settings', 'value_blob', "$dir/pq_settings_patch_add_value_blob.sql" );

		// Change the timestamp field to MediaWiki's binary(14). This requires creating a new field,
		// converting all values into it using a maintenance script, and then dropping the old field
		// and renaming the new field
		$updater->addExtensionField( 'pq_score_log', 'timestamp2', "$dir/pq_score_log_new_timestamp_2022-08-18.sql" );

		/*
		$updater->addExtensionUpdate( [
			'runMaintenance',
			MigrateTimestampToMWFormat::class,
			"$dir/../maintenance/PostDatabaseUpdate/migrateTimestampToMWFormat.php"
		] );
		 */
		$updater->modifyExtensionField(
			'pq_score_log', 'timestamp', "$dir/pq_score_log_drop_old_timestamp_2022-08-18.sql"
		);
		$updater->addExtensionField( 'pq_score', 'status', "$dir/pq_score_patch_add_status.2024-05-19.sql" );
		$updater->addExtensionField( 'pq_score_log', 'new_status', "$dir/pq_score_log_add_new_status_2024-05-19.sql" );
		$updater->addExtensionField( 'pq_score_log', 'old_status', "$dir/pq_score_log_add_old_status_2024-05-19.sql" );

		$updater->addExtensionUpdate( [
			'runMaintenance',
			fixScoreLogAfterAddingStatus::class,
			"$dir/../maintenance/PostDatabaseUpdate/fixScoreLogAfterAddingStatus.php"
		] );
	}
}
