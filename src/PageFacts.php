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
	 * the crumb carries the portal's name in this wiki's language. Where
	 * neither exists, there is no crumb: a name the reader cannot see on the
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
	 * The local article with an interlanguage link to $language:$text.
	 */
	private function getPageLinkingTo( string $language, string $text ): ?array {
		$row = $this->connectionProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'langlinks' )
			->join( 'page', null, 'page_id = ll_from' )
			->where( [
				'll_lang' => $language,
				'll_title' => [ $text, str_replace( ' ', '_', $text ) ],
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
			] )
			->orderBy( 'll_from' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		return $row
			? [ 'namespace' => (int)$row->page_namespace, 'title' => (string)$row->page_title ]
			: null;
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
