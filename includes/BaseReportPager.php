<?php

namespace MediaWiki\Extension\PageQuality;

use DateTime;
use Exception;
use FormOptions;
use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Title\TitleFactory;
use TablePager;

/**
 * Abstract base class for PageQuality report pagers.
 * Provides shared infrastructure for querying and rendering quality reports.
 */
abstract class BaseReportPager extends TablePager {

	/** @var LinkRenderer */
	protected LinkRenderer $linkRenderer;
	/** @var string */
	protected string $report_type;
	/** @var FormOptions */
	protected FormOptions $opts;
	/** @var TitleFactory */
	protected TitleFactory $titleFactory;

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param FormOptions $opts
	 * @param string $report_type
	 * @param TitleFactory $titleFactory
	 */
	public function __construct(
		IContextSource $context, LinkRenderer $linkRenderer, FormOptions $opts, string $report_type,
		TitleFactory $titleFactory
	) {
		$this->report_type = $report_type;

		parent::__construct( $context );

		$this->opts = $opts;
		$this->linkRenderer = $linkRenderer;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * Build a query that compares two pq_score_log entries for the same page,
	 * selecting the earliest new entry vs. the latest old entry within a date range.
	 *
	 * @param string|null $from_date
	 * @param string|null $to_date
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getScoreLogQuery( ?string $from_date = null, ?string $to_date = null ): array {
		$info = [
			'tables' => [ 'log1' => 'pq_score_log', 'log2' => 'pq_score_log', 'page' ],
			'fields' => [
				'page.page_id', 'page.page_namespace', 'page.page_title',
				'new_score' => 'log1.new_score', 'timestamp' => 'log1.timestamp',
				'old_score' => 'log2.old_score', 'log2.timestamp',
				'new_status' => 'log1.new_status', 'old_status' => 'log2.old_status'
			],
			// @fixme this doesn't necessarily select the correct log entry.
			// It should always select the max and min entry
			'join_conds' => [
				'log2' => [ 'LEFT JOIN', 'log1.page_id = log2.page_id' ],
				'page' => [ 'INNER JOIN', 'log1.page_id = page.page_id' ]
			],
			'options' => [
				'ORDER BY' => [ 'log1.timestamp ASC', 'log2.timestamp DESC' ],
				'GROUP BY' => 'page.page_id',
			]
		];

		$dateConditions = array_merge(
			$this->getConditionLimitByDates( 'log1.timestamp', $from_date, $to_date ),
			$this->getConditionLimitByDates( 'log2.timestamp', $from_date, $to_date )
		);
		$info['conds'] = isset( $info['conds'] ) ? array_merge( $info['conds'], $dateConditions ) : $dateConditions;

		return $info;
	}

	/**
	 * Build WHERE conditions to restrict a timestamp field to a date range.
	 *
	 * @param string $fieldName
	 * @param DateTime|string|null $from
	 * @param DateTime|string|null $to
	 *
	 * @return array
	 *
	 * @throws Exception
	 */
	public function getConditionLimitByDates( string $fieldName, $from = null, $to = null ): array {
		$db = $this->getDatabase();
		$conds = [];
		if ( !empty( $from ) ) {
			$conds[] = $fieldName . ' >= ' . $db->addQuotes( $db->timestamp( new DateTime( $from ) ) );
		}
		if ( !empty( $to ) ) {
			// Add 1 day, so we check for "any date before tomorrow"
			$to = $db->timestamp( new DateTime( $to . ' +1 day' ) );
			$conds[] = $fieldName . ' < ' . $db->addQuotes( $to );
		}

		return $conds;
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return '';
	}
}

