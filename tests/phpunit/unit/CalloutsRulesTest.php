<?php

namespace MediaWiki\Extension\PageQuality\Tests\Unit;

use MediaWiki\Extension\PageQuality\Scorer;
use MediaWiki\Extension\PageQuality\Scorer\CalloutsRules;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\PageQuality\Scorer\CalloutsRules
 */
class CalloutsRulesTest extends MediaWikiUnitTestCase {

	/**
	 * @param string $html
	 * @return array Number of issues found, by check
	 */
	private function countIssues( string $html ): array {
		Scorer::loadDOM( $html );
		$responses = ( new CalloutsRules() )->calculatePageScore();
		return array_map( 'count', $responses );
	}

	public function testCalloutsAfterOneWithNothingBeforeItAreScored() {
		$html = '<div class="wr-tip">first</div>' .
			'<h2>Heading</h2><div class="wr-warning">after a heading</div>' .
			'<div class="wr-tip">after a callout</div>';

		$this->assertSame(
			[ 'callout_section_begin' => 1, 'callout_gaps' => 1 ],
			$this->countIssues( $html )
		);
	}

	public function testCalloutsAfterAnotherElementAreScored() {
		// Control: the same callouts with a paragraph before the first one
		$html = '<p>text</p><div class="wr-tip">first</div>' .
			'<h2>Heading</h2><div class="wr-warning">after a heading</div>' .
			'<div class="wr-tip">after a callout</div>';

		$this->assertSame(
			[ 'callout_section_begin' => 1, 'callout_gaps' => 1 ],
			$this->countIssues( $html )
		);
	}

	public function testPageWithOnlyACalloutWithNothingBeforeItHasNoIssues() {
		$this->assertSame( [], $this->countIssues( '<div class="wr-example">only</div>' ) );
	}

	public function testPageWithoutCalloutsIsReported() {
		$this->assertSame( [ 'callout_number' => 1 ], $this->countIssues( '<p>text</p>' ) );
	}
}
