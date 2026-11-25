<?php

namespace MediaWiki\Extension\PageQuality\Scorer;

use DOMDocument;
use DOMElement;
use DOMNode;
use MediaWiki\Extension\PageQuality\Scorer as BaseScorer;

class Readability extends BaseScorer {

	/**
	 * @inheritDoc
	 */
	public static array $checksList = [
		"blocked_expressions" => [
			"name" => "pag_scorer_stop_words",
			"description" => "pag_scorer_stop_words_desc",
			"check_type" => "do_not_exist",
			"data_type" => "list",
			"severity" => BaseScorer::YELLOW,
			"default" => ""
		],
		"para_length" => [
			"name" => "pag_scorer_para_len",
			"description" => "pag_scorer_para_len_desc",
			"check_type" => "max",
			"severity" => BaseScorer::YELLOW,
			"default" => 40
		],
		"para_length_max" => [
			"name" => "pag_scorer_para_len_max",
			"description" => "pag_scorer_para_len_max_desc",
			"check_type" => "max",
			"severity" => BaseScorer::RED,
			"default" => 60
		],
		"sentence_length" => [
			"name" => "pag_scorer_sentence_len",
			"description" => "pag_scorer_sentence_len_desc",
			"check_type" => "max",
			"severity" => BaseScorer::YELLOW,
			"default" => 15
		],
		"sentence_length_max" => [
			"name" => "pag_scorer_sentence_len_max",
			"description" => "pag_scorer_sentence_len_max_desc",
			"check_type" => "max",
			"severity" => BaseScorer::RED,
			"default" => 30
		],
		"list_items_per_level_max" => [
			"name" => "pag_scorer_list_items_per_level_max",
			"description" => "pag_scorer_list_items_per_level_max_desc",
			"check_type" => "max",
			"severity" => BaseScorer::RED,
			"default" => 10
		],
	];
	/** @var array */
	public array $response = [];

	/**
	 * @inheritDoc
	 */
	public function calculatePageScore(): ?array {
		$blocked_expressions = self::getSetting( "blocked_expressions" );
		if ( !empty( $blocked_expressions ) ) {
			// Match against the text as a reader sees it, with the parser's entities decoded
			$plainText = html_entity_decode(
				strip_tags( self::getText() ), ENT_QUOTES | ENT_HTML5, 'UTF-8'
			);
			foreach ( $blocked_expressions as $blocked_expression ) {
				if ( trim( $blocked_expression ) === '' ) {
					continue;
				}
				$expressionLength = mb_strlen( $blocked_expression );
				$offset = mb_strpos( $plainText, $blocked_expression );
				while ( $offset !== false ) {
					$this->response[ 'blocked_expressions' ][] = [
						"score" => self::getCheckList()[ 'blocked_expressions' ][ 'severity' ],
						"example" => self::makeHighlightedExample( $plainText, $offset, $expressionLength )
					];
					$offset = mb_strpos( $plainText, $blocked_expression, $offset + $expressionLength );
				}
			}
		}

		$dom = self::loadDOM( self::$text );
		$pNodes = $dom->getElementsByTagName( 'html' );
		foreach ( $pNodes as $pNode ) {
			$this->recurseDomNodes( $pNode );
		}
		return $this->response;
	}

	/**
	 * Build the example for a blocked expression: up to 30 characters of text on
	 * each side of the match, with the match in bold. Every part taken from the
	 * page is escaped, and the result fits the example column with its tags whole.
	 *
	 * @param string $plainText Plain text of the page, entities decoded
	 * @param int $offset Character offset of the match
	 * @param int $length Character length of the match
	 * @return string HTML
	 */
	public static function makeHighlightedExample( string $plainText, int $offset, int $length ): string {
		$contextLength = 30;
		$start = max( 0, $offset - $contextLength );
		$before = mb_substr( $plainText, $start, $offset - $start );
		// Start on a word boundary when the excerpt does not start the text
		$space = mb_strpos( $before, ' ' );
		if ( $start > 0 && $space !== false ) {
			$before = mb_substr( $before, $space + 1 );
		}
		$match = mb_substr( $plainText, $offset, $length );
		$after = mb_substr( $plainText, $offset + $length, $contextLength );

		$example = self::joinHighlightedExample( $before, $match, $after );
		while ( mb_strlen( $example ) > self::EXAMPLE_MAX_LENGTH && $match !== '' ) {
			if ( $after !== '' ) {
				$after = mb_substr( $after, 0, -1 );
			} elseif ( $before !== '' ) {
				$before = mb_substr( $before, 1 );
			} else {
				$match = mb_substr( $match, 0, -1 );
			}
			$example = self::joinHighlightedExample( $before, $match, $after );
		}
		return $example;
	}

