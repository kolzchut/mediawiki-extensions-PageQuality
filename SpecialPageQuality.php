<?php

class SpecialPageQuality extends SpecialPage{

	function __construct() {
		parent::__construct( 'PageQuality' );
	}

	function execute( $subpage ) {
		$linkDefs = [
			'pq_settings' => 'Special:PageQuality/settings',
			'pq_reports' => 'Special:PageQuality/reports',
			'pq_history' => 'Special:PageQuality/history',
		];
		$links = [];
		foreach ( $linkDefs as $name => $page ) {
			$title = Title::newFromText( $page );
			$links[] = $this->getLinkRenderer()->makeLink( $title, $this->msg( $name ) );
		}
		$linkStr = $this->getContext()->getLanguage()->pipeList( $links );
		$this->getOutput()->setSubtitle( $linkStr );

		if ( $subpage == "report" ) {
			$this->showReport();
		} else if ( $subpage == "settings" ) {
			$this->showSettings();
		} else if ( $subpage == "history" ) {
			$this->showChangeHistoryForm();
			$this->showChangeHistory();
		} else if ( $subpage == "reports" ) {
			$this->showStatistics();
		} else if ( strpos( $subpage, "reports" ) !== false ) {
			$this->showListReport( substr($subpage, strrpos($subpage, '/') + 1) );
		}
	}

