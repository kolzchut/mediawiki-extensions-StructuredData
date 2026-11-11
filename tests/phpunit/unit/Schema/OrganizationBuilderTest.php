<?php

namespace MediaWiki\Extension\StructuredData\Tests\Unit\Schema;

use MediaWiki\Extension\StructuredData\Schema\OrganizationBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\StructuredData\Schema\OrganizationBuilder
 */
class OrganizationBuilderTest extends MediaWikiUnitTestCase {

	public function testWithLogo() {
		$this->assertSame( [
			'@type' => 'Organization',
			'@id' => 'https://example.org/he/#organization',
			'name' => 'כל-זכות',
			'url' => 'https://example.org/he/',
			'logo' => [ '@type' => 'ImageObject', 'url' => 'https://example.org/logo.png' ],
		], OrganizationBuilder::build(
			'https://example.org/he/#organization',
			'כל-זכות',
			'https://example.org/he/',
			'https://example.org/logo.png'
		) );
	}

	public function testWithoutLogo() {
		$this->assertArrayNotHasKey( 'logo', OrganizationBuilder::build( 'id', 'N', 'u', null ) );
		$this->assertArrayNotHasKey( 'logo', OrganizationBuilder::build( 'id', 'N', 'u', '' ) );
	}
}
