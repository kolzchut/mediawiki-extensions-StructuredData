<?php

namespace MediaWiki\Extension\StructuredData\Schema;

/**
 * schema.org Organization: the site's publisher, referenced by @id from the
 * Article's publisher and author.
 *
 * @see https://schema.org/Organization
 * @see https://developers.google.com/search/docs/appearance/structured-data/organization
 */
class OrganizationBuilder {

	/**
	 * @param string $id Node identifier (an IRI)
	 * @param string $name
	 * @param string $url The organisation's home page
	 * @param string|null $logoUrl Absolute URL, or null for none
	 * @return array
	 */
	public static function build( string $id, string $name, string $url, ?string $logoUrl ): array {
		$node = [
			'@type' => 'Organization',
			'@id' => $id,
			'name' => $name,
			'url' => $url,
		];
		if ( $logoUrl !== null && $logoUrl !== '' ) {
			$node['logo'] = [
				'@type' => 'ImageObject',
				'url' => $logoUrl,
			];
		}
		return $node;
	}
}
