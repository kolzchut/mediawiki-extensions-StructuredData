<?php

namespace MediaWiki\Extension\StructuredData\Schema;

/**
 * schema.org BreadcrumbList.
 *
 * Google requires at least two items, each with a position and a name, and an
 * item URL on every one but the last. Every item here carries its URL.
 *
 * @see https://schema.org/BreadcrumbList
 * @see https://developers.google.com/search/docs/appearance/structured-data/breadcrumb
 */
class BreadcrumbListBuilder {

	/**
	 * @param string $id Node identifier (an IRI)
	 * @param array[] $crumbs Ordered from the top of the site down, each
	 *  [ 'name' => string, 'url' => string ]. A crumb with an empty name or URL
	 *  is dropped.
	 * @return array|null Null when fewer than two crumbs remain
	 */
	public static function build( string $id, array $crumbs ): ?array {
		$items = [];
		foreach ( $crumbs as $crumb ) {
			$name = trim( (string)( $crumb['name'] ?? '' ) );
			$url = (string)( $crumb['url'] ?? '' );
			if ( $name === '' || $url === '' ) {
				continue;
			}
			$items[] = [
				'@type' => 'ListItem',
				'position' => count( $items ) + 1,
				'name' => $name,
				'item' => $url,
			];
		}
		if ( count( $items ) < 2 ) {
			return null;
		}
		return [
			'@type' => 'BreadcrumbList',
			'@id' => $id,
			'itemListElement' => $items,
		];
	}
}
