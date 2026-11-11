<?php

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWiki\WikiMap\WikiMap;
use RepoGroup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The facts about a page that cost a database query to find out: when it was
 * created, which page its content area leads to, and its image.
 *
 * They are collected once, when the page is parsed, and stored in the
 * ParserOutput's extension data, so they are parser-cached along with the
 * page and a page view reads them without a query. Every edit, purge or
 * template change that re-parses the page recollects them.
 */
class PageFacts {

	/** ParserOutput extension data key */
	public const DATA_KEY = 'structureddata-facts';

	/** The page property that Extension:ArticleContentArea sets */
	private const CONTENT_AREA_PROPERTY = 'ArticleContentArea';

	/** The value ArticleContentArea stores for a content area it does not know */
	private const UNKNOWN_CONTENT_AREA = 'unknown';

	/** Page properties in which Extension:PageImages stores the page image, by preference */
	private const PAGE_IMAGE_PROPERTIES = [ 'page_image_free', 'page_image' ];

	public function __construct(
		private readonly Config $config,
		private readonly RevisionLookup $revisionLookup,
		private readonly PageStore $pageStore,
		private readonly RedirectLookup $redirectLookup,
		private readonly IConnectionProvider $connectionProvider,
		private readonly RepoGroup $repoGroup,
		private readonly UrlUtils $urlUtils
	) {
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @return array{firstRevision: ?string, contentArea: ?array, image: ?string}
	 */
	public function collect( Title $title, ParserOutput $parserOutput ): array {
		return [
			'firstRevision' => $this->config->get( 'StructuredDataArticle' )
				? $this->getFirstRevisionTimestamp( $title, $parserOutput )
				: null,
			'contentArea' => $this->config->get( 'StructuredDataBreadcrumb' )
				&& $this->config->get( 'StructuredDataBreadcrumbContentArea' )
				? $this->getContentAreaPage( $title, $parserOutput )
				: null,
			'image' => $this->config->get( 'StructuredDataArticle' )
				? $this->getImageUrl( $parserOutput )
				: null,
		];
	}

	/**
	 * A page being created has no stored first revision yet when it is first
	 * parsed; then the revision being parsed is the first.
	 */
	private function getFirstRevisionTimestamp( Title $title, ParserOutput $parserOutput ): ?string {
		$first = $title->getArticleID() > 0
			? $this->revisionLookup->getFirstRevision( $title )
			: null;
		return $first ? $first->getTimestamp() : $parserOutput->getRevisionTimestamp();
	}

	/**
	 * The page a breadcrumb names for the page's content area, as
	 * [ 'namespace' => int, 'title' => DB key ], or null.
	 *
	 * ArticleContentArea stores content areas by their name in one language
	 * ($wgStructuredDataContentAreaLanguage, Hebrew on Kol-Zchut) on every
	 * wiki. On a wiki in that language, the content area's portal is the
	 * article of that name, or the one it redirects to. On any other wiki, it
	 * is the article whose interlanguage link points at that portal, so that
	 * the crumb carries the portal's name in this wiki's language (see
	 * getPageLinkingTo()). Where neither exists, there is no crumb: a name the reader cannot see on the
	 * page, or a link to a category page that search engines are told not to
	 * index, does more harm than good.
	 */
	private function getContentAreaPage( Title $title, ParserOutput $parserOutput ): ?array {
		$area = $parserOutput->getPageProperty( self::CONTENT_AREA_PROPERTY );
		if ( !is_string( $area ) ) {
			return null;
		}
		$area = trim( $area );
		if ( $area === '' || $area === self::UNKNOWN_CONTENT_AREA ) {
			return null;
		}

		$language = $this->config->get( 'StructuredDataContentAreaLanguage' )
			?? $this->config->get( MainConfigNames::LanguageCode );
		if ( $language === $this->config->get( MainConfigNames::LanguageCode ) ) {
			$target = $this->getArticle( $area );
		} else {
			$target = $this->getPageLinkingTo( (string)$language, $area );
		}

		if ( $target === null
			|| ( $target['namespace'] === $title->getNamespace() && $target['title'] === $title->getDBkey() )
		) {
			return null;
		}
		return $target;
	}

	/**
	 * The article of that name, or the article it redirects to: some content
	 * areas are named differently from their portal, and redirect to it.
	 */
	private function getArticle( string $text ): ?array {
		$page = $this->pageStore->getExistingPageByText( $text, NS_MAIN );
		if ( $page && $page->isRedirect() ) {
			$target = $this->redirectLookup->getRedirectTarget( $page );
			$page = ( $target && !$target->isExternal() && $target->getNamespace() === NS_MAIN )
				? $this->pageStore->getPageByName( NS_MAIN, $target->getDBkey() )
				: null;
			$page = ( $page && !$page->isRedirect() ) ? $page : null;
		}
		return $page
			? [ 'namespace' => $page->getNamespace(), 'title' => $page->getDBkey() ]
			: null;
	}

	/**
	 * The local article with an interlanguage link to the portal of the
	 * content area $area, which is named in $language.
	 *
	 * Interlanguage links point at the portal article, so where the area's
	 * name is a redirect on the wiki in $language (on Kol-Zchut, he:המשפט
	 * הפלילי to he:אנשים בהליכים פליליים), the local portal links to the
	 * redirect's target, not to the name. The name is therefore resolved
	 * through that wiki first ($wgStructuredDataContentAreaWiki), and the
	 * link may point at either.
	 *
	 * Where several local articles match, the pick is deterministic: a link
	 * to the redirect's target, which is the portal itself, wins over a link
	 * to the area's name; among links to the same title, the article with the
	 * lowest page ID, which is the oldest, wins.
	 */
	private function getPageLinkingTo( string $language, string $area ): ?array {
		// Title texts, with spaces, in order of preference
		$names = [ str_replace( '_', ' ', $area ) ];
		$target = $this->getContentAreaRedirectTarget( $area );
		if ( $target !== null && $target !== $names[0] ) {
			array_unshift( $names, $target );
		}

		$titles = [];
		foreach ( $names as $name ) {
			$titles[] = $name;
			$titles[] = str_replace( ' ', '_', $name );
		}
		$rows = $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'll_title', 'page_namespace', 'page_title' ] )
			->from( 'langlinks' )
			->join( 'page', null, 'page_id = ll_from' )
			->where( [
				'll_lang' => $language,
				'll_title' => array_values( array_unique( $titles ) ),
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
			] )
			->orderBy( 'll_from' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$best = null;
		$bestRank = PHP_INT_MAX;
		foreach ( $rows as $row ) {
			$rank = array_search( str_replace( '_', ' ', (string)$row->ll_title ), $names, true );
			// Rows come lowest page ID first, so the first row of a rank wins it.
			if ( $rank !== false && $rank < $bestRank ) {
				$best = $row;
				$bestRank = $rank;
			}
		}
		return $best
			? [ 'namespace' => (int)$best->page_namespace, 'title' => (string)$best->page_title ]
			: null;
	}

