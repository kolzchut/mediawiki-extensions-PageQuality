<?php

namespace MediaWiki\Extension\PageQuality;

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Skin;
use WikiPage;

class Hooks implements BeforePageDisplayHook, PageSaveCompleteHook {

	public function __construct(
		private readonly PermissionManager $permissionManager,
	) {
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param \MediaWiki\User\UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
	 * @param \MediaWiki\Storage\EditResult $editResult
	 *
	 * @return void
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	): void {
		// @todo Check for null edits
		if ( Scorer::isPageScoreable( $wikiPage->getTitle() ) ) {
			Scorer::runScorerForPage( $wikiPage->getTitle() );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 *
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $this->permissionManager->userHasRight( $out->getUser(), 'viewpagequality' ) ) {
			if ( Scorer::isPageScoreable( $out->getTitle() ) ) {
				[ $score ] = Scorer::getScorForPage( $out->getTitle() );

				$link = Html::rawElement(
					'a',
					[
						'href' => '#',
						'data-target' => '#pagequality-sidebar'
					],
					$out->msg( 'pq_quality_score_link' )->escaped() . ' <span class="badge">' . $score . '</span>'
				);

				$out->setIndicators( [ 'pq_status' => $link ] );
				$out->addModules( 'ext.page_quality' );
			}
		}
	}

}
