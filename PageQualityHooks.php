<?php

use MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate\MigrateTimestampToMWFormat;
use MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate\fixScoreLogAfterAddingStatus;
use MediaWiki\MediaWikiServices;

class PageQualityHooks {

	/**
	 * @param WikiPage $wikiPage
	 * @param \MediaWiki\User\UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
	 * @param \MediaWiki\Storage\EditResult $editResult
	 *
	 * @return void
	 * @throws MWException
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage, MediaWiki\User\UserIdentity $user, string $summary, int $flags,
		MediaWiki\Revision\RevisionRecord $revisionRecord, MediaWiki\Storage\EditResult $editResult
	) {
		// @todo Check for null edits
		if ( PageQualityScorer::isPageScoreable( $wikiPage->getTitle() ) ) {
			list( $score, $responses ) = PageQualityScorer::runScorerForPage( $wikiPage->getTitle() );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 *
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( $permissionManager->userHasRight( $out->getUser(), 'viewpagequality' ) ) {
			if ( PageQualityScorer::isPageScoreable( $out->getTitle() ) ) {
				list( $score, $responses ) = PageQualityScorer::getScorForPage( $out->getTitle() );

				$link = Html::rawElement(
					'a',
					[
						'href' => '#',
						'data-target' => '#pagequality-sidebar'
					],
					$out->msg( 'pq_quality_score_link' )->escaped(
					) . ' <span class="badge">' . $score . '</span>'
				);

				$out->setIndicators( [ 'pq_status' => $link ] );
				$out->addModules( 'ext.page_quality' );
			}
		}
	}

	/**
	 * LoadExtensionSchemaUpdate Hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdate
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdate( DatabaseUpdater $updater ) {
		$dir = __DIR__ . '/sql';

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
