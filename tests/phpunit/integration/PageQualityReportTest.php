<?php

namespace MediaWiki\Extension\PageQuality\Tests\Integration;

use DOMDocument;
use DOMXPath;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\PageQuality\Scorer;
use MediaWiki\Extension\PageQuality\SpecialPageQuality;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * Markup the parser escaped in an article must not come back as markup in the
 * quality report (the special page and the sidebar both print this HTML).
 *
 * @group Database
 * @covers \MediaWiki\Extension\PageQuality\SpecialPageQuality::getPageQualityReportHtml
 * @covers \MediaWiki\Extension\PageQuality\Hooks::onPageSaveComplete
 * @covers \MediaWiki\Extension\PageQuality\Scorer::runScorerForPage
 */
class PageQualityReportTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::ParserEnableLegacyHeadingDOM => true,
			'PageQualityNamespaces' => [ NS_MAIN ],
		] );
		Scorer::clearSettingsCache();
	}

	protected function tearDown(): void {
		Scorer::clearSettingsCache();
		parent::tearDown();
	}

	public function testReportHasNoMarkupFromPageText() {
		// A callout right after a heading, holding a long sentence that starts with
		// a tag the parser will escape. Both the callout and the sentence rules
		// take their example from this text.
		$wikitext = "==Heading==\n" .
			'<div class="wr-tip">&lt;i data-x=1&gt; ' . str_repeat( 'word ', 40 ) . "end</div>\n\ntext";
		$status = $this->editPage( 'PageQualityEscapingTest', $wikitext );
		$this->assertStatusGood( $status );
		DeferredUpdates::doUpdates();

		$pageId = $status->getNewRevision()->getPageId();
		$examples = $this->getDb()->newSelectQueryBuilder()
			->select( 'example' )
			->from( 'pq_issues' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$this->assertNotEmpty( $examples, 'Saving the page did not score it' );

		$html = SpecialPageQuality::getPageQualityReportHtml( $pageId );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$this->assertSame( 0, $xpath->query( '//i[@data-x]' )->length, 'The report holds an <i data-x> element' );
		$this->assertStringContainsString(
			'<i data-x=1>', $doc->textContent, 'The page text should be shown as text'
		);
	}
}
