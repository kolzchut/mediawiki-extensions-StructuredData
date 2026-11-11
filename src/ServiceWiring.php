<?php

use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'StructuredData.LastHumanEditLookup' => static function ( MediaWikiServices $services ): LastHumanEditLookup {
		return new LastHumanEditLookup(
			ExtensionRegistry::getInstance()->isLoaded( 'RealLastUpdate' )
		);
	},
];
