<?php

namespace MediaWiki\Extension\PageQuality;

use ErrorPageError;
use Exception;
use ExtensionRegistry;
use FormOptions;
use IContextSource;
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Title\TitleFactory;

/**
 * Pager for score-change reports (improvements and declines):
 * compares earliest vs. latest pq_score_log entries for each page within the
 * requested date range and filters by whether the status improved or declined.
 *
 * Previously named ChangesReportPager.
 */
class ScoreChangesReportPager extends BaseReportPager {

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param FormOptions $opts
	 * @param string $report_type Must be 'declines' or 'improvements'.
	 * @param TitleFactory $titleFactory
	 * @throws ErrorPageError
	 */
	public function __construct(
		IContextSource $context, LinkRenderer $linkRenderer, FormOptions $opts, string $report_type,
		TitleFactory $titleFactory
	) {
		if ( !in_array( $report_type, [ 'declines', 'improvements' ] ) ) {
			throw new ErrorPageError( 'pq_reports', 'pq_report_error_no_report' );
		}

		parent::__construct( $context, $linkRenderer, $opts, $report_type, $titleFactory );
	}

	/**
	 * @inheritDoc
	 */
	public function getFieldNames() {
		static $headers = null;

		if ( $headers == [] ) {
			$headers = [
				'pagename' => 'pq_report_pagename',
				'status' => 'pq_report_page_status',
				'old_status' => 'pq_report_page_status_old',
				'new_score' => 'pq_report_page_score',
				'old_score' => 'pq_report_page_score_old'
			];
			foreach ( $headers as &$msg ) {
				$msg = $this->msg( $msg )->text();
			}
		}

		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ) {
		switch ( $name ) {
			case 'pagename':
				$formatted = $this->linkRenderer->makeKnownLink(
					$this->titleFactory->newFromRow( $this->mCurrentRow )
				);
				break;
			case 'status':
			case 'old_status':
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
		$info = $this->getScoreLogQuery( $from_date, $to_date );

		$havingCond = ( $this->report_type === 'declines' ) ? "new_status > old_status" : "new_status < old_status";
		$info['options']['HAVING'] = $havingCond;

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
		return [ 'time' => [ 'log1.timestamp ASC', 'log2.timestamp DESC' ] ];
	}

	/**
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ): bool {
		return ( $field === 'score' );
	}
}
