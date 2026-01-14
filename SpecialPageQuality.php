<?php
use MediaWiki\Extension\ArticleContentArea\ArticleContentArea;
use MediaWiki\Extension\ArticleType\ArticleType;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class SpecialPageQuality extends SpecialPage {

	/** @var null|string */
	protected ?string $subpage = null;
	/** @var TablePager */
	private TablePager $pager;

	private $validSubReports = [

		];

	/**
	 * @inheritDoc
	 */
	public function __construct( $name = 'PageQuality', $restriction = 'viewpagequality' ) {
		parent::__construct( $name, $restriction );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );
		$linkDefs = [
			'pq_reports' => 'Special:PageQuality/reports',
			'pq_history' => 'Special:PageQuality/history',
			'pq_settings' => 'Special:PageQuality/settings',
		];
		$links = [];
		foreach ( $linkDefs as $name => $page ) {
			$title = Title::newFromText( $page );
			$links[] = $this->getLinkRenderer()->makeLink( $title, $this->msg( $name ) );
		}
		$linkStr = $this->getContext()->getLanguage()->pipeList( $links );
		$this->getOutput()->setSubtitle( $linkStr );
		$report_type = substr( $subPage, strrpos( $subPage, '/' ) + 1 );

		$opts = ( new FormOptions() );
		$opts->add( 'article_content_type', '' );
		$opts->add( 'article_type', '' );
		$opts->add( 'from_date', '' );
		$opts->add( 'to_date', '' );
		$opts->fetchValuesFromRequest( $this->getRequest() );

		if ( !empty( $from_date ) ) {
			$opts->setValue( 'from_date', $from_date, true );
		}
		if ( !empty( $to_date ) ) {
			$opts->setValue( 'to_date', $to_date, true );
		}

		if ( in_array( $report_type, [ 'declines', 'improvements' ] ) ) {
			$this->pager = new PageQualityChangesReportPager( $this->getContext(), $this->getLinkRenderer(), $opts, $report_type );
		} else {
			$this->pager = new PageQualityReportPager( $this->getContext(), $this->getLinkRenderer(), $opts, $report_type );
		}

		$this->subpage = $subPage;
		if ( $subPage == "report" ) {
			$this->showReport();
		} elseif ( $subPage == "settings" ) {
			$this->showSettings();
		} elseif ( $subPage == "history" ) {
			$this->showChangeHistoryForm();
			$this->showChangeHistory();
		} elseif ( $subPage == "reports" || $subPage == "" ) {
			$this->showStatistics();
		} elseif ( strpos( $subPage, "reports" ) !== false ) {
			$this->showListReport( $report_type );
		}
	}

	/**
	 * @param string $save_link
	 * @param array $saved_settings_values
	 *
	 * @return \OOUI\TabPanelLayout
	 * @throws \OOUI\Exception
	 */
	private function getGeneralSettingTab( string $save_link, array $saved_settings_values ) {
		$class_type = "General";
		foreach ( PageQualityScorer::getGeneralSettings() as $type => $data ) {
			if ( !empty( $data['dependsOnExtension'] ) &&
				 !ExtensionRegistry::getInstance()->isLoaded( $data['dependsOnExtension'] )
			) {
				continue;
			}
			$value = $data['default'];
			if ( array_key_exists( $type, $saved_settings_values ) ) {
				$value = $saved_settings_values[$type];
			}
			if ( array_key_exists( 'data_type', $data ) && $data['data_type'] == "list" ) {
				$settings_html .= '
					<div class="form-group">
						<label for="' . $type . '">' .
							$this->msg( $data['name'] ) . ' ' . $this->msg( 'pq_settings_list_field_help' )->escaped() .
						'</label>' .
						'<textarea name="' . $type . '" class="form-control" placeholder="' . $data['default'] . '">' .
							$value .
						'</textarea>
					</div>
				';
			} else {
				$settings_html = '
					<div class="form-group">
						<label for="' . $type . '">' . $this->msg( $data['name'] ) . '</label>
						<input name="' . $type . '" type="text" class="form-control" placeholder="' .
								 $data['default'] . '" value=' . $value .
						 '>
					</div>
				';
			}
		}
		$tabsContent = '
			<div id="settings_list" style="margin-top:10px;">
					' . $settings_html . '
			</div>';

		return new OOUI\TabPanelLayout( 'pq-settings-section-' . $class_type, [
			'label' => $class_type,
			'content' => new OOUI\FieldsetLayout( [
				'classes' => [ 'mw-prefs-section-fieldset' ],
				'id' => "pq-settings-$class_type",
				'label' => $class_type,
				'items' => [
					new OOUI\Widget( [
						'content' => new OOUI\HtmlSnippet( $tabsContent )
					] ),
				],
			] ),
			'expanded' => false,
			'framed' => true,
		] );
	}

	/**
	 * @return void
	 * @throws PermissionsError
	 * @throws \OOUI\Exception
	 */
	protected function showSettings() {
		global $wgScript;

		if ( $this->subpage === 'settings' ) {
			$this->mRestriction = 'configpagequality';
			$this->checkPermissions();
		}

		$this->getOutput()->enableOOUI();
		$this->getOutput()->setPageTitle( $this->msg( 'pq_settings_title' ) );

		$dbw = wfGetDB( DB_PRIMARY );
		$dbr = wfGetDB( DB_REPLICA );

		// @todo Save settings only if they changed ("dirty state")
		// @todo Delete saved settings if they're reset to their default setting
		if ( $this->getRequest()->getVal( 'save' ) == 1 ) {
			foreach ( PageQualityScorer::getAllScorers() as $scorer_class ) {
				$all_checklist = $scorer_class::getCheckList();
				foreach ( $all_checklist as $type => $check ) {
					$value_field = "value";
					if ( array_key_exists( 'data_type', $check ) && $check['data_type'] == "list" ) {
						$value_field = "value_blob";
					}

					$value = $this->getRequest()->getVal( $type );
					if ( $value ) {
						$dbw->upsert(
							'pq_settings',
							[ 'setting' => $type, $value_field =>  $value ],
							'setting',
							[ $value_field =>  $value ?? null ],
						);
					}
				}
			}
			foreach ( PageQualityScorer::getGeneralSettings() as $type => $data ) {
				$savedValue = $this->getRequest()->getVal( $type );
				if ( $savedValue !== null ) {
					$value_field = "value";
					if ( array_key_exists( 'data_type', $check ) && $check['data_type'] == "list" ) {
						$value_field = "value_blob";
					}
					$value = $this->getRequest()->getVal( $type );
					$dbw->upsert(
						'pq_settings',
						[ 'setting' => $type, $value_field =>  $value ],
						'setting',
						[ $value_field =>  $value ?? null ],
					);
				}
			}
			if ( $this->getRequest()->getVal( 'regenerate_scores' ) == "yes" ) {
				$dbw->delete( 'pq_issues', ISQLPlatform::ALL_ROWS );
				$query = self::getQueryForAllPages();
				$res = $dbr->select(
					$query['tables'],
					$query['fields'],
					$query['conds'],
					__METHOD__,
					[],
					$query['join_conds']
				);
				$jobs = [];
				foreach ( $res as $row ) {
					$jobs[] = new PageQualiyRefreshJob( Title::newFromId( $row->page_id ) );
				}
				MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup()->push( $jobs );
			}
		}

		$saved_settings_values = PageQualityScorer::getSettingValues();

		$save_link = $this->getPageTitle( 'settings' )->getLocalURL( [ 'save' => 1 ] );
		$tabPanels = [];

		$tabPanels[] = $this->getGeneralSettingTab( $save_link, $saved_settings_values );

		foreach ( PageQualityScorer::getAllScorers() as $scorer_class ) {
			$class_type = str_replace( "PageQualityScorer", "", $scorer_class );
			$settings_html = "";

			$all_checklist = $scorer_class::getCheckList();
			foreach ( $all_checklist as $type => $data ) {
				if ( !array_key_exists( 'default', $data ) ) {
					continue;
				}
				$value = "";
				if ( array_key_exists( $type, $saved_settings_values ) ) {
					$value = $saved_settings_values[$type];
				}
				if ( array_key_exists( 'data_type', $data ) && $data['data_type'] == "list" ) {
					$settings_html .=
						'<div class="form-group">
							<label for="' . $type . '">' . $this->msg( $data['name'] ) .
								  ' ' . $this->msg( 'pq_settings_list_field_help' )->escaped() .
							'</label>' .
							'<textarea name="' . $type . '" class="form-control" placeholder="' . $data['default'] . '">'
									  . $value .
						  '</textarea>
						</div>
					';
				} else {
					$settings_html .= '
						<div class="form-group">
							<label for="' . $type . '">' . $this->msg( $data['name'] ) . '</label>
							<input name="' . $type . '" type="text" class="form-control" placeholder="' .
								  $data['default'] . '" value=' . $value . '>
						</div>
					';
				}
			}
			if ( empty( $settings_html ) ) {
				continue;
			}

			$tabsContent = '
				<div id="settings_list" style="margin-top:10px;">
						' . $settings_html . '
				</div>';

			$tabPanels[] = new OOUI\TabPanelLayout( 'pq-settings-section-' . $class_type, [
				'label' => $class_type,
				'content' => new OOUI\FieldsetLayout( [
					'classes' => [ 'mw-prefs-section-fieldset' ],
					'id' => "pq-settings-$class_type",
					'label' => $class_type,
					'items' => [
						new OOUI\Widget( [
							'content' => new OOUI\HtmlSnippet( $tabsContent )
						] ),
					],
				] ),
				'expanded' => false,
				'framed' => true,
			] );
		}

		$indexLayout = new OOUI\IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'autoFocus' => false,
			'classes' => [ 'pq-settings-tabs' ],
		] );
		$indexLayout->addTabPanels( $tabPanels );

		$form = new OOUI\PanelLayout( [
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'pq-settings-tabs-wrapper' ],
			'content' => $indexLayout
		] );

		$html = '
		<form action="' . $save_link . '" method="post">
			' . $form . '
				<input type="checkbox" id="regenerate_scores" name="regenerate_scores" value="yes">
				<label for="regenerate_scores"> ' . $this->msg( "regenerate_scores_checkbox" ) . '</label><br>
				<button type="submit" class="btn btn-primary">' .
					$this->msg( 'pq_settings_submit' )->escaped() .
				'</button>
		</form>
		';

		$this->getOutput()->addHTML( $html );
		$this->getOutput()->addModules( 'ext.page_quality.special' );
	}

	/**
	 * @param string $report_type
	 *
	 * @return void
	 * @throws MWException
	 */
	private function showListReport( string $report_type ) {
		$from_date = $this->getRequest()->getVal( 'from_date', "" );
		$to_date = $this->getRequest()->getVal( 'to_date', "" );

		$formDescriptor = [];
		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$valid_content_areas = ArticleContentArea::getValidContentAreas();
			$formDescriptor['article_content_type'] = [
				'type' => 'select',
				'name' => 'article_content_type',
				'label-message' => 'article_content_type',
				'options' => [ "" => "" ] + array_combine( $valid_content_areas, $valid_content_areas ),
			];
		}
		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$valid_article_types = ArticleType::getValidArticleTypes();
			$formDescriptor['article_article_type'] = [
				'type' => 'select',
				'name' => 'article_type',
				'label-message' => 'article_type',
				'options' => [ "" => "" ] + array_combine( $valid_article_types, $valid_article_types ),
			];
		}

		$formDescriptor['from_date'] = [
			'type' => 'date',
			'name' => 'from_date',
			'label' => $this->msg( 'pg_history_form_from_date' ),
			'default' => $from_date,
		];
		$formDescriptor['to_date'] = [
			'type' => 'date',
			'name' => 'to_date',
			'label' => $this->msg( 'pg_history_form_to_date' ),
			'default' => $to_date,
		];
		if ( $report_type === "all" ) {
			$this->getOutput()->setPageTitle( $this->msg( "total_scanned_pages" )->escaped() );
		} elseif ( $report_type === "red_all" ) {
			$this->getOutput()->setPageTitle( $this->msg( "red_scanned_pages" )->escaped() );
		} elseif ( $report_type === "yellow_all" ) {
			$this->getOutput()->setPageTitle( $this->msg( "yellow_scanned_pages" )->escaped() );
		} elseif ( $report_type === "green_all" ) {
			$this->getOutput()->setPageTitle( $this->msg( "green_scanned_pages" )->escaped() );
		} elseif ( $report_type === "declines" ) {
			$this->getOutput()->setPageTitle( $this->msg( "declining_pages" )->escaped() );
		} elseif ( $report_type === "improvements" ) {
			$this->getOutput()->setPageTitle( $this->msg( "improving_pages" )->escaped() );
		} else {
			$all_checklist = PageQualityScorer::getAllChecksList();
			if ( !isset($all_checklist[$report_type] ) ) {
				throw new ErrorPageError( 'pq_reports', 'pq_report_error_no_report' );
			}
			$this->getOutput()->setPageTitle(
				$this->msg( "scorer_type_count",
					$this->msg( $all_checklist[$report_type] )->text(),
					PageQualityScorer::getLocalizedSeverity( $all_checklist[$report_type]['severity'] )
				)->escaped()
			);
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'filter-legend' )
			->setSubmitTextMsg( 'pg_history_form_submit' )
			->prepareForm()
			->displayForm( false );

		$this->getOutput()->addParserOutputContent( $this->pager->getFullOutput() );
	}

	/**
	 * @return void
	 * @throws MWException
	 */
	private function showChangeHistoryForm() {
		$this->getOutput()->setPageTitle( $this->msg( 'pq_page_quality_history' ) );

		$from = $this->getRequest()->getVal( 'from_date', null );
		$to = $this->getRequest()->getVal( 'to_date', null );

		$formDescriptor = [
			'from_date' => [
				'type' => 'date',
				'name' => 'from_date',
				'label' => $this->msg( 'pg_history_form_from_date' ),
				'default' => $from,
			],
			'to_date' => [
				'type' => 'date',
				'name' => 'to_date',
				'label' => $this->msg( 'pg_history_form_to_date' ),
				'default' => $to,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setFormIdentifier( 'filter_by_date' )
			->setSubmitTextMsg( 'pg_history_form_submit' )
			->setSubmitCallback( [ $this, 'showChangeHistory' ] )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @return void
	 */
	private function showChangeHistory() {
		$from = $this->getRequest()->getVal( 'from_date', null );
		$to = $this->getRequest()->getVal( 'to_date', null );

		// @todo check properly if to_date <= $from_date and return an appropriate error message
		$dbr = wfGetDB( DB_REPLICA );

		$query = $this->pager->getScoreLogQuery( $from, $to );

		$res = $dbr->select(
			$query['tables'],
			$query['fields'],
			$query['conds'],
			__METHOD__,
			$query['options'],
			$query['join_conds']
		);

		// We might have multiple entries for the same page within the selected time frame, the
		// below code will ensure that only the latest log is considered in that case.
		$improvements = [];
		$declines = [];
		foreach ( $res as $row ) {
			if ( $row->new_score > PageQualityScorer::getSetting( "red" ) &&
				 $row->old_score < PageQualityScorer::getSetting( "red" )
			) {
				$declines[$row->page_id] = 1;
				$improvements[$row->page_id] = 0;
			} elseif ( $row->new_score < PageQualityScorer::getSetting( "red" ) &&
					   $row->old_score > PageQualityScorer::getSetting( "red" )
			) {
				$improvements[$row->page_id] = 1;
				$declines[$row->page_id] = 0;
			}
		}
		$html = '
			<table class="wikitable sortable">
			<tr>
				<th>
					' . $this->msg( 'pq_report_metric' )->escaped() . '
				</th>
				<th>
					' . $this->msg( 'pq_report_num_pages' )->escaped() . '
				</th>
			';
		$page = 'Special:PageQuality/reports/declines';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink(
			$title, array_sum( $declines ), [], [ 'from_date' => $from, 'to_date' => $to ]
		);

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'declining_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/improvements';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink(
			$title, array_sum( $improvements ), [], [ 'from_date' => $from, 'to_date' => $to ]
		);

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'improving_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>';

		$html .= '
			</table>
		';

		$this->getOutput()->addHTML( $html );
	}

	/**
	 * @return void
	 */
	private function showStatistics() {
		PageQualityScorer::loadAllScoreres();

		$this->getOutput()->setPageTitle( $this->msg( 'pq_page_quality_reports_dashboard' ) );

		$dbr = wfGetDB( DB_REPLICA );

		$yellowConditional = $dbr->conditional( [ 'status' => PageQualityScorer::YELLOW ], '1', 'NULL' );
		$redConditional =  $dbr->conditional( [ 'status' => PageQualityScorer::RED ], '1', 'NULL' );
		$greenConditional =  $dbr->conditional( [ 'status' => PageQualityScorer::GREEN ], '1', 'NULL' );

		$pageCount = $dbr->selectRow(
			"pq_score", [
				'red' => "COUNT($redConditional)",
				'yellow' => "COUNT($yellowConditional)",
				'green' => "COUNT($greenConditional)",
			],
			[]
		);

		$res = $dbr->select(
			"pq_issues",
			'*',
			[ true ],
			__METHOD__
		);
		$scorer_stats = [];
		$page_stats = [];
		foreach ( $res as $row ) {
			$type = $row->pq_type;
			$score = $row->score;
			$page_id = $row->page_id;
			if ( !array_key_exists( $page_id, $page_stats ) ) {
				$page_stats[$page_id] = [ 'score' => 0 ];
			}
			$page_stats[$page_id]['score'] += $score;
			if ( !array_key_exists( $type, $page_stats[$page_id] ) ) {
				$page_stats[$page_id][$type] = 0;
			}
			$page_stats[$page_id][$type]++;

			if ( !array_key_exists( $type, $scorer_stats ) ) {
				$scorer_stats[$type] = [];
			}
			if ( !array_key_exists( $page_id, $scorer_stats[$type] ) ) {
				$scorer_stats[$type][$page_id] = 0;
			}
			$scorer_stats[$type][$page_id]++;
		}

		$page = 'Special:PageQuality/reports/all';
		$title = Title::newFromText( $page );
		$totalCount = $pageCount->red + $pageCount->yellow + $pageCount->green;
		$link = $this->getLinkRenderer()->makeLink( $title, $totalCount );

		$html = '
			<table class="wikitable sortable">
			<tr>
				<th>
					' . $this->msg( 'pq_report_metric' )->escaped() . '
				</th>
				<th>
					' . $this->msg( 'pq_report_num_pages' )->escaped() . '
				</th>
			</tr>';

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'total_scanned_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/red_all';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink( $title, $pageCount->red );

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'red_scanned_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/yellow_all';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink( $title, $pageCount->yellow );

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'yellow_scanned_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>
		';

		$titleValue = self::getTitleValueFor( $this->getName(), 'reports/green_all' );
		$link = $this->getLinkRenderer()->makeKnownLink( $titleValue, $pageCount->green );

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'green_scanned_pages' )->escaped() . '
				</td>
				<td>
					' . $link . '
				</td>
			</tr>
		';

		$all_checklist = PageQualityScorer::getAllChecksList();
		$col = array_column( $all_checklist, "severity" );
		array_multisort( $col, SORT_DESC, $all_checklist );
		foreach ( $all_checklist as $type => $type_data ) {
				$page = "Special:PageQuality/reports/$type";
				$title = Title::newFromText( $page );
				$count = array_key_exists( $type, $scorer_stats ) ? count( $scorer_stats[$type] ) : 0;
				$link = $this->getLinkRenderer()->makeLink( $title, $count );
				$text = $this->msg(
					'scorer_type_count',
					$this->msg( $type_data['name'] )->text(),
					PageQualityScorer::getLocalizedSeverity( $type_data['severity'] )
				)->escaped();

				$html .= '
					<tr>
						<td>
							' . $text . '
						</td>
						<td>
							' . $link . '
						</td>
					</tr>
				';
		}
		$html .= '
			</table>
		';

		$this->getOutput()->addHTML( $html );
	}

	/**
	 * @return void
	 */
	protected function showReport() {
		$page_id = $this->getRequest()->getVal( 'page_id' );
		$title = Title::newFromId( $page_id );
		$this->getOutput()->setPageTitle( $this->msg( 'pq_page_quality_report_for_title', $title->getText() ) );
		$this->getOutput()->addHTML( self::getPageQualityReportHtml( $page_id ) );
	}

	/**
	 * Generate the report in HTML format for a specific page
	 *
	 * @param int $page_id
	 *
	 * @return string
	 */
	public static function getPageQualityReportHtml( int $page_id ): string {
		PageQualityScorer::loadAllScoreres();
		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			"pq_issues",
			'*',
			[ 'page_id' => $page_id ],
			__METHOD__
		);

		$html = "";

		$responses = [];
		foreach ( $res as $row ) {
			$responses[$row->pq_type][$row->score][] = [
				"example" => $row->example
			];
		}

		$saved_settings_values = PageQualityScorer::getSettingValues();
		$all_checklist = [];
		foreach ( PageQualityScorer::getAllScorers() as $scorer_class ) {
			$all_checklist += $scorer_class::getCheckList();
		}

		foreach ( $responses as $type => $type_responses ) {
			krsort( $type_responses );
			foreach ( $type_responses as $score => $score_responses ) {
				$limit = 0;
				if ( array_key_exists( $type, $saved_settings_values ) ) {
					$limit = $saved_settings_values[$type];
				} elseif ( array_key_exists( 'default', $all_checklist[$type] ) ) {
					$limit = $all_checklist[$type]['default'];
				}
				$message = wfMessage( "page_scorer_exceeds", $limit );
				if ( $all_checklist[$type]['check_type'] == "min" ) {
					$message = wfMessage( "page_scorer_minimum", $limit );
				} elseif ( $all_checklist[$type]['check_type'] == "exist" ) {
					$message = wfMessage( "page_scorer_existence" );
				} elseif ( $all_checklist[$type]['check_type'] == "do_not_exist" ) {
					$message = wfMessage( "page_scorer_inexistence" );
				}

				$panelTypeBySeverity = ( $all_checklist[$type]['severity'] === PageQualityScorer::RED ) ?
					'panel-danger' : 'panel-warning';

				$html .= '
					<div class="panel ' . $panelTypeBySeverity . '">
					<div class="panel-heading">
						<span class="badge" data-raofz="15">' . count( $score_responses ) . '</span>&nbsp;
						<span class="sr-only">' . wfMessage( 'pq_num_issues' )->numParams( count( $score_responses ) ) . ' </span>
						<span>' . wfMessage( PageQualityScorer::getAllChecksList()[$type]['name'] )->escaped() . ' - ' . $message . '</span>
					</div>
				';
				$html .= '
						<ul class="list-group">
				';
				foreach ( $score_responses as $response ) {
					if ( !empty( $response[ 'example' ] ) ) {
						$html .=
								 '<li class="list-group-item">' .
									trim( $response[ 'example' ] ) . '<span class="ellipsis">&hellip;</span>' .
								  '</li>';
					}
				}
				$html .= '
						</ul>
					</div>
				';
			}
		}
		return $html;
	}

	static function getQueryForAllPages() {
		$dbr = wfGetDB( DB_REPLICA );
		$allowedNamespaces = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'PageQualityNamespaces' );
		$namespacesList = $dbr->makeList( $allowedNamespaces );

		$query = [
			'tables' => [ 'page' ],
			'fields' => [ 'page_id' ],
			'conds' => [
				'page_is_redirect' => 0,
				"page_namespace IN ($namespacesList)"
			]
		];
		$relevantArticleTypes = PageQualityScorer::getSetting( 'article_types' );
		if ( !empty( $relevantArticleTypes ) && ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleTypeQuery = ArticleType::getJoin( $relevantArticleTypes );
			$query = array_merge_recursive( $query, $articleTypeQuery );
		} else {
			$query['join_conds'] = [];
		}

		return $query;
	}
}
