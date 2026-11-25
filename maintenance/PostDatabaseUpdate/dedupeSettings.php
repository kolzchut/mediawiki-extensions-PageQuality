<?php
namespace MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate;

use LoggedUpdateMaintenance;

/**
 * Settings saves used to add a new pq_settings row every time instead of updating
 * the existing one. Keep only the newest row of each setting, so that the unique
 * index on pq_settings.setting can be added.
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

$maintClass = DedupeSettings::class;

/**
 * Run automatically with update.php, before the unique index is added
 */
class DedupeSettings extends LoggedUpdateMaintenance {

	public const TABLE = 'pq_settings';
	public const UNIQUE_INDEX = 'pq_settings_setting';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Keep only the newest pq_settings row of each setting' );
	}

	/**
	 * Until the unique index exists, run even if an earlier run was logged. A save
	 * made after that run could have added a duplicate again, and the index cannot
	 * be added while one is left.
	 *
	 * @inheritDoc
	 */
	public function execute() {
		$dbw = $this->getPrimaryDB();
		if (
			$dbw->tableExists( self::TABLE, __METHOD__ ) &&
			!$dbw->indexExists( self::TABLE, self::UNIQUE_INDEX, __METHOD__ )
		) {
			$this->setForce();
		}
		return parent::execute();
	}

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates(): bool {
		$dbw = $this->getPrimaryDB();
		if ( !$dbw->tableExists( self::TABLE, __METHOD__ ) ) {
			$this->output( "...table " . self::TABLE . " does not exist yet, nothing to do\n" );
			return true;
		}

		// Rows get ids in the order they were added, so the highest id is the latest save.
		// Group in the database, so that settings are told apart by the same comparison
		// the unique index will use.
		$duplicates = $dbw->newSelectQueryBuilder()
			->select( [ 'setting', 'keep_id' => 'MAX(id)' ] )
			->from( self::TABLE )
			->where( $dbw->expr( 'setting', '!=', null ) )
			->groupBy( 'setting' )
			->having( 'COUNT(*) > 1' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$deleted = 0;
		foreach ( $duplicates as $row ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE )
				->where( [
					'setting' => $row->setting,
					$dbw->expr( 'id', '<', (int)$row->keep_id ),
				] )
				->caller( __METHOD__ )
				->execute();
			$deleted += $dbw->affectedRows();
		}

		$this->output( "Deleted $deleted duplicate PageQuality settings rows\n" );
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
