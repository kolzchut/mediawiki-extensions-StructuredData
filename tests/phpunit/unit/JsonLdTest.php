<?php

namespace MediaWiki\Extension\StructuredData\Tests\Unit;

use MediaWiki\Extension\StructuredData\JsonLd;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\StructuredData\JsonLd
 */
class JsonLdTest extends MediaWikiUnitTestCase {

	private static function decode( string $script ): array {
		$matched = preg_match(
			'#^<script type="application/ld\+json">(.*)</script>$#s', $script, $m
		);
		self::assertSame( 1, $matched, $script );
		return json_decode( $m[1], true, 512, JSON_THROW_ON_ERROR );
	}

	public function testWrapsNodesInOneGraph() {
		$script = JsonLd::toScript( [ 'a' => [ '@type' => 'Thing' ], 'b' => [ '@type' => 'Article' ] ] );

		$this->assertSame( [
			'@context' => 'https://schema.org',
			'@graph' => [ [ '@type' => 'Thing' ], [ '@type' => 'Article' ] ],
		], self::decode( $script ) );
	}

	public function testNothingToEmit() {
		$this->assertNull( JsonLd::toScript( [] ) );
	}

	public function testKeepsUnicodeAndSlashesReadable() {
		$script = JsonLd::toScript( [
			[ 'name' => 'כל-זכות كل الحق Коль Зхут', 'url' => 'https://example.org/he/a/b' ],
		] );

		$this->assertStringContainsString( 'כל-זכות كل الحق Коль Зхут', $script );
		$this->assertStringContainsString( 'https://example.org/he/a/b', $script );
	}

	public static function provideBreakouts() {
		return [
			'closing tag' => [ '</script><script>alert(1)</script>' ],
			'upper-case closing tag' => [ '</SCRIPT>' ],
			'comment opener' => [ '<!--' ],
			'CDATA end' => [ ']]>' ],
			'line separator' => [ "a\u{2028}b\u{2029}c" ],
		];
	}

	/**
	 * @dataProvider provideBreakouts
	 */
	public function testValuesCannotBreakOutOfTheScript( string $value ) {
		$script = JsonLd::toScript( [ [ 'name' => $value ] ] );
		$body = substr( $script, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );

		$this->assertStringNotContainsString( '<', $body );
		$this->assertStringNotContainsString( '>', $body );
		$this->assertStringNotContainsString( "\u{2028}", $body );
		$this->assertSame( $value, self::decode( $script )['@graph'][0]['name'] );
	}
}
