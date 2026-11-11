<?php

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\Extension\StructuredData\PageFacts;
use MediaWiki\Logger\LoggerFactory;
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
		// MediaWiki 1.43 has the farm configuration only as the $wgConf global,
		// which Setup.php creates before the site's settings fill it in. It is
		// not in the Config service.
		$siteConfiguration = $GLOBALS['wgConf'] ?? null;
		return new PageFacts(
			$services->getMainConfig(),
			$services->getRevisionLookup(),
			$services->getPageStore(),
			$services->getRedirectLookup(),
			$services->getDBLoadBalancerFactory(),
			$services->getRepoGroup(),
			$services->getUrlUtils(),
			$siteConfiguration instanceof SiteConfiguration ? $siteConfiguration : new SiteConfiguration(),
			LoggerFactory::getInstance( 'StructuredData' )
		);
	},
];