	function getGeneralSettingTab( $save_link, $saved_settings_values ) {
		$class_type = "General";
		$value = "";
		$type = "red";
		if ( array_key_exists( $type, $saved_settings_values ) ) {
			$value = $saved_settings_values[$type];
		}
		$settings_html = '
			<div class="form-group">
				<label for="'. $type .'">Red Score</label>
				<input name="'. $type .'" type="text" class="form-control" placeholder="'. PageQualityScorer::$general_setting_defaults[$type] .'" value='. $value .'>
			</div>
		';
		$tabsContent = '
			<div id="settings_list" style="margin-top:10px;">
				<form action="' . $save_link . '" method="post">
					'. $settings_html .'
					<button type="submit" class="btn btn-primary">Save</button>
				</form>
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
	function showSettings() {
		global $wgScript;

		if ( !in_array( 'sysop', $this->getUser()->getEffectiveGroups() ) ) {
			$this->getOutput()->addHTML( 'You do not have the necessary permissions to view this page.' );
			return;
		}

		$this->getOutput()->enableOOUI();

		$this->getOutput()->setPageTitle( $this->msg( 'pq_settings_title' ) );

		$dbw = wfGetDB( DB_MASTER );

		if ( $this->getRequest()->getVal('save') ==  1 ) {
			foreach( PageQualityScorer::getAllScorers() as $scorer_class ) {
				$all_checklist = $scorer_class::getCheckList();
				foreach( $all_checklist as $type => $check ) {
					if ( $this->getRequest()->getVal( $type ) ) {
						$value_field = "value";
						if ( array_key_exists( 'data_type', $check ) && $check['data_type'] == "list" ) {
							$value_field = "value_blob";
						}
						$dbw->delete(
							'pq_settings',
							array( 'setting' => $type ),
							__METHOD__
						);
						$dbw->insert(
							'pq_settings',
							array( 'setting' => $type, $value_field => $this->getRequest()->getVal( $type ) ),
							__METHOD__
						);
					}
				}
			}
			foreach( PageQualityScorer::$general_setting_defaults as $type => $default_value ) {
				if ( $this->getRequest()->getVal( $type ) ) {
					$dbw->delete(
						'pq_settings',
						array( 'setting' => $type ),
						__METHOD__
					);
					$dbw->insert(
						'pq_settings',
						array( 'setting' => $type, 'value' => $this->getRequest()->getVal( $type ) ),
						__METHOD__
					);
				}
			}
		}

		$saved_settings_values = PageQualityScorer::getSettingValues();

		$save_link = $wgScript . '?title=Special:PageQuality/settings&save=1';
		$html = '
		<div class="" style="">
			<form action="' . $save_link . '" method="post">
		';

		$tabPanels = [];

		$tabPanels[] = $this->getGeneralSettingTab( $save_link, $saved_settings_values );

		foreach ( PageQualityScorer::getAllScorers() as $scorer_class ) {
			$class_type = str_replace( "PageQualityScorer", "", $scorer_class );
			$settings_html = "";

			$all_checklist = $scorer_class::getCheckList();
			foreach( $all_checklist as $type => $data ) {
				if ( !array_key_exists( 'default', $data ) ) {
					continue;
				}
				$value = "";
				if ( array_key_exists( $type, $saved_settings_values ) ) {
					$value = $saved_settings_values[$type];
				}
				if ( array_key_exists( 'data_type', $data ) && $data['data_type'] == "list" ) {
					$settings_html .= '
						<div class="form-group">
							<label for="'. $type .'">'. $this->msg( $data['name'] ) .' (Please add list values separated by newline)</label>
							<textarea name="'. $type .'" class="form-control" placeholder="'. $data['default'] .'">'. $value .'</textarea>
						</div>
					';
				} else {
					$settings_html .= '
						<div class="form-group">
							<label for="'. $type .'">'. $this->msg( $data['name'] ) .'</label>
							<input name="'. $type .'" type="text" class="form-control" placeholder="'. $data['default'] .'" value='. $value .'>
						</div>
					';
				}
			}
			if ( empty( $settings_html ) ) {
				continue;
			}

			$tabsContent = '
				<div id="settings_list" style="margin-top:10px;">
					<form action="' . $save_link . '" method="post">
						'. $settings_html .'
						<button type="submit" class="btn btn-primary">Save</button>
					</form>
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

		$this->getOutput()->addHTML( $form );
		$this->getOutput()->addModules( 'ext.page_quality.special' );

	}

	function showListReport( $report_type ) {
		PageQualityScorer::loadAllScoreres();

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			"pq_issues",
			'*',
			[true],
			__METHOD__
		);
		$page_stats = [];
		foreach( $res as $row ) {
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
		}

		if ( $report_type == "red_all" ) {
			$result = [];
			foreach( $page_stats as $page_id => $page_data ) {
				if ( $page_data['score'] > PageQualityScorer::getSetting( "red" ) ) {
					$result[$page_id] = $page_data[$report_type];
				}
			}
		} else if ( $report_type == "yellow_all" ) {
			$result = [];
			foreach( $page_stats as $page_id => $page_data ) {
				if ( $page_data['score'] > 0 && $page_data['score'] <= PageQualityScorer::getSetting( "red" ) ) {
					$result[$page_id] = $page_data[$report_type];
				}
			}
		} else {
			$result = [];
			foreach( $page_stats as $page_id => $page_data ) {
				if ( array_key_exists( $report_type, $page_data ) ) {
					$result[$page_id] = $page_data[$report_type];
				}
			}
		}


		$html = '
			<table class="wikitable sortable">
			<tr>
				<th>
					' . $this->msg('pq_report_pagename' )->escaped() . '
				</th>
				<th>
					' . $this->msg('pq_report_page_score' )->escaped() . '
				</th>
				<th>
					' . $this->msg('pq_report_page_status' )->escaped() . '
				</th>
			';
		$all_checklist = PageQualityScorer::getAllChecksList();
		$col = array_column( $all_checklist, "severity" );
		array_multisort( $col, SORT_DESC, $all_checklist );
		foreach( $all_checklist as $type => $type_data ) {
			$html .= '
				<th>
					' . $this->msg( $type_data['name'] )->escaped() .'
				</th>
			';
		}
		$html .= '
			</tr>';

		foreach( $page_stats as $page_id => $page_data ) {
			if ( !array_key_exists( $page_id, $result ) ) {
				continue;
			}

			// Messages used next: pq_report_page_status_red, pq_report_page_status_yellow, pq_report_page_status_green
			$page_status_code = $page_data['score'] > PageQualityScorer::getSetting( "red" ) ? "red" : ( $page_data['score'] > 0 ? "yellow" : "green" );

			$html .= '
				<tr>
					<td>
						' . $this->getLinkRenderer()->makeKnownLink( Title::newFromId( $page_id ) ) . '
					</td>
					<td>
						' . $page_data['score'] . '
					</td>
					<td>
						' . $this->msg( 'pq_report_page_status_' . $page_status_code )->escaped() . '
					</td>
				';
			foreach( $all_checklist as $type => $type_data ) {
				$counter = 0;
				if ( array_key_exists( $type, $page_data ) ) {
					$counter = $page_data[$type];
				}
				$html .= '
					<td>
						'. $counter .'
					</td>
				';
			}
			$html .= '
				</tr>';

		}
		$html .= '</table>';
		$this->getOutput()->addHTML( $html );
	}

	function showChangeHistoryForm() {
		$from = $this->getRequest()->getVal( 'from_date', null );
		$to = $this->getRequest()->getVal( 'to_date', null );

		$formDescriptor = [
			'from_date' => [
				'type' => 'date',
				'name' => 'from_date',
				'label' => 'From Date',
				'default' => $from,
			],
			'to_date' => [
				'type' => 'date',
				'name' => 'to_date',
				'label' => 'To Date',
				'default' => $to,
			],
		];

		$htmlForm = new HTMLForm( $formDescriptor, $this->getContext() );
		$htmlForm->setFormIdentifier( 'filter_by_date' );
		$htmlForm
			->setSubmitText( 'Filter' )
			->setSubmitCallback( [ $this, 'showChangeHistory' ] )
			->prepareForm()
			->displayForm( false );
	}

	function showChangeHistory() {
		$from = $this->getRequest()->getVal( 'from_date', null );
		$to = $this->getRequest()->getVal( 'to_date', null );
		if ( empty( $from ) || empty( $to ) ) {
			return;
		}

		$from_date = DateTime::createFromFormat( 'Y-m-d', $from )->getTimestamp();
		$to_date = DateTime::createFromFormat( 'Y-m-d', $to )->getTimestamp();

		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			"pq_score_log",
			'*',
			[ "timestamp > $from_date AND timestamp < $to_date" ],
			__METHOD__
		);

		$improvements = 0;
		$declines = 0;
		foreach( $res as $row ) {
			if ( $row->new_score > PageQualityScorer::getSetting( "red" ) && $row->old_score < PageQualityScorer::getSetting( "red" ) ) {
				$declines++;
			} else if ( $row->new_score < PageQualityScorer::getSetting( "red" ) && $row->old_score > PageQualityScorer::getSetting( "red" ) ) {
				$improvements++;
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
		$link = $this->getLinkRenderer()->makeLink( $title, $declines );

		$html .= '
			<tr>
				<td>
					'. $this->msg( 'declining_pages' )->escaped() . '
				</td>
				<td>
					'. $link .'
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/improvements';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink( $title, $improvements );

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'improving_pages' )->escaped() . '
				</td>
				<td>
					'. $link .'
				</td>
			</tr>';

		$html .= '
			</table>
		';

		$this->getOutput()->addHTML( $html );
	}

	function showStatistics() {
		PageQualityScorer::loadAllScoreres();

		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			"pq_issues",
			'*',
			[true],
			__METHOD__
		);
		$scorer_stats = [];
		$page_stats = [];
		foreach( $res as $row ) {
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
				$scorer_stats[$type] = 0;
			}
			$scorer_stats[$type]++;
		}
		$red_page_count = count( array_filter( array_column($page_stats, "score"), function( $a ) { return $a > PageQualityScorer::getSetting( "red" ); } ) );
		$yellow_page_count = count( array_filter( array_column($page_stats, "score"), function( $a ) { return $a <= PageQualityScorer::getSetting( "red" ) && $a >0; } ) );


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
					' . count( $page_stats ) .'
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/red_all';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink( $title, $red_page_count );

