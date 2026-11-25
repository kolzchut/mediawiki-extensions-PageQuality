<?php

namespace MediaWiki\Extension\PageQuality\Tests\Integration;

use DOMDocument;
use DOMXPath;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PageQuality\Scorer;
use MediaWiki\Extension\PageQuality\SpecialPageQuality;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use SpecialPageTestBase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\PageQuality\SpecialPageQuality
 */
class SpecialPageQualitySettingsTest extends SpecialPageTestBase {

	private const INPUT_VALUE = '1 data-x=1';
	private const TEXTAREA_VALUE = '</textarea><i>';

	/** @inheritDoc */
	protected function newSpecialPage() {
		return new SpecialPageQuality( $this->getServiceContainer()->getTitleFactory() );
	}

	protected function setUp(): void {
		parent::setUp();
		// The wiki may withhold viewpagequality from '*', and the page needs both rights
		$this->setGroupPermissions( [
			'pagequality-admin' => [ 'viewpagequality' => true, 'configpagequality' => true ],
		] );
		Scorer::clearSettingsCache();
	}

	protected function tearDown(): void {
		Scorer::clearSettingsCache();
		parent::tearDown();
	}

	private function getAdmin(): User {
		return $this->getTestUser( [ 'pagequality-admin' ] )->getUser();
	}

	/**
	 * @param array $data
	 * @param bool $posted
	 * @return FauxRequest
	 */
	private function newRequest( array $data, bool $posted ): FauxRequest {
		return new FauxRequest(
			$data + [ 'save' => '1', 'red' => self::INPUT_VALUE ],
			$posted,
			RequestContext::getMain()->getRequest()->getSession()
		);
	}

	/**
	 * @param string $setting
	 * @return string[]
	 */
	private function getStoredValues( string $setting ): array {
		return $this->getDb()->newSelectQueryBuilder()
			->select( 'value' )
			->from( 'pq_settings' )
			->where( [ 'setting' => $setting ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	public function testGetChangesNothing() {
		// Even with the admin's own token, a GET must not save
		$admin = $this->getAdmin();
		$request = $this->newRequest( [ 'wpEditToken' => $admin->getEditToken() ], false );

		$this->executeSpecialPage( 'settings', $request, 'qqx', $admin );

		$this->assertSame( [], $this->getStoredValues( 'red' ) );
	}

	public static function provideBadTokens() {
		return [
			'empty token' => [ '' ],
			'wrong token' => [ 'bogus+\\' ],
		];
	}

	/**
	 * @dataProvider provideBadTokens
	 */
	public function testPostWithoutValidTokenIsRefused( string $token ) {
		$request = $this->newRequest( [ 'wpEditToken' => $token ], true );

		[ $html ] = $this->executeSpecialPage( 'settings', $request, 'qqx', $this->getAdmin() );

		$this->assertSame( [], $this->getStoredValues( 'red' ) );
		$this->assertStringContainsString( '(sessionfailure)', $html );
	}

	public function testPostWithTokenSavesAndPrintsValuesEscaped() {
		// No wpEditToken: SpecialPageExecutor supplies the performer's own token
		// when a posted request has none
		$request = $this->newRequest( [ 'blocked_expressions' => self::TEXTAREA_VALUE ], true );

		[ $html ] = $this->executeSpecialPage( 'settings', $request, 'qqx', $this->getAdmin() );

		$this->assertContains( self::INPUT_VALUE, $this->getStoredValues( 'red' ) );
		$this->assertStringNotContainsString( '(sessionfailure)', $html );

		// The page that follows the save prints the stored values back
		$this->assertSettingValuesPrintedAsText( $html );
	}

	public function testStoredValuesArePrintedAsText() {
		// Separate inserts: a multi-row insert needs the same columns in every row
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'pq_settings' )
			->row( [ 'setting' => 'red', 'value' => self::INPUT_VALUE ] )
			->caller( __METHOD__ )
			->execute();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'pq_settings' )
			->row( [ 'setting' => 'blocked_expressions', 'value_blob' => self::TEXTAREA_VALUE ] )
			->caller( __METHOD__ )
			->execute();
		Scorer::clearSettingsCache();

		[ $html ] = $this->executeSpecialPage( 'settings', new FauxRequest(), 'qqx', $this->getAdmin() );

		$this->assertSettingValuesPrintedAsText( $html );
	}

	public function testFormCarriesToken() {
		[ $html ] = $this->executeSpecialPage( 'settings', new FauxRequest(), 'qqx', $this->getAdmin() );

		$xpath = $this->parse( $html );
		$this->assertSame( 1, $xpath->query( '//form[@method="post"]//input[@name="wpEditToken"]' )->length );
	}

	/**
	 * @param string $html
	 * @return DOMXPath
	 */
	private function parse( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
		libxml_clear_errors();
		return new DOMXPath( $doc );
	}

	/**
	 * @param string $html
	 */
	private function assertSettingValuesPrintedAsText( string $html ): void {
		$xpath = $this->parse( $html );

		$inputs = $xpath->query( '//input[@name="red"]' );
		$this->assertSame( 1, $inputs->length );
		$this->assertSame( self::INPUT_VALUE, $inputs->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//*[@data-x]' )->length, 'A value added an attribute' );

		$textareas = $xpath->query( '//textarea[@name="blocked_expressions"]' );
		$this->assertSame( 1, $textareas->length );
		$this->assertSame( self::TEXTAREA_VALUE, $textareas->item( 0 )->textContent );
		$this->assertSame( 0, $xpath->query( '//i' )->length, 'A value closed the textarea' );
	}
}
