<?php
namespace MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate;

use LoggedUpdateMaintenance;
use MediaWiki\Extension\PageQuality\RefreshJob;
use MediaWiki\Extension\PageQuality\SpecialPageQuality;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

/**
 * Examples stored before they were escaped can hold markup taken from page text.
 * Remove every stored issue and queue a rescoring job for every scoreable page,
 * so the report is rebuilt from examples made by the current code.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @ingroup Maintenance
 */

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../../maintenance/Maintenance.php';
}

$maintClass = RegenerateIssueExamples::class;

/**
 * Run automatically with update.php, once
 */
class RegenerateIssueExamples extends LoggedUpdateMaintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Delete all stored page quality issues and queue a rescoring job for every scoreable page'
		);
		$this->setBatchSize( 500 );
	}

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates(): bool {
		$dbw = $this->getDB( DB_PRIMARY );

		// Remove the old rows first, so none of them is shown while the jobs are pending
		$dbw->delete( 'pq_issues', ISQLPlatform::ALL_ROWS, __METHOD__ );
		$this->output( "Deleted {$dbw->affectedRows()} stored page quality issues\n" );

		$query = SpecialPageQuality::getQueryForAllPages();
		$query['fields'] = [ 'page_id', 'page_namespace', 'page_title' ];
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();

		$lastId = 0;
		$queued = 0;
		while ( true ) {
			$conds = $query['conds'];
			$conds[] = 'page_id > ' . $dbw->addQuotes( $lastId );
			$res = $dbw->select(
				$query['tables'],
				$query['fields'],
				$conds,
				__METHOD__,
				[ 'ORDER BY' => 'page_id', 'LIMIT' => $this->getBatchSize() ],
				$query['join_conds']
			);
			if ( !$res->numRows() ) {
				break;
			}
			$jobs = [];
			foreach ( $res as $row ) {
				$jobs[] = new RefreshJob( Title::makeTitle( $row->page_namespace, $row->page_title ) );
				$lastId = (int)$row->page_id;
			}
			$jobQueueGroup->push( $jobs );
			$queued += count( $jobs );
		}

		$this->output( "Queued $queued page quality rescoring jobs\n" );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey(): string {
		return __CLASS__;
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;
