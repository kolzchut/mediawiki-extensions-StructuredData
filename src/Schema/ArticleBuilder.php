<?php

namespace MediaWiki\Extension\StructuredData\Schema;

use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampException;

/**
 * schema.org Article.
 *
 * Google requires no property of an Article. It recommends headline, author,
 * datePublished and dateModified (ISO 8601 with a time zone) and an image.
 *
 * @see https://schema.org/Article
 * @see https://developers.google.com/search/docs/appearance/structured-data/article
 */
class ArticleBuilder {

	/**
	 * @param string $id Node identifier (an IRI)
	 * @param string $headline
	 * @param string $url The page's canonical URL
	 * @param string $inLanguage BCP 47 language code
	 * @param string|null $published Timestamp of the page's first revision, in
	 *  any format ConvertibleTimestamp reads; null or unreadable leaves it out
	 * @param string|null $modified Timestamp of the page's last edit, likewise
	 * @param string|null $publisherId @id of the publishing Organization, which
	 *  is also given as the author
	 * @return array
	 */
	public static function build(
		string $id,
		string $headline,
		string $url,
		string $inLanguage,
		?string $published,
		?string $modified,
		?string $publisherId
	): array {
		$node = [
			'@type' => 'Article',
			'@id' => $id,
			'headline' => $headline,
			'url' => $url,
			'mainEntityOfPage' => $url,
			'inLanguage' => $inLanguage,
		];
		$published = self::toIso8601( $published );
		$modified = self::toIso8601( $modified );
		if ( $published !== null ) {
			$node['datePublished'] = $published;
		}
		if ( $modified !== null ) {
			// A page cannot have changed before it was created. Imported history
			// can say otherwise; then the creation date is the truer of the two.
			if ( $published !== null && strcmp( $modified, $published ) < 0 ) {
				$modified = $published;
			}
			$node['dateModified'] = $modified;
		}
		if ( $publisherId !== null ) {
			$node['publisher'] = [ '@id' => $publisherId ];
			$node['author'] = [ '@id' => $publisherId ];
		}
		return $node;
	}

	/**
	 * @param string|null $timestamp
	 * @return string|null e.g. 2026-11-11T09:30:00Z
	 */
	public static function toIso8601( ?string $timestamp ): ?string {
		if ( $timestamp === null || $timestamp === '' ) {
			return null;
		}
		try {
			$iso = ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp );
		} catch ( TimestampException ) {
			return null;
		}
		return is_string( $iso ) ? $iso : null;
	}
}