		$html .= '
			<tr>
				<td>
					' . $this->msg( 'red_scanned_pages' )->escaped() . '
				</td>
				<td>
					'. $link .'
				</td>
			</tr>';

		$page = 'Special:PageQuality/reports/yellow_all';
		$title = Title::newFromText( $page );
		$link = $this->getLinkRenderer()->makeLink( $title, $yellow_page_count );

		$html .= '
			<tr>
				<td>
					'. $this->msg( 'yellow_scanned_pages' )->escaped() . '
				</td>
				<td>
					'. $link .'
				</td>
			</tr>
		';


		$html .= '
			<tr>
				<td>
					' . $this->msg( 'green_scanned_pages' )->escaped() . '
				</td>
				<td>
					'. ( count( $page_stats ) - $red_page_count - $yellow_page_count ) .'
				</td>
			</tr>
		';

		$all_checklist = PageQualityScorer::getAllChecksList();
		$col = array_column( $all_checklist, "severity" );
		array_multisort( $col, SORT_DESC, $all_checklist );
		foreach( $all_checklist as $type => $type_data ) {
			if ( array_key_exists( $type, $scorer_stats ) ) {
				$page = "Special:PageQuality/reports/$type";
				$title = Title::newFromText( $page );
				$link = $this->getLinkRenderer()->makeLink( $title, $scorer_stats[$type] );

				$html .= '
					<tr>
						<td>
							' . $this->msg( "scorer_type_count", $this->msg( $type_data['name'] ) )->escaped() . '
						</td>
						<td>
							'. $link .'
						</td>
					</tr>
				';
			}
		}
		$html .= '
			</table>
		';

