<?php

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Extension\RealLastUpdate\RealLastUpdate;

/**
 * When a page was last edited by a human, which is what Extension:RealLastUpdate
 * records: bot edits (link fixes, template moves) do not make an article newer.
 *
 * RealLastUpdate is optional. The teen site does not load it, and there the
 * caller falls back to the page's latest revision.
 */
class LastHumanEditLookup {

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
	 * @param int $pageId
	 * @return string|null MediaWiki timestamp of the last human edit, or null when
	 *  RealLastUpdate is not loaded or knows of no human edit to the page
	 */
	public function getTimestamp( int $pageId ): ?string {
		if ( !$this->available || $pageId <= 0 ) {
			return null;
		}
		$edit = RealLastUpdate::getLastRealEdit( $pageId );
		if ( !$edit || empty( $edit['timestamp'] ) ) {
			return null;
		}
		return (string)$edit['timestamp'];
	}
}