	/**
	 * @param string $before Plain text
	 * @param string $match Plain text
	 * @param string $after Plain text
	 * @return string HTML
	 */
	private static function joinHighlightedExample( string $before, string $match, string $after ): string {
		return self::escapeExampleText( $before ) .
			'<b>' . self::escapeExampleText( $match ) . '</b>' .
			self::escapeExampleText( $after );
	}

	/**
	 * @param string $text
	 *
	 * @return DOMDocument|null
	 */
	public static function loadDOM( string $text ): ?DOMDocument {
		$text = strip_tags( $text,
			[ 'p', 'table', 'tr', 'th', 'td', 'div', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5' ]
		);
		return parent::loadDOM( $text );
	}

	/**
	 * @param DOMNode $pNode
	 *
	 * @return void
	 */
	public function recurseDomNodes( DOMNode $pNode ): void {
		if ( $pNode instanceof DOMElement && ( $pNode->tagName == "ul" || $pNode->tagName == "ol" ) ) {
			if ( $pNode->hasChildNodes() ) {
				$count_of_li = 0;
				foreach ( $pNode->childNodes as $childNode ) {
					if ( $childNode instanceof DOMElement && $childNode->tagName == "li" ) {
						$count_of_li++;
					}
				}
				if ( $count_of_li > self::getSetting( "list_items_per_level_max" ) ) {
					$this->response['list_items_per_level_max'][] = [
						"score" => self::getCheckList()['list_items_per_level_max']['severity'],
						"example" => self::makeTextExample( $pNode->textContent, 50 )
					];
				}
			}
		}
		if ( $pNode->hasChildNodes() && count( $pNode->childNodes ) > 0 ) {
			foreach ( $pNode->childNodes as $childNode ) {
				$this->recurseDomNodes( $childNode );
			}
		} elseif ( $pNode->hasChildNodes() && $pNode->firstChild->hasChildNodes() ) {
			foreach ( $pNode->firstChild->childNodes as $childNode ) {
				$this->recurseDomNodes( $childNode );
			}
		} else {
			if ( empty( trim( $pNode->nodeValue ) ) ) {
				return;
			}
			if ( $pNode->parentNode instanceof DOMElement &&
				stripos( $pNode->parentNode->getAttribute( 'class' ), "emphasis-item-text" ) === false ) {
				$this->evaluateParagraphs( $pNode->nodeValue );
			}
		}
	}

	/**
	 * @param string $str
	 *
	 * @return void
	 */
	public function evaluateParagraphs( string $str ): void {
		$sentences = preg_split( '/(?<=[.?!])\s+(?=\w)/iu', $str );
		foreach ( $sentences as $sentence ) {
			$wc = self::str_word_count_utf8( $sentence );

			if ( $wc > self::getSetting( "sentence_length_max" ) ) {
				$this->response['sentence_length_max'][] = [
					"score" => self::getCheckList()['sentence_length_max']['severity'],
					"example" => self::makeTextExample( $sentence, 50 )
				];
			} elseif ( $wc > self::getSetting( "sentence_length" ) ) {
				$this->response['sentence_length'][] = [
					"score" => self::getCheckList()['sentence_length']['severity'],
					"example" => self::makeTextExample( $sentence, 50 )
				];
			}
		}

		if ( count( $sentences ) == 1 ) {
			return;
		}

		$wc = self::str_word_count_utf8( $str );
		if ( $wc > self::getSetting( "para_length_max" ) ) {
			$this->response['para_length_max'][] = [
				"score" => self::getCheckList()['para_length_max']['severity'],
				"example" => self::makeTextExample( $str, 50 )
			];
		} elseif ( $wc > self::getSetting( "para_length" ) ) {
			$this->response['para_length'][] = [
				"score" => self::getCheckList()['para_length']['severity'],
				"example" => self::makeTextExample( $str, 50 )
			];
		}
	}
}
