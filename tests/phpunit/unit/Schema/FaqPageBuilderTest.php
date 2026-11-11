<?php

namespace MediaWiki\Extension\StructuredData\Tests\Unit\Schema;

use MediaWiki\Extension\StructuredData\Schema\FaqPageBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\StructuredData\Schema\FaqPageBuilder
 */
class FaqPageBuilderTest extends MediaWikiUnitTestCase {

	public function testBuildsOneQuestionPerItem() {
		$node = FaqPageBuilder::build( 'https://example.org/he/P#faq', 'https://example.org/he/P', [
			[ 'question' => 'מי זכאי?', 'answer' => 'כל תושב מעל גיל 18.' ],
			[ 'question' => 'How "long"?', 'answer' => '5–7 days' ],
		] );

		$this->assertSame( [
			'@type' => 'FAQPage',
			'@id' => 'https://example.org/he/P#faq',
			'url' => 'https://example.org/he/P',
			'mainEntity' => [
				[
					'@type' => 'Question',
					'name' => 'מי זכאי?',
					'acceptedAnswer' => [ '@type' => 'Answer', 'text' => 'כל תושב מעל גיל 18.' ],
				],
				[
					'@type' => 'Question',
					'name' => 'How "long"?',
					'acceptedAnswer' => [ '@type' => 'Answer', 'text' => '5–7 days' ],
				],
			],
		], $node );
	}

	public function testStripsMarkupAndCollapsesWhitespace() {
		$node = FaqPageBuilder::build( 'id', 'u', [
			[ 'question' => " <b>Bold</b>\n question ", 'answer' => "<p>Line one</p>\n\n<p>two</p>" ],
		] );

		$this->assertSame( 'Bold question', $node['mainEntity'][0]['name'] );
		$this->assertSame( 'Line one two', $node['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public static function provideEntities() {
		return [
			'quotes' => [ 'Is it &quot;free&quot;?', 'Is it "free"?' ],
			'apostrophe' => [ 'It&#039;s free', "It's free" ],
			'ampersand' => [ 'Tom &amp; Jerry', 'Tom & Jerry' ],
			'no-break spaces' => [ "a&#160;b&nbsp;c\u{00A0}d", 'a b c d' ],
			'Hebrew gershayim' => [ 'מה זה ת&quot;ז?', 'מה זה ת"ז?' ],
			'escaped markup stays text' => [ '&lt;b&gt;x&lt;/b&gt;', '<b>x</b>' ],
		];
	}

	/**
	 * @dataProvider provideEntities
	 */
	public function testDecodesEntities( string $input, string $expected ) {
		$node = FaqPageBuilder::build( 'id', 'u', [ [ 'question' => $input, 'answer' => $input ] ] );

		$this->assertSame( $expected, $node['mainEntity'][0]['name'] );
		$this->assertSame( $expected, $node['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function testDropsItemsWithoutQuestionOrAnswer() {
		$node = FaqPageBuilder::build( 'id', 'u', [
			[ 'question' => 'Q', 'answer' => '' ],
			[ 'question' => '', 'answer' => 'A' ],
			[ 'answer' => 'A' ],
			'not an item',
			[ 'question' => 'Kept', 'answer' => 'Yes' ],
		] );

		$this->assertCount( 1, $node['mainEntity'] );
		$this->assertSame( 'Kept', $node['mainEntity'][0]['name'] );
	}

	public function testNullWithoutQuestions() {
		$this->assertNull( FaqPageBuilder::build( 'id', 'u', [] ) );
		$this->assertNull( FaqPageBuilder::build( 'id', 'u', [ [ 'question' => 'Q' ] ] ) );
	}
}
