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
