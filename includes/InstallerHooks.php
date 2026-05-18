<?php

namespace MediaWiki\Extension\PageQuality;

use MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate\fixScoreLogAfterAddingStatus;
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

		// Change the timestamp field to MediaWiki's binary(14). The accompanying maintenance script
		// (maintenance/PostDatabaseUpdate/migrateTimestampToMWFormat.php) is intentionally not
		// registered as an auto-update — it iterates every pq_score_log row and is prohibitively
		// slow on large wikis. Run it manually via run.php if a wiki still needs the timestamp2
		// backfill.
		$updater->addExtensionField( 'pq_score_log', 'timestamp2', "$dir/pq_score_log_new_timestamp_2022-08-18.sql" );

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
