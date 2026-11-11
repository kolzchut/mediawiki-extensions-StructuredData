<?php

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Parser\ParserOutput;

/**
 * When a page was last edited by a human, which is what Extension:RealLastUpdate
 * records: bot edits (link fixes, template moves) do not make an article newer.
 *
 * RealLastUpdate looks the date up on every page view, in its own
 * OutputPageParserOutput handler, and leaves it in the page property
 * RealLastUpdateTimestamp of the ParserOutput being shown. This reads it from
 * there instead of asking the database a second time. It is read when the
 * <head> is written (OutputPageAfterGetHeadLinksArray), after every
 * OutputPageParserOutput handler has run, so the order in which the two
 * extensions are loaded does not matter.
 *
 * RealLastUpdate is optional. The teen site does not load it, and there the
 * caller falls back to the revision being shown.
 */
class LastHumanEditLookup {

	/** The page property RealLastUpdate sets: a MediaWiki timestamp */
	public const PROPERTY = 'RealLastUpdateTimestamp';

	public function __construct(
		private readonly bool $available
	) {
	}

	/**
	 * Whether RealLastUpdate is loaded, so that its answer can be trusted.
	 */
	public function isAvailable(): bool {
		return $this->available;
	}

	/**
	 * @param ParserOutput $parserOutput The page's output, after RealLastUpdate's
	 *  OutputPageParserOutput handler has seen it
	 * @return string|null Timestamp of the last human edit, or null when
	 *  RealLastUpdate is not loaded or knows of no human edit to the page
	 */
	public function getTimestamp( ParserOutput $parserOutput ): ?string {
		if ( !$this->available ) {
			return null;
		}
		$timestamp = $parserOutput->getPageProperty( self::PROPERTY );
		return is_string( $timestamp ) && $timestamp !== '' ? $timestamp : null;
	}
}
