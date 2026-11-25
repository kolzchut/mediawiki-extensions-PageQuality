<?php

namespace MediaWiki\Extension\PageQuality\Tests\Integration;

use MediaWiki\Extension\PageQuality\Scorer;
use MediaWiki\Extension\PageQuality\Scorer\Readability;
use MediaWikiIntegrationTestCase;

/**
 * Examples taken from page text are stored and later printed as HTML, so they
 * must come out of the rules escaped.
 *
 * @group Database
 * @covers \MediaWiki\Extension\PageQuality\Scorer
 * @covers \MediaWiki\Extension\PageQuality\Scorer\CalloutsRules
 * @covers \MediaWiki\Extension\PageQuality\Scorer\EmphasisRules
 * @covers \MediaWiki\Extension\PageQuality\Scorer\Readability
 */
class ScorerExampleEscapingTest extends MediaWikiIntegrationTestCase {

	/**
	 * A tag the parser refused, as it appears in the parser's HTML. The DOM decodes
	 * it to `<i data-x=1>`, so an escaped example must show these same entities.
	 */
	private const MARKER = '&lt;i data-x=1&gt;';

	protected function setUp(): void {
		parent::setUp();
		Scorer::clearSettingsCache();
		Scorer::loadAllScoreres();
	}

	protected function tearDown(): void {
		Scorer::clearSettingsCache();
		parent::tearDown();
	}

	/**
	 * @param array $responses
	 * @param string $type
	 * @return string[]
	 */
	private function getExamples( array $responses, string $type ): array {
		$this->assertArrayHasKey( $type, $responses, "The $type rule did not fire" );
		return array_map( static fn ( $r ) => (string)$r['example'], $responses[$type] );
	}

	/**
	 * @param string $example
	 */
	private function assertNoMarkerElement( string $example ): void {
		$this->assertStringNotContainsString( '<i', $example );
		$this->assertStringContainsString( self::MARKER, $example );
	}

	public function testCalloutExamplesAreEscaped() {
		$html = '<h2>Heading</h2><div class="wr-tip">kz ' . self::MARKER . ' end</div>' .
			'<div class="wr-tip">second ' . self::MARKER . ' end</div><p>text</p>';
		[ , $responses ] = Scorer::runAllScorers( $html );

		foreach ( [ 'callout_section_begin', 'callout_gaps' ] as $type ) {
			foreach ( $this->getExamples( $responses, $type ) as $example ) {
				$this->assertNoMarkerElement( $example );
			}
		}
	}

	public function testEmphasisExampleIsEscaped() {
		$words = str_repeat( 'word ', 40 );
		$html = '<div class="emphasis-item">' . self::MARKER . ' ' . $words . '</div>';
		[ , $responses ] = Scorer::runAllScorers( $html );

		foreach ( $this->getExamples( $responses, 'emphasis_line_length' ) as $example ) {
			$this->assertNoMarkerElement( $example );
		}
	}

	public function testSentenceAndParagraphExamplesAreEscaped() {
		$words = str_repeat( 'word ', 70 );
		$html = '<p>' . self::MARKER . ' ' . $words . '. Another sentence here.</p>';
		[ , $responses ] = Scorer::runAllScorers( $html );

		foreach ( [ 'sentence_length_max', 'para_length_max' ] as $type ) {
			foreach ( $this->getExamples( $responses, $type ) as $example ) {
				$this->assertNoMarkerElement( $example );
			}
		}
	}

	public function testListExampleIsEscaped() {
		$html = '<ul><li>' . self::MARKER . '</li>' . str_repeat( '<li>item</li>', 12 ) . '</ul>';
		[ , $responses ] = Scorer::runAllScorers( $html );

		foreach ( $this->getExamples( $responses, 'list_items_per_level_max' ) as $example ) {
			$this->assertNoMarkerElement( $example );
		}
	}

	public function testBlockedExpressionExampleKeepsOnlyItsOwnBold() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'pq_settings' )
			->row( [ 'setting' => 'blocked_expressions', 'value_blob' => 'forbidden' ] )
			->caller( __METHOD__ )
			->execute();
		Scorer::clearSettingsCache();

		$html = '<p>before ' . self::MARKER . ' forbidden after</p>';
		[ , $responses ] = Scorer::runAllScorers( $html );

		$examples = $this->getExamples( $responses, 'blocked_expressions' );
		$this->assertCount( 1, $examples );
		$this->assertSame( 'before ' . self::MARKER . ' <b>forbidden</b> after', $examples[0] );
	}

	public function testTextExampleFitsColumnWithoutSplittingAnEntity() {
		$example = Scorer::makeTextExample( str_repeat( '<', 60 ) );
		$this->assertLessThanOrEqual( Scorer::EXAMPLE_MAX_LENGTH, mb_strlen( $example ) );
		$this->assertSame( str_repeat( '&lt;', 25 ), $example );
	}

	public function testTextExampleHonoursMaxLength() {
		$this->assertSame( 'abcde', Scorer::makeTextExample( 'abcdefgh', 5 ) );
		$this->assertSame( '&quot;a&quot;', Scorer::makeTextExample( '"a"', 50 ) );
	}

	public function testHighlightedExampleFitsColumnWithTagsWhole() {
		$text = str_repeat( '&', 40 ) . 'forbidden' . str_repeat( '&', 40 );
		$example = Readability::makeHighlightedExample( $text, 40, 9 );
		$this->assertLessThanOrEqual( Scorer::EXAMPLE_MAX_LENGTH, mb_strlen( $example ) );
		$this->assertStringContainsString( '<b>forbidden</b>', $example );
		$this->assertDoesNotMatchRegularExpression( '/&(?!amp;)/', $example, 'An entity was cut' );
	}
}
