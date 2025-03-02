<?php

use MediaWiki\Revision\RevisionRecord;

abstract class PageQualityScorer {

	public const GREEN = 0;
	public const YELLOW = 1;
	public const RED = 2;

	/** @var array */
	protected static $registered_classes = [];
	/** @var array */
	protected static $settings = [];
	/** @var array */
	protected static $checksList = [];
	/** @var string */
	protected static $text = null;
	/** @var DOMDocument|null */
	protected static $dom = null;

	/** @var array */
	protected static $general_settings = [
		"red" => [
			"name" => "pag_scorer_red_score",
			"default" => 10,
		],
		"article_types" => [
			"name" => "pag_scorer_article_type",
			"data_type" => "list",
			"default" => "",
			"dependsOnExtension" => "ArticleType"
		],
	];

	/**
	 * @return mixed
	 */
	abstract public function calculatePageScore();

	/**
	 * This is a naive word counter, which pretty much ignores anything except spaces as word
	 * delimiters. It should work fine with utf-8 strings.
	 *
	 * @param string $text
	 *
	 * @return int|void
	 */
	protected static function str_word_count_utf8( $text ) {
		// We do the following because strtr just didn't work right in utf-8 text
		$replacements = "\n:,[]={}|*,";
		$replacements = str_split( $replacements );
		// Add the Arabic comma as well
		$replacements[] = '،';
		$text = str_replace( $replacements, ' ', $text );

		// Remove comments
		$text = preg_replace( '/<!--[\s\S]*?-->/', '', $text );
		// Remove single-character words
		$text = preg_replace( '/ . /', ' ', $text );
		// Replace any type of space with a simple single space
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return count( explode( " ", $text ) );
	}

	/**
	 * @return array
	 */
	public static function getGeneralSettings(): array {
		return static::$general_settings;
	}

	/**
	 * @return array
	 */
	public static function getCheckList(): array {
		$checkList = static::$checksList;
		$enabledChecks = [];
		foreach ( $checkList as $check => $value ) {
			if ( !isset( $value['disabled'] ) || $value['disabled'] !== true ) {
				$enabledChecks[$check] = $value;
			}
		}
		return $enabledChecks;
	}

	public static function loadAllScoreres() {
		if ( !empty( static::$registered_classes ) ) {
			return;
		}
		foreach ( glob( __DIR__ . "/scorers/*.php" ) as $filename ) {
			include_once $filename;
			self::$registered_classes[] = basename( $filename, '.php' );
		}
	}

	/**
	 * @param string $type
	 *
	 * @return array|false|mixed|string[]
	 */
	public static function getSetting( string $type ) {
		if ( array_key_exists( $type, self::getSettingValues() ) ) {
			$setting_value = self::$settings[$type];
		} elseif ( array_key_exists( $type, self::$general_settings ) ) {
			$setting_value = self::$general_settings[$type]['default'];
		} else {
			$setting_value = self::getCheckList()[$type]['default'];
		}

		if ( $setting_value ) {
			if ( self::isListSetting( $type ) ) {
				// explode by line endings
				$setting_value = preg_split( '/\R/', $setting_value );
			}
		}
		return $setting_value;
	}

	/**
	 * @param string $type
	 *
	 * @return bool
	 */
	protected static function isListSetting( string $type ): bool {
		return (
			isset( self::getCheckList()[$type][ 'data_type' ] ) &&
			self::getCheckList()[$type][ 'data_type' ] === 'list'
		) ||
		(
			isset( self::$general_settings[$type]['data_type' ] ) &&
			self::$general_settings[$type]['data_type' ] === 'list'
		);
	}

	/**
	 * @return array
	 */
	public static function getAllScorers(): array {
		if ( empty( self::$registered_classes ) ) {
			self::loadAllScoreres();
		}
		return self::$registered_classes;
	}