	/**
	 * Where the content area's name is a redirect on the wiki in the
	 * content-area language, the title of its target, with spaces; else null.
	 *
	 * This is a query on that wiki's database, and runs at parse time only.
	 * Without $wgStructuredDataContentAreaWiki there is no such query, and
	 * only the area's name is matched.
	 */
	private function getContentAreaRedirectTarget( string $area ): ?string {
		$wiki = $this->config->get( 'StructuredDataContentAreaWiki' );
		if ( !is_string( $wiki ) || $wiki === '' ) {
			return null;
		}
		$row = $this->connectionProvider
			->getReplicaDatabase( WikiMap::isCurrentWikiId( $wiki ) ? false : $wiki )
			->newSelectQueryBuilder()
			->select( [ 'rd_namespace', 'rd_title', 'rd_interwiki' ] )
			->from( 'page' )
			->join( 'redirect', null, 'rd_from = page_id' )
			->where( [
				'page_namespace' => NS_MAIN,
				'page_title' => str_replace( ' ', '_', $area ),
				'page_is_redirect' => 1,
			] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row
			|| (int)$row->rd_namespace !== NS_MAIN
			|| (string)$row->rd_interwiki !== ''
			|| (string)$row->rd_title === ''
		) {
			return null;
		}
		return str_replace( '_', ' ', (string)$row->rd_title );
	}

	/**
	 * The page image that Extension:PageImages chose, preferring a freely
	 * licensed one.
	 */
	private function getImageUrl( ParserOutput $parserOutput ): ?string {
		foreach ( self::PAGE_IMAGE_PROPERTIES as $property ) {
			$name = $parserOutput->getPageProperty( $property );
			if ( !is_string( $name ) || $name === '' ) {
				continue;
			}
			$file = $this->repoGroup->findFile( $name );
			if ( $file && $file->exists() ) {
				return $this->urlUtils->expand( $file->getUrl(), PROTO_CANONICAL );
			}
		}
		return null;
	}
}
