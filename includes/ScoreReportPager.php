<?php

namespace MediaWiki\Extension\PageQuality;

use DateTime;
use Exception;
use ExtensionRegistry;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;

/**
 * Pager for the per-page score report: shows current scores alongside the
 * oldest recorded score within the requested date range.
 *
 * Previously named ReportPager.
 */
class ScoreReportPager extends BaseReportPager {

	/**
	 * @inheritDoc
	 */
	public function getFieldNames() {
		static $headers = null;

		Scorer::loadAllScoreres();
		$all_checklist = Scorer::getAllChecksList();

		$headers = [
			'pagename' => 'pq_report_pagename',
			'score' => 'pq_report_page_score',
			// 'score_old' => 'pq_report_page_score_old',
			'status' => 'pq_report_page_status',
			'old_score' => 'pq_report_page_score_old',
			'old_status' => 'pq_report_page_status_old',
		];
		$headers["timestamp"] = "pq_report_page_score_timestamp";
		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	/**
	 * It seems we totally ignore this function and use formatValueMy() in formatRow().
	 * That seems wrong, but what do I know.
	 * This function must still be implemented, even empty.
	 *
	 * @todo clean up this mess
	 *
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ) {
		$formatted = '';
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'pagename':
				$formatted = $this->linkRenderer->makeKnownLink( $this->titleFactory->newFromRow( $row ) );
				break;
			case 'timestamp':
				if ( !empty( $value ) ) {
					$formatted = ( new DateTime() )->setTimestamp( wfTimestamp( TS_UNIX, $value ) )
						->format( 'j M y' );
				}
				break;
			case 'score':
				$score = !empty( $row->new_score ) ? $row->new_score : $row->score;
				$formatted = $score;
				break;
			case 'old_status':
			case 'status':
				$status = Scorer::getHumanReadableStatus( $value );
				$formatted = $this->msg( 'pq_report_page_status_' . $status )->escaped();
				break;
			default:
				$formatted = $value;
		}

		return $formatted;
	}

	/**
	 * @inheritDoc
	 * @throws Exception
	 */
	public function getQueryInfo(): array {
		$from_date = $this->opts->getValue( 'from_date' );
		$to_date = $this->opts->getValue( 'to_date' );

		// We also join on the page table, so that deleted pages do not show
		// While doing that, we get enough fields for Title::newFromRow()
		$info = [
			'tables' => [ 'pq_score', 'pq_score_log', 'page' ],
			'fields' => [
				'page.page_id', 'page.page_namespace', 'page.page_title',
				'pq_score.score', 'pq_score.status', 'old_score', 'pq_score_log.old_status',
				'timestamp' => 'MAX(pq_score_log.timestamp)'
			],
			'conds' => $this->getConditionLimitByDates( 'pq_score_log.timestamp', $from_date, $to_date ),
			// @fixme this doesn't necessarily select the correct log entry.
			// It should always select the max and min entry
			'join_conds' => [
				"pq_score_log" => [
					'LEFT JOIN',
					[ 'pq_score.page_id = pq_score_log.page_id', 'pq_score.score = pq_score_log.new_score' ]
				],
				'page' => [ 'INNER JOIN', [ 'pq_score.page_id = page.page_id' ] ]
			],
			'options' => [ 'GROUP BY' => "page.page_id" ]
		];

		switch ( $this->report_type ) {
			case "all":
				break;
			case "red_all":
				$info['conds']['pq_score.status'] = Scorer::RED;
				break;
			case "yellow_all":
				$info['conds']['pq_score.status'] = Scorer::YELLOW;
				break;
			case "green_all":
				$info['conds']['pq_score.status'] = Scorer::GREEN;
				break;
			default:
				$info['tables'][] = 'pq_issues';
				$info['join_conds']['pq_issues'] = [
					'JOIN', [ 'pq_score.page_id = pq_issues.page_id', 'pq_type' => $this->report_type ]
				];
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) &&
			 !empty( $this->opts->getValue( 'article_content_type' ) )
		) {
			$info = array_merge_recursive(
				$info,
				ArticleContentArea::getJoin( $this->opts->getValue( 'article_content_type' ), 'page.page_id' )
			);
		}
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) &&
			 !empty( $this->opts->getValue( 'article_type' ) )
		) {
			$info = array_merge_recursive(
				$info, ArticleType::getJoin( $this->opts->getValue( 'article_type' ), 'page.page_id' )
			);
		}

		return $info;
	}

	/** @inheritDoc */
	public function getIndexField() {
		return 'pq_score.score';
	}

	/**
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ): bool {
		return ( $field === 'score' );
	}
}