	/**
	 * @return array
	 */
	public static function getSettingValues(): array {
		if ( !empty( self::$settings ) ) {
			return self::$settings;
		}
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'pq_settings',
			'*',
			[ true ],
			__METHOD__
		);

		$all_checklist = self::getAllChecksList();
		self::$settings = [];
		foreach ( $res as $row ) {
			self::$settings[$row->setting] = $row->value;
			if ( array_key_exists( $row->setting, $all_checklist ) &&
				 array_key_exists( 'data_type', $all_checklist[$row->setting] ) &&
				 $all_checklist[$row->setting]['data_type'] === 'list'
			) {
				self::$settings[$row->setting] = $row->value_blob ?? null;
			}
		}
		return self::$settings;
	}

	/**
	 * @param Title $title
	 *
	 * @return bool
	 */
	public static function isPageScoreable( Title $title ): bool {
		$allowedNamespaces = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'PageQualityNamespaces' );

		if ( $title->isRedirect() ) {
			return false;
		}

		if ( !in_array( $title->getNamespace(), $allowedNamespaces ) ) {
			return false;
		}

		$relevantArticleTypes = self::getSetting( 'article_types' );
		if ( !empty( $relevantArticleTypes ) && ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleType = \MediaWiki\Extension\ArticleType\ArticleType::getArticleType( $title );
			if ( !in_array( $articleType, $relevantArticleTypes ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $text
	 *
	 * @return DOMDocument|null
	 */
	public static function loadDOM( string $text ): ?DOMDocument {
		// @todo load only actual page content. right now this will also load stuff like "protectedpagewarning"
		$dom = new DOMDocument( '1.0', 'utf-8' );

		// Unicode-compatibility - see:
		// https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $text );
		$dom->preserveWhiteSpace = false;
		self::$dom = $dom;
		self::$text = $text;
		self::removeIgnoredElements();

		return self::$dom;
	}

	/**
	 * @return string|null
	 */
	protected static function getText(): ?string {
		return self::$text;
	}

	/**
	 * @return DOMDocument|null
	 */
	protected static function getDOM(): ?DOMDocument {
		return self::$dom;
	}

	/**
	 * @return array
	 */
	public static function getAllChecksList(): array {
		self::loadAllScoreres();
		$all_checklist = [];
		foreach ( self::$registered_classes as $scorer_class ) {
			$all_checklist += $scorer_class::getCheckList();
		}
		return $all_checklist;
	}

	/**
	 * @param Title $title
	 *
	 * @return array
	 */
	public static function getScorForPage( Title $title ): array {
		self::loadAllScoreres();

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'pq_issues',
			'*',
			[ 'page_id' => $title->getArticleID() ],
			__METHOD__
		);

		$score = 0;
		$responses = [];
		foreach ( $res as $row ) {
			$responses[$row->pq_type][] = [
				'example' => $row->example,
				'score' => $row->score
			];
			$score += $row->score;
		}
		return [ $score, $responses ];
	}

	protected static function deleteDataForPage( Title $title ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->delete(
			'pq_score',
			['page_id' => $title->getArticleID()],
			__METHOD__
		);
		$dbw->delete(
			'pq_issues',
			['page_id' => $title->getArticleID()],
			__METHOD__
		);
	}

	/**
	 * @param Title $title
	 * @param string $page_html
	 * @param bool $automated_run
	 *
	 * @return array
	 * @throws MWException
	 */
	public static function runScorerForPage(
		Title $title, string $page_html = "", bool $automated_run = false
	): array {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbr = wfGetDB( DB_REPLICA );

		// Retrieve the existing record
		$res = $dbr->selectRow(
			'pq_score',
			['score', 'status'],
			[ 'page_id' => $title->getArticleID() ]
		);

		// Delete existing records
		if ( $res ) {
			self::deleteDataForPage( $title );
		}

		// Return if not scoreable (and if it was previously scoreable, its data was deleted
		if ( !self::isPageScoreable( $title ) ) {
			return [ 0, [] ];
		}

		if ( empty( $page_html ) ) {
			$pageObj = WikiPage::factory( $title );
			$page_html = $pageObj->getContent( RevisionRecord::RAW )->getParserOutput( $title )->getText();
		}
		self::loadAllScoreres();
		list( $score, $responses ) = self::runAllScoreres( $page_html );

		$old_score = $res ? $res->score : 0;
		$old_status = $res ? $res->status : PageQualityScorer::GREEN;

		$hasRedIssue = false;
		foreach ( $responses as $type => $type_responses ) {
			foreach ( $type_responses as $response ) {
				if ( $response['score'] === self::RED ) {
					$hasRedIssue = true;
				}
				$dbw->insert(
					'pq_issues',
					[
						'page_id' => $title->getArticleID(),
						'pq_type' => $type,
						'score'   => $response['score'],
						'example' => $response['example']
					],
					__METHOD__,
					[ 'IGNORE' ]
				);
			}
		}

		$status = self::GREEN;
		if ( $score > 0 ) {
			$status = self::YELLOW;
		}
		if ( $hasRedIssue && $score > PageQualityScorer::getSetting( "red" ) ) {
			$status = self::RED;
		}

		if ( $old_score <> $score || $status <> $old_status ) {
			$dbw->insert(
				'pq_score_log',
				[
					'page_id'     => $title->getArticleID(),
					'revision_id' => $title->getLatestRevID(),
					'new_score'   => $score,
					'old_score'   => $old_score,
					'new_status' => $status,
					'old_status' => $old_status,
					'timestamp' => $dbw->timestamp()
				],
				__METHOD__,
				[ 'IGNORE' ]
			);
		}




		$dbw->insert(
			'pq_score',
			[
				'page_id' => $title->getArticleID(),
				'score' => $score,
				'status' => $status
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		return [ $score, $responses ];
	}

	/**
	 * @param string $text
	 *
	 * @return array
	 */
	public static function runAllScoreres( string $text ): array {
		self::loadDOM( $text );
		$responses = [];
		foreach ( self::$registered_classes as $scorer_class ) {
			$scorer_obj = new $scorer_class();
			$responses += $scorer_obj->calculatePageScore();
		}

		$score = 0;
		foreach ( $responses as $type => $type_responses ) {
			foreach ( $type_responses as $response ) {
				$score += $response['score'];
			}
		}
		return [ $score, $responses ];
	}

	/**
	 * @param DOMDocument $dom
	 * @param string $className
	 *
	 * @return DOMNodeList|false|mixed
	 */
	protected static function getElementsByClassName( DOMDocument $dom, string $className ) {
		$xpath = new DOMXpath( $dom );
		$expression = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $className . ' ")]';
		return $xpath->query( $expression );
	}

	/**
	 * Get a status code (0/1/2) and return the human equivalent (green/yellow/red)
	 *
	 * @param int|null $status
	 * @return string
	 */
	public static function getHumanReadableStatus(?int $status ) {
		switch ( $status ) {
			case self::RED: return 'red';
			case self::YELLOW: return 'yellow';
			case self::GREEN: return 'green';
			default: return 'unknown';
		}
	}

	/**
	 * @param int|null $severity
	 * @return string
	 */
	public static function getHumanReadableSeverity(?int $severity ) {
		switch ( $severity ) {
			case self::RED: return 'red';
			case self::YELLOW: return 'yellow';
			default: return 'unknown';
		}
	}

	/**
	 * Messages used:
	 *
	 * @param int|null $severity
	 * @return string
	 */
	public static function getLocalizedSeverity( ?int $severity ) {
		$severity = self::getHumanReadableSeverity( $severity );
		return wfMessage( "pq_severity_$severity" )->text();
	}

	protected static function removeIgnoredElements() {
		$ignoredElements = self::getElementsByClassName( self::getDOM(), 'pagequality-ignore' );
		foreach ( $ignoredElements as $element ) {
			$element->parentNode->removeChild( $element );
		}
	}

}
