<?php

use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\Extension\StructuredData\PageFacts;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'StructuredData.LastHumanEditLookup' => static function ( MediaWikiServices $services ): LastHumanEditLookup {
		return new LastHumanEditLookup(
			ExtensionRegistry::getInstance()->isLoaded( 'RealLastUpdate' )
		);
	},
	'StructuredData.PageFacts' => static function ( MediaWikiServices $services ): PageFacts {
		return new PageFacts(
			$services->getMainConfig(),
			$services->getRevisionLookup(),
			$services->getPageStore(),
			$services->getRedirectLookup(),
			$services->getConnectionProvider(),
			$services->getRepoGroup(),
			$services->getUrlUtils()
		);
	},
];
