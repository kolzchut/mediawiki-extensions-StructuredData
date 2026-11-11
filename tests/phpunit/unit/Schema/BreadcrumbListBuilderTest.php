<?php

namespace MediaWiki\Extension\StructuredData\Tests\Unit\Schema;

use MediaWiki\Extension\StructuredData\Schema\BreadcrumbListBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\StructuredData\Schema\BreadcrumbListBuilder
 */
class BreadcrumbListBuilderTest extends MediaWikiUnitTestCase {

	public function testBuildsPositionedItems() {
		$node = BreadcrumbListBuilder::build( 'https://example.org/he/P#breadcrumb', [
			[ 'name' => 'עמוד ראשי', 'url' => 'https://example.org/he/' ],
			[ 'name' => 'חינוך', 'url' => 'https://example.org/he/קטגוריה:חינוך' ],
			[ 'name' => 'P', 'url' => 'https://example.org/he/P' ],
		] );

		$this->assertSame( [
			'@type' => 'BreadcrumbList',
			'@id' => 'https://example.org/he/P#breadcrumb',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => 'עמוד ראשי',
					'item' => 'https://example.org/he/' ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => 'חינוך',
					'item' => 'https://example.org/he/קטגוריה:חינוך' ],
				[ '@type' => 'ListItem', 'position' => 3, 'name' => 'P',
					'item' => 'https://example.org/he/P' ],
			],
		], $node );
	}

	public function testDropsIncompleteCrumbsAndRenumbers() {
		$node = BreadcrumbListBuilder::build( 'id', [
			[ 'name' => 'Home', 'url' => 'https://example.org/' ],
			[ 'name' => '  ', 'url' => 'https://example.org/Category:X' ],
			[ 'name' => 'No URL', 'url' => '' ],
			[ 'name' => ' Page ', 'url' => 'https://example.org/Page' ],
		] );

		$this->assertCount( 2, $node['itemListElement'] );
		$this->assertSame( 2, $node['itemListElement'][1]['position'] );
		$this->assertSame( 'Page', $node['itemListElement'][1]['name'] );
	}

	public function testNeedsTwoCrumbs() {
		$this->assertNull( BreadcrumbListBuilder::build( 'id', [
			[ 'name' => 'Page', 'url' => 'https://example.org/Page' ],
		] ) );
		$this->assertNull( BreadcrumbListBuilder::build( 'id', [] ) );
	}
}
