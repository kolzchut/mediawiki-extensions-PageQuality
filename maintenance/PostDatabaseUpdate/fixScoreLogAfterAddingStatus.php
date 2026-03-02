<?php
namespace MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate;

use BatchRowIterator;
use LoggedUpdateMaintenance;
use MediaWiki\Extension\PageQuality\Scorer;

/**
 * This script add the status information to old log records
 */

/**
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
 * @author Dror S.
 * @ingroup Maintenance
 */

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../../maintenance/Maintenance.php';
}

$maintClass = fixScoreLogAfterAddingStatus::class;

/**
 * Run automatically with update.php, once
 */
// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
class fixScoreLogAfterAddingStatus extends LoggedUpdateMaintenance {

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates(): bool {
		$dbw = $this->getDB( DB_PRIMARY );

		$iterator = new BatchRowIterator(
			$dbw,
			'pq_score_log',
			[ 'id' ],
			$this->getBatchSize()
		);
		$iterator->setFetchColumns( [ 'id', 'old_status', 'new_status', 'old_score', 'new_score' ] );

		$processed = 0;
		foreach ( $iterator as $batch ) {
			foreach ( $batch as $row ) {
				$old_status = $this->getStatusFromScoreOldAlgorithm( $row->old_score );
				$new_status = $this->getStatusFromScoreOldAlgorithm( $row->new_score );

				$dbw->update(
					'pq_score_log',
					[ 'old_status' => $old_status, 'new_status' => $new_status ],
					[ 'id' => $row->id ]
				);

				$processed += $dbw->affectedRows();
			}
		}

		$this->output( "Processed $processed PageQuality log records\n" );
		return true;
	}

	/**
	 * @param int $score
	 * @return int
	 */
	protected function getStatusFromScoreOldAlgorithm( $score ) {
		$status = Scorer::GREEN;
		if ( $score > 0 ) {
			$status = Scorer::YELLOW;
		}
		if ( $score > Scorer::getSetting( "red" ) ) {
			$status = Scorer::RED;
		}

		return $status;
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey(): string {
		return __CLASS__;
	}

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Migrates all historical records to include the status according to the old algorithm" );
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;
