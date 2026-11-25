<?php

namespace MediaWiki\Extension\PageQuality\Tests\Integration;

use MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate\DedupeSettings;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

/**
 * The update that removes duplicate settings rows, so that the unique index on
 * pq_settings.setting can be added. The tests drop that index to recreate the
 * state of a wiki that has not been updated yet, and put it back afterwards.
 *
 * @group Database
 * @covers \MediaWiki\Extension\PageQuality\Maintenance\PostDatabaseUpdate\DedupeSettings
 */
class DedupeSettingsTest extends MaintenanceBaseTestCase {

	private const INDEX_PATCH = __DIR__ . '/../../../sql/pq_settings_patch_unique_setting.sql';

	/** @inheritDoc */
	protected function getMaintenanceClass() {
		return DedupeSettings::class;
	}

	protected function setUp(): void {
		parent::setUp();
		if ( $this->getDb()->getType() !== 'mysql' ) {
			$this->markTestSkipped( 'Drops an index with MySQL syntax' );
		}
		$db = $this->getDb();
		// Start as a wiki where the update has not run
		$db->newDeleteQueryBuilder()
			->deleteFrom( 'updatelog' )
			->where( [ 'ul_key' => DedupeSettings::class ] )
			->caller( __METHOD__ )
			->execute();
		if ( $this->hasUniqueIndex() ) {
			$db->query(
				'DROP INDEX ' . $db->addIdentifierQuotes( DedupeSettings::UNIQUE_INDEX ) .
					' ON ' . $db->tableName( DedupeSettings::TABLE ),
				__METHOD__
			);
		}
	}

	protected function tearDown(): void {
		if ( $this->getDb()->getType() === 'mysql' && !$this->hasUniqueIndex() ) {
			// Leave the table as the schema has it, for the tests that come after
			$this->getDb()->newDeleteQueryBuilder()
				->deleteFrom( DedupeSettings::TABLE )
				->where( ISQLPlatform::ALL_ROWS )
				->caller( __METHOD__ )
				->execute();
			$this->addUniqueIndex();
		}
		parent::tearDown();
	}

	private function hasUniqueIndex(): bool {
		return $this->getDb()->indexExists( DedupeSettings::TABLE, DedupeSettings::UNIQUE_INDEX, __METHOD__ );
	}

	private function addUniqueIndex(): void {
		$this->getDb()->sourceFile( self::INDEX_PATCH );
	}

	/**
	 * @param array[] $rows Each with 'setting' and 'value' or 'value_blob', added in this order
	 */
	private function insertRows( array $rows ): void {
		foreach ( $rows as $row ) {
			// One insert per row: a multi-row insert needs the same columns in every row
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( DedupeSettings::TABLE )
				->row( $row )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * @return array[] Every row's setting, value and value_blob, in the order they were added
	 */
	private function getRows(): array {
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'setting', 'value', 'value_blob' ] )
			->from( DedupeSettings::TABLE )
			->orderBy( 'id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [ $row->setting, $row->value, $row->value_blob ];
		}
		return $rows;
	}

	public function testKeepsTheNewestRowOfEachSetting() {
		$this->insertRows( [
			[ 'setting' => 'red', 'value' => '1' ],
			[ 'setting' => 'blocked_expressions', 'value_blob' => 'old' ],
			[ 'setting' => 'red', 'value' => '2' ],
			[ 'setting' => 'article_types', 'value' => 'only' ],
			[ 'setting' => null, 'value' => 'a' ],
			[ 'setting' => 'blocked_expressions', 'value_blob' => 'new' ],
			[ 'setting' => 'red', 'value' => '3' ],
			[ 'setting' => null, 'value' => 'b' ],
		] );

		$this->assertTrue( $this->maintenance->execute() );

		$this->assertSame( [
			[ 'article_types', 'only', null ],
			[ null, 'a', null ],
			[ 'blocked_expressions', null, 'new' ],
			[ 'red', '3', null ],
			[ null, 'b', null ],
		], $this->getRows() );

		// What the update is for: the unique index can now be added. Rows with no
		// setting are left alone, and do not stop it.
		$this->addUniqueIndex();
		$this->assertTrue( $this->hasUniqueIndex() );
		$this->expectOutputRegex( '/Deleted 3 duplicate/' );
	}

	public function testRunsAgainUntilTheIndexExists() {
		$this->insertRows( [
			[ 'setting' => 'red', 'value' => '1' ],
			[ 'setting' => 'red', 'value' => '2' ],
		] );
		$this->assertTrue( $this->maintenance->execute() );

		// A save made before the index was added duplicated the setting again. The
		// update is logged as done, but must not be skipped while the index is missing.
		$this->insertRows( [ [ 'setting' => 'red', 'value' => '3' ] ] );
		$this->assertTrue( $this->createMaintenance()->execute() );
		$this->assertSame( [ [ 'red', '3', null ] ], $this->getRows() );

		// Once the index exists, the logged update is skipped
		$this->addUniqueIndex();
		$this->assertTrue( $this->createMaintenance()->execute() );
		$this->assertSame( [ [ 'red', '3', null ] ], $this->getRows() );
		$this->expectOutputRegex( '/already logged as completed/' );
	}

	public function testDoesNothingWithoutDuplicates() {
		$this->insertRows( [
			[ 'setting' => 'red', 'value' => '1' ],
			[ 'setting' => 'article_types', 'value' => 'x' ],
		] );
		$this->addUniqueIndex();

		$this->assertTrue( $this->maintenance->execute() );

		$this->assertSame( [ [ 'red', '1', null ], [ 'article_types', 'x', null ] ], $this->getRows() );
		$this->expectOutputRegex( '/Deleted 0 duplicate/' );
	}
}
