<?php

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Html\Html;
use MediaWiki\Json\FormatJson;

/**
 * Serialises schema.org nodes into one JSON-LD <script> element.
 */
class JsonLd {

	public const CONTEXT = 'https://schema.org';

	/**
	 * The JSON-LD document for a set of nodes, as one @graph.
	 *
	 * @param array[] $nodes
	 * @return array
	 */
	public static function document( array $nodes ): array {
		return [
			'@context' => self::CONTEXT,
			'@graph' => array_values( $nodes ),
		];
	}

	/**
	 * A <script type="application/ld+json"> element holding the nodes.
	 *
	 * FormatJson escapes '<', '>' and '&' (it is not given XMLMETA_OK), so no
	 * value can close the element early with "</script>" or open a comment, and
	 * it escapes U+2028 and U+2029. UTF8_OK keeps Hebrew, Arabic and Russian
	 * text readable instead of \u-escaping every character.
	 *
	 * @param array[] $nodes
	 * @return string|null HTML, or null when there is nothing to emit or the
	 *  nodes cannot be encoded
	 */
	public static function toScript( array $nodes ): ?string {
		if ( !$nodes ) {
			return null;
		}
		$json = FormatJson::encode( self::document( $nodes ), false, FormatJson::UTF8_OK );
		if ( !is_string( $json ) ) {
			return null;
		}
		return Html::rawElement( 'script', [ 'type' => 'application/ld+json' ], $json );
	}
}
