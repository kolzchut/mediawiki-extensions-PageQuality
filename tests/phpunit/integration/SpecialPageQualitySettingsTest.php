<?php

namespace MediaWiki\Extension\PageQuality\Tests\Integration;

use DOMDocument;
use DOMXPath;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PageQuality\Scorer;
use MediaWiki\Extension\PageQuality\SpecialPageQuality;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\CsrfTokenSet;
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
	 * A settings request in a session logged in as $user, so that the token the
	 * page expects is that user's registered token and not the logged-out one.
	 *
	 * @param User $user
	 * @param array $data
	 * @param bool $posted
	 * @return FauxRequest
	 */
	private function newRequest( User $user, array $data, bool $posted ): FauxRequest {
		$session = RequestContext::getMain()->getRequest()->getSession();
		$session->setUser( $user );
		return new FauxRequest(
			$data + [ 'save' => '1', 'red' => self::INPUT_VALUE ],
			$posted,
			$session
		);
	}

	/**
	 * @param FauxRequest $request
	 * @return string The valid edit token for the request's session
	 */
	private function getValidToken( FauxRequest $request ): string {
		$token = ( new CsrfTokenSet( $request ) )->getToken()->toString();
		$this->assertNotSame( '+\\', $token, 'The session should belong to a registered user' );
		return $token;
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
		// Even with a valid token, a GET must not save
		$admin = $this->getAdmin();
		$request = $this->newRequest( $admin, [], false );
		$request->setVal( 'wpEditToken', $this->getValidToken( $request ) );

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
		$admin = $this->getAdmin();
		$request = $this->newRequest( $admin, [ 'wpEditToken' => $token ], true );

		[ $html ] = $this->executeSpecialPage( 'settings', $request, 'qqx', $admin );

		$this->assertSame( [], $this->getStoredValues( 'red' ) );
		$this->assertStringContainsString( '(sessionfailure)', $html );

		// Control: the same request with the right token does save, so the refusal
		// above came from comparing the token
		$request = $this->newRequest( $admin, [], true );
		$request->setVal( 'wpEditToken', $this->getValidToken( $request ) );

		[ $html ] = $this->executeSpecialPage( 'settings', $request, 'qqx', $admin );

		$this->assertContains( self::INPUT_VALUE, $this->getStoredValues( 'red' ) );
		$this->assertStringNotContainsString( '(sessionfailure)', $html );
	}

	public function testPostWithTokenSavesAndPrintsValuesEscaped() {
		$admin = $this->getAdmin();
		$request = $this->newRequest( $admin, [ 'blocked_expressions' => self::TEXTAREA_VALUE ], true );
		$request->setVal( 'wpEditToken', $this->getValidToken( $request ) );

		[ $html ] = $this->executeSpecialPage( 'settings', $request, 'qqx', $admin );

		$this->assertContains( self::INPUT_VALUE, $this->getStoredValues( 'red' ) );
		$this->assertStringNotContainsString( '(sessionfailure)', $html );

		// The page that follows the save prints the stored values back
		$this->assertSettingValuesPrintedAsText( $html );
	}

	public function testSavingTwiceUpdatesInPlace() {
		$admin = $this->getAdmin();
		foreach ( [ '5', '7' ] as $red ) {
			$request = $this->newRequest(
				$admin, [ 'red' => $red, 'blocked_expressions' => "expression $red" ], true
			);
			$request->setVal( 'wpEditToken', $this->getValidToken( $request ) );
			$this->executeSpecialPage( 'settings', $request, 'qqx', $admin );
		}

		$this->assertSame( [ '7' ], $this->getStoredValues( 'red' ) );
		$this->assertSame(
			[ 'expression 7' ],
			$this->getDb()->newSelectQueryBuilder()
				->select( 'value_blob' )
				->from( 'pq_settings' )
				->where( [ 'setting' => 'blocked_expressions' ] )
				->caller( __METHOD__ )
				->fetchFieldValues()
		);
		$this->assertSame(
			[],
			$this->getDb()->newSelectQueryBuilder()
				->select( 'setting' )
				->from( 'pq_settings' )
				->groupBy( 'setting' )
				->having( 'COUNT(*) > 1' )
				->caller( __METHOD__ )
				->fetchFieldValues(),
			'A setting has more than one row'
		);
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