		$this->getOutput()->addHTML( $html );
	}

	function showReport() {
		$html = "";


		$page_id = $this->getRequest()->getVal('page_id');
		$title = Title::newFromId( $page_id );

		$this->getOutput()->setPageTitle( $this->msg( 'pq_page_quality_report_for_title' ) . " " . $title->getText() );

		$this->getOutput()->addHTML( self::getPageQualityReportHtml( $page_id ) );
	}

	public static function getPageQualityReportHtml( $page_id ) {
		PageQualityScorer::loadAllScoreres();

		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			"pq_issues",
			'*',
			array( 'page_id' => $page_id ),
			__METHOD__
		);

		$html = "";

		$responses = [];
		foreach( $res as $row ) {
			$responses[$row->pq_type][$row->score][] = [
				"example" => $row->example
			];
		}

		$saved_settings_values = PageQualityScorer::getSettingValues();
		$all_checklist = [];
		foreach( PageQualityScorer::getAllScorers() as $scorer_class ) {
			$all_checklist += $scorer_class::getCheckList();
		}

		foreach( $responses as $type => $type_responses ) {
			krsort( $type_responses );
			foreach( $type_responses as $score => $score_responses ) {
				$limit = 0;
				if ( array_key_exists( $type, $saved_settings_values )  ) {
					$limit = $saved_settings_values[$type];
				} else if ( array_key_exists( 'default', $all_checklist[$type] ) ) {
					$limit = $all_checklist[$type]['default'];
				}
				$message = wfMessage( "page_scorer_exceeds", $limit );
				if ( $all_checklist[$type]['check_type'] == "min" ) {
					$message = wfMessage( "page_scorer_minimum", $limit );
				} else if ( $all_checklist[$type]['check_type'] == "exist" ) {
					$message = wfMessage( "page_scorer_existence" );
				} else if ( $all_checklist[$type]['check_type'] == "do_not_exist" ) {
					$message = wfMessage( "page_scorer_inexistence" );
				}
				$html .= '
					<div class="panel panel-danger">
					<div class="panel-heading">
						<span style="background:'. PageQualityScorer::$severity_bg_color[$all_checklist[$type]['severity']] .';color:#721c24;font-weight:600;text-transform:uppercase;">'. wfMessage( 'pq_num_issues' )->numParams( count( $score_responses ) ) . '</span> -
						<span style="font-weight:600;">'. wfMessage( PageQualityScorer::getAllChecksList()[$type]['name'] ) .' - '. $message .'</span>
					</div>
				';
				$html .= '
				<div class="panel">
						<ul class="list-group">
				';
				foreach( $score_responses as $response ) {
					$html .= '
							 <li class="">
							    ' . $response['example'] . '...
							  </li>
					';
				}
				$html .= '
						</ul>
					</div>
					</div>
				';
			}
		}
		return $html;
	}
}
