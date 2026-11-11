<?php

namespace MediaWiki\Extension\StructuredData\Tests\Unit\Schema;

use MediaWiki\Extension\StructuredData\Schema\ArticleBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\StructuredData\Schema\ArticleBuilder
 */
class ArticleBuilderTest extends MediaWikiUnitTestCase {

	public function testBuildsFullArticle() {
		$node = ArticleBuilder::build(
			'https://example.org/ar/P#article',
			'عنوان',
			'https://example.org/ar/P',
			'ar',
			'20120304050607',
			'20261111093000',
			'https://example.org/ar/#organization'
		);

		$this->assertSame( [
			'@type' => 'Article',
			'@id' => 'https://example.org/ar/P#article',
			'headline' => 'عنوان',
			'url' => 'https://example.org/ar/P',
			'mainEntityOfPage' => 'https://example.org/ar/P',
			'inLanguage' => 'ar',
			'datePublished' => '2012-03-04T05:06:07Z',
			'dateModified' => '2026-11-11T09:30:00Z',
			'publisher' => [ '@id' => 'https://example.org/ar/#organization' ],
			'author' => [ '@id' => 'https://example.org/ar/#organization' ],
		], $node );
	}

	public function testLeavesOutUnknownDatesAndPublisher() {
		$node = ArticleBuilder::build( 'id', 'H', 'https://example.org/P', 'ru', null, 'not a date', null );

		$this->assertArrayNotHasKey( 'datePublished', $node );
		$this->assertArrayNotHasKey( 'dateModified', $node );
		$this->assertArrayNotHasKey( 'publisher', $node );
		$this->assertArrayNotHasKey( 'author', $node );
	}

	public function testImage() {
		$node = ArticleBuilder::build( 'id', 'H', 'u', 'he', null, null, null, 'https://example.org/a.png' );
		$this->assertSame( 'https://example.org/a.png', $node['image'] );

		$this->assertArrayNotHasKey( 'image', ArticleBuilder::build( 'id', 'H', 'u', 'he', null, null, null, null ) );
		$this->assertArrayNotHasKey( 'image', ArticleBuilder::build( 'id', 'H', 'u', 'he', null, null, null, '' ) );
	}

	public function testModifiedNeverPrecedesPublished() {
		$node = ArticleBuilder::build( 'id', 'H', 'u', 'he', '20200101000000', '20190101000000', null );

		$this->assertSame( '2020-01-01T00:00:00Z', $node['dateModified'] );
	}

	public static function provideTimestamps() {
		return [
			'MediaWiki' => [ '20261111093000', '2026-11-11T09:30:00Z' ],
			'ISO 8601' => [ '2026-11-11T09:30:00Z', '2026-11-11T09:30:00Z' ],
			'empty' => [ '', null ],
			'null' => [ null, null ],
			'garbage' => [ 'yesterday-ish', null ],
		];
	}

	/**
	 * @dataProvider provideTimestamps
	 */
	public function testToIso8601( ?string $input, ?string $expected ) {
		$this->assertSame( $expected, ArticleBuilder::toIso8601( $input ) );
	}
}
