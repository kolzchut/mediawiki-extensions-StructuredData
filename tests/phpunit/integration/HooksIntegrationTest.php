<?php

namespace MediaWiki\Extension\StructuredData\Tests\Integration;

use Closure;
use MediaWiki\Api\ApiMain;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\StructuredData\Hooks;
use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\Extension\StructuredData\PageFacts;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Language\LanguageCode;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Skin;
use TestLogger;
use Wikimedia\Rdbms\DBConnectionError;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Runs the hooks against real pages, revisions, titles and interlanguage links.
 *
 * Every page is created and edited at a fixed fake time, so no expectation
 * depends on when the suite runs.
 *
 * @group Database
 * @covers \MediaWiki\Extension\StructuredData\Hooks
 * @covers \MediaWiki\Extension\StructuredData\PageFacts
 * @covers \MediaWiki\Extension\StructuredData\JsonLd
 * @covers \MediaWiki\Extension\StructuredData\LastHumanEditLookup
 */
class HooksIntegrationTest extends MediaWikiIntegrationTestCase {

	private const CREATED = '20200102030405';
	private const EDITED = '20210607080910';
	private const HUMAN_EDIT = '20210304050607';

	private const FAQ_ITEMS = [
		[ 'question' => 'Who can apply?', 'answer' => 'Any resident over 18.' ],
		[ 'question' => 'Is it free?', 'answer' => 'Yes.' ],
	];

	/** The ID this test gives the content-area source wiki when it is not this wiki */
	private const SOURCE_WIKI = 'sd_source_wiki';

	/** $wgConf, as the tests give it to PageFacts */
	private SiteConfiguration $siteConfiguration;

	private TestLogger $logger;

	/**
	 * Stands in for PageFacts' connection to the source wiki's database, or
	 * null for the real one
	 *
	 * @var Closure|null
	 */
	private ?Closure $sourceWikiDatabase = null;

	/** @var string[] The wikis PageFacts asked for a connection to */
	private array $sourceWikiConnections = [];

	protected function setUp(): void {
		parent::setUp();
		$this->siteConfiguration = new SiteConfiguration();
		$this->logger = new TestLogger( true );
		$this->overrideConfigValues( [
			'StructuredDataNamespaces' => null,
			'StructuredDataPublisherName' => 'Kol-Zchut',
			'StructuredDataPublisherLogo' => '/logo.png',
			'StructuredDataDefaultImage' => null,
			'StructuredDataBreadcrumb' => true,
			'StructuredDataBreadcrumbContentArea' => true,
			'StructuredDataContentAreaSourceWiki' => null,
			'StructuredDataArticle' => true,
			'StructuredDataFAQ' => true,
			'FAQElementOutputSchema' => false,
			'Server' => 'https://example.org',
			'CanonicalServer' => 'https://example.org',
			'ArticlePath' => '/wiki/$1',
		] );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function newHooks( bool $realLastUpdate = false, string $entryPoint = 'index' ): Hooks {
		$services = $this->getServiceContainer();
		return new Hooks(
			$services->getMainConfig(),
			$services->getNamespaceInfo(),
			$services->getTitleFactory(),
			$services->getUrlUtils(),
			new LastHumanEditLookup( $realLastUpdate ),
			$this->newPageFacts(),
			$entryPoint
		);
	}

	private function newPageFacts(): PageFacts {
		$services = $this->getServiceContainer();
		$args = [
			$services->getMainConfig(),
			$services->getRevisionLookup(),
			$services->getPageStore(),
			$services->getRedirectLookup(),
			$services->getDBLoadBalancerFactory(),
			$services->getRepoGroup(),
			$services->getUrlUtils(),
			$this->siteConfiguration,
			$this->logger,
		];
		if ( !$this->sourceWikiDatabase ) {
			return new PageFacts( ...$args );
		}
		$connections = &$this->sourceWikiConnections;
		return new class( $this->sourceWikiDatabase, $connections, ...$args ) extends PageFacts {
			/** @var string[] */
			private array $connections;

			public function __construct( private readonly Closure $database, array &$connections, ...$args ) {
				parent::__construct( ...$args );
				$this->connections = &$connections;
			}

			protected function getSourceWikiDatabase( string $sourceWiki ): IReadableDatabase {
				$this->connections[] = $sourceWiki;
				return ( $this->database )( $sourceWiki );
			}
		};
	}

	/**
	 * Make the content-area source wiki another wiki, SOURCE_WIKI, in
	 * language xx.
	 *
	 * @param Closure|null $database Its database, from the wiki ID; the
	 *  test's own by default. Null for the real connection.
	 * @param array $wikis The wikis $wgConf lists
	 * @param array $languages $wgConf's wgLanguageCode, by wiki
	 */
	private function useSourceWiki(
		?Closure $database = null,
		array $wikis = [ self::SOURCE_WIKI ],
		array $languages = [ self::SOURCE_WIKI => 'xx' ]
	): void {
		$this->overrideConfigValue( 'StructuredDataContentAreaSourceWiki', self::SOURCE_WIKI );
		$this->siteConfiguration->wikis = array_merge( [ WikiMap::getCurrentWikiId() ], $wikis );
		$this->siteConfiguration->settings['wgLanguageCode'] = $languages;
		$this->sourceWikiDatabase = $database ?? fn () => $this->getDb();
	}

	/**
	 * The messages of the warnings logged
	 *
	 * @return string[]
	 */
	private function loggedWarnings(): array {
		return array_column( array_filter( $this->logger->getBuffer(),
			static fn ( $entry ) => $entry[0] === 'warning'
		), 1 );
	}

	/**
	 * A page created at CREATED and edited again at EDITED.
	 */
	private function makePage( string $name, int $namespace = NS_MAIN ): Title {
		$title = Title::makeTitle( $namespace, $name );
		ConvertibleTimestamp::setFakeTime( self::CREATED );
		$this->editPage( $title, 'First revision' );
		ConvertibleTimestamp::setFakeTime( self::EDITED );
		$this->editPage( $title, 'Second revision' );
		ConvertibleTimestamp::setFakeTime( false );
		return Title::makeTitle( $namespace, $name );
	}

	/**
	 * The ParserOutput as the parser leaves it for this extension: with the
	 * given page properties set during the parse, then the facts collected.
	 *
	 * @param Hooks $hooks
	 * @param Title $title
	 * @param array $pageProperties
	 * @param array|null $faqItems
	 * @return ParserOutput
	 */
	private function parse(
		Hooks $hooks, Title $title, array $pageProperties = [], ?array $faqItems = null
	): ParserOutput {
		$parserOutput = new ParserOutput();
		$parserOutput->setRevisionTimestamp( self::EDITED );
		foreach ( $pageProperties as $name => $value ) {
			$parserOutput->setPageProperty( $name, $value );
		}
		if ( $faqItems !== null ) {
			$parserOutput->setExtensionData( Hooks::FAQ_DATA_KEY, $faqItems );
		}
		$hooks->onContentAlterParserOutput( new WikitextContent( 'x' ), $title, $parserOutput );
		return $parserOutput;
	}

	/**
	 * An OutputPage as Article::view() leaves it for a plain view, with the
	 * <h1> it sets before it has the parse: the prefixed title.
	 */
	private function newView( Title $title, array $query = [], ?int $revId = null ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setRequest( new FauxRequest( $query ) );
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous() );
		$out = $context->getOutput();
		$out->setPageTitle( Parser::formatPageTitle(
			str_replace( '_', ' ', $title->getNsText() ), ':', $title->getText()
		) );
		$out->setArticleFlag( true );
		$out->setRevisionId( $revId ?? $title->getLatestRevID() );
		$out->setRevisionTimestamp( self::EDITED );
		return $out;
	}

	/**
	 * Set the title the parse displays on both the <h1> and the display title,
	 * as Article::adjustDisplayTitle() does (and WRArticleType after it).
	 */
	private static function showTitle( OutputPage $out, string $html ): void {
		$out->setPageTitle( $html );
		$out->setDisplayTitle( $html );
	}

	/**
	 * A real parse of the page as stored, with the facts collected during it,
	 * as the parser cache holds it.
	 */
	private function realParse( Hooks $hooks, Title $title ): ParserOutput {
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$parserOutput = $this->getServiceContainer()->getParserOutputAccess()->getParserOutput(
			$page, $page->makeParserOptions( 'canonical' )
		)->getValue();
		$hooks->onContentAlterParserOutput( new WikitextContent( 'x' ), $title, $parserOutput );
		return $parserOutput;
	}

	/**
	 * Run the view-time hooks and decode the JSON-LD they add.
	 *
	 * @param Hooks $hooks
	 * @param OutputPage $out
	 * @param ParserOutput|null $parserOutput Null for a fresh parse of the page
	 * @param callable|null $afterOwnHandler Another OutputPageParserOutput
	 *  handler that runs after this extension's (RealLastUpdate's, say)
	 * @return array|null
	 */
	private function render(
		Hooks $hooks, OutputPage $out, ?ParserOutput $parserOutput = null, ?callable $afterOwnHandler = null
	): ?array {
		$parserOutput ??= $this->parse( $hooks, $out->getTitle() );
		$hooks->onOutputPageParserOutput( $out, $parserOutput );
		if ( $afterOwnHandler ) {
			$afterOwnHandler( $parserOutput );
		}
		$tags = [];
		$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
		return $this->decode( $tags[Hooks::HEAD_ITEM] ?? null );
	}

	private function decode( ?string $head ): ?array {
		if ( $head === null ) {
			return null;
		}
		$this->assertMatchesRegularExpression( '#^<script type="application/ld\+json">.*</script>$#s', $head );
		$json = substr( $head, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Run the page through the hooks as OutputPage and the skin do, with this
	 * extension registered for every hook it implements and $laterHandler as a
	 * BeforePageDisplay handler registered after it, as for an extension loaded
	 * later. Decode the JSON-LD from the <head> the skin writes.
	 *
	 * @param Hooks $hooks
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @param callable $laterHandler
	 * @return array|null
	 */
	private function renderThroughHooks(
		Hooks $hooks, OutputPage $out, ParserOutput $parserOutput, callable $laterHandler
	): ?array {
		foreach ( [ 'OutputPageParserOutput', 'BeforePageDisplay', 'OutputPageAfterGetHeadLinksArray' ] as $hook ) {
			$this->clearHook( $hook );
			if ( method_exists( $hooks, "on$hook" ) ) {
				$this->setTemporaryHook( $hook, [ $hooks, "on$hook" ] );
			}
		}
		$this->setTemporaryHook( 'BeforePageDisplay', $laterHandler, false );
		$hookRunner = new HookRunner( $this->getServiceContainer()->getHookContainer() );

		$hookRunner->onOutputPageParserOutput( $out, $parserOutput );
		$hookRunner->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
		// What OutputPage::headElement() writes into the <head>, wherever the
		// JSON-LD went
		$head = array_merge( $out->getHeadLinksArray(), $out->getHeadItemsArray() );

		return $this->decode( $head[Hooks::HEAD_ITEM] ?? null );
	}

	private static function byType( array $doc ): array {
		$nodes = [];
		foreach ( $doc['@graph'] as $node ) {
			$nodes[$node['@type']] = $node;
		}
		return $nodes;
	}

	private function crumbs( Hooks $hooks, Title $title, array $pageProperties ): array {
		$doc = $this->render( $hooks, $this->newView( $title ), $this->parse( $hooks, $title, $pageProperties ) );
		return self::byType( $doc )['BreadcrumbList']['itemListElement'];
	}

	public function testArticleView() {
		$title = $this->makePage( 'SD article' );
		$hooks = $this->newHooks( true );
		$parserOutput = $this->parse( $hooks, $title );

		// RealLastUpdate fills in the date after this extension's handler has run.
		$doc = $this->render( $hooks, $this->newView( $title ), $parserOutput,
			static function ( ParserOutput $po ) {
				$po->setPageProperty( LastHumanEditLookup::PROPERTY, self::HUMAN_EDIT );
			}
		);

		$this->assertSame( 'https://schema.org', $doc['@context'] );
		$nodes = self::byType( $doc );
		$this->assertSame( [ 'BreadcrumbList', 'Organization', 'Article' ], array_keys( $nodes ) );

		$url = $title->getCanonicalURL();
		$home = Title::newMainPage()->getCanonicalURL();
		$crumbs = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertCount( 2, $crumbs );
		$this->assertSame( $home, $crumbs[0]['item'] );
		$this->assertSame( [ '@type' => 'ListItem', 'position' => 2, 'name' => 'SD article',
			'item' => $url ], $crumbs[1] );

		$this->assertSame( 'Kol-Zchut', $nodes['Organization']['name'] );
		$this->assertSame( 'https://example.org/logo.png', $nodes['Organization']['logo']['url'] );

		$article = $nodes['Article'];
		$this->assertSame( 'SD article', $article['headline'] );
		$this->assertSame( $url, $article['url'] );
		$this->assertSame(
			LanguageCode::bcp47( $title->getPageLanguage()->getCode() ),
			$article['inLanguage']
		);
		$this->assertSame( '2020-01-02T03:04:05Z', $article['datePublished'] );
		$this->assertSame( '2021-03-04T05:06:07Z', $article['dateModified'] );
		$this->assertSame( $nodes['Organization']['@id'], $article['publisher']['@id'] );
		$this->assertSame( $nodes['Organization']['@id'], $article['author']['@id'] );
		$this->assertArrayNotHasKey( 'image', $article );
	}

	public function testFirstRevisionIsCollectedAtParseTime() {
		$title = $this->makePage( 'SD parse time' );
		$parserOutput = $this->parse( $this->newHooks(), $title );

		$facts = $parserOutput->getExtensionData( PageFacts::DATA_KEY );

		$this->assertSame( self::CREATED, $facts['firstRevision'] );
		// It travels in the ParserOutput, so the parser cache keeps it.
		$codec = $this->getServiceContainer()->getJsonCodec();
		$copy = $codec->deserialize( $codec->serialize( $parserOutput ) );
		$this->assertSame( $facts, $copy->getExtensionData( PageFacts::DATA_KEY ) );
	}

	public function testANewPagesFirstRevisionIsTheOneBeingParsed() {
		$title = Title::makeTitle( NS_MAIN, 'SD not yet saved' );
		$this->getNonexistingTestPage( $title );

		$facts = $this->parse( $this->newHooks(), $title )->getExtensionData( PageFacts::DATA_KEY );

		$this->assertSame( self::EDITED, $facts['firstRevision'] );
	}

	public function testAnOldParserCacheEntryWithoutFactsGetsNoQuery() {
		$title = $this->makePage( 'SD legacy cache' );

		// Parsed before this extension was loaded: nothing to read, and no
		// query is made to fill the gap. The next parse adds the facts.
		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title ), new ParserOutput() ) );
	}

	public function testNoHumanEditOnRecordLeavesDateModifiedOut() {
		$title = $this->makePage( 'SD bot only' );

		$nodes = self::byType( $this->render( $this->newHooks( true ), $this->newView( $title ) ) );

		$this->assertSame( '2020-01-02T03:04:05Z', $nodes['Article']['datePublished'] );
		$this->assertArrayNotHasKey( 'dateModified', $nodes['Article'] );
	}

	public function testWithoutRealLastUpdateTheShownRevisionIsTheModifiedDate() {
		$title = $this->makePage( 'SD no RLU' );

		$nodes = self::byType( $this->render( $this->newHooks( false ), $this->newView( $title ) ) );

		$this->assertSame( '2021-06-07T08:09:10Z', $nodes['Article']['dateModified'] );
	}

	public static function provideThisWikiAsTheSource() {
		return [
			'null' => [ false ],
			'this wiki\'s ID' => [ true ],
		];
	}

	/**
	 * @dataProvider provideThisWikiAsTheSource
	 */
	public function testContentAreaPortalOnTheSourceWiki( bool $byId ) {
		if ( $byId ) {
			$this->overrideConfigValue( 'StructuredDataContentAreaSourceWiki', WikiMap::getCurrentWikiId() );
		}
		// The source wiki needs no $wgConf entry and no connection of its own.
		$this->sourceWikiDatabase = function () {
			$this->fail( 'No cross-wiki query on the source wiki' );
		};
		$this->makePage( 'Education' );
		$title = $this->makePage( 'SD same language' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Education' ] );

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Education', $crumbs[1]['name'] );
		$this->assertSame( Title::makeTitle( NS_MAIN, 'Education' )->getCanonicalURL(), $crumbs[1]['item'] );
		$this->assertSame( 3, $crumbs[2]['position'] );
		$this->assertSame( [], $this->loggedWarnings() );
	}

	/**
	 * @dataProvider provideThisWikiAsTheSource
	 */
	public function testContentAreaRedirectLeadsToThePortal( bool $byId ) {
		if ( $byId ) {
			$this->overrideConfigValue( 'StructuredDataContentAreaSourceWiki', WikiMap::getCurrentWikiId() );
		}
		$portal = $this->makePage( 'Real portal' );
		$this->editPage( Title::makeTitle( NS_MAIN, 'Area by another name' ), '#REDIRECT [[Real portal]]' );
		$title = $this->makePage( 'SD redirected area' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Area by another name' ] );

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Real portal', $crumbs[1]['name'] );
		$this->assertSame( $portal->getCanonicalURL(), $crumbs[1]['item'] );
	}

	public function testContentAreaPortalOnAnotherLanguagesWiki() {
		// The area's name is in another language; this wiki's portal links to it.
		$this->useSourceWiki();
		$portal = $this->makePage( 'Local education portal' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'langlinks' )
			->row( [ 'll_from' => $portal->getArticleID(), 'll_lang' => 'xx', 'll_title' => 'Foreign area' ] )
			->caller( __METHOD__ )
			->execute();
		$title = $this->makePage( 'SD other language' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Foreign area' ] );

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Local education portal', $crumbs[1]['name'] );
		$this->assertSame( $portal->getCanonicalURL(), $crumbs[1]['item'] );
	}

	/**
	 * A local article with an interlanguage link to xx:$target.
	 */
	private function makeLocalPortal( string $name, string $target ): Title {
		$portal = $this->makePage( $name );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'langlinks' )
			->row( [ 'll_from' => $portal->getArticleID(), 'll_lang' => 'xx', 'll_title' => $target ] )
			->caller( __METHOD__ )
			->execute();
		return $portal;
	}

	/**
	 * The area's name is a redirect on the source wiki, and this wiki's
	 * portal links to the redirect's target. This test's own database stands
	 * in for the source wiki's (kz_he on Kol-Zchut).
	 */
	private function makeRedirectedForeignArea( string $area, string $target ): void {
		$this->useSourceWiki();
		$this->editPage( Title::makeTitle( NS_MAIN, $area ), "#REDIRECT [[$target]]" );
	}

	public function testContentAreaRedirectOnAnotherLanguagesWikiLeadsToThePortal() {
		$this->makeRedirectedForeignArea( 'Hub area name', 'Hub area portal' );
		$portal = $this->makeLocalPortal( 'Local hub portal', 'Hub area portal' );
		$title = $this->makePage( 'SD other language redirected area' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Hub area name' ] );

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Local hub portal', $crumbs[1]['name'] );
		$this->assertSame( $portal->getCanonicalURL(), $crumbs[1]['item'] );
		// The redirect was looked up on the source wiki.
		$this->assertSame( [ self::SOURCE_WIKI ], $this->sourceWikiConnections );
		$this->assertSame( [], $this->loggedWarnings() );
	}

	public function testTheSourceWikisLanguageComesFromWgConf() {
		$this->useSourceWiki( null, [ self::SOURCE_WIKI ], [ self::SOURCE_WIKI => 'yy' ] );
		$this->makeLocalPortal( 'Local xx portal', 'Language area' );
		$yy = $this->makePage( 'Local yy portal' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'langlinks' )
			->row( [ 'll_from' => $yy->getArticleID(), 'll_lang' => 'yy', 'll_title' => 'Language area' ] )
			->caller( __METHOD__ )
			->execute();
		$title = $this->makePage( 'SD language from wgConf' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Language area' ] );

		$this->assertSame( $yy->getCanonicalURL(), $crumbs[1]['item'] );
	}

	public static function provideSourceWikisUnknownToWgConf() {
		return [
			'not listed' => [ [], [ self::SOURCE_WIKI => 'xx' ] ],
			'no language' => [ [ self::SOURCE_WIKI ], [] ],
			'an empty language' => [ [ self::SOURCE_WIKI ], [ self::SOURCE_WIKI => '' ] ],
		];
	}

	/**
	 * @dataProvider provideSourceWikisUnknownToWgConf
	 */
	public function testASourceWikiUnknownToWgConfGivesNoCrumb( array $wikis, array $languages ) {
		$this->useSourceWiki( null, $wikis, $languages );
		// A portal that would match, were the source wiki's language known
		$this->makeLocalPortal( 'Local portal of an unknown wiki', 'Unknown wiki area' );
		$title = $this->makePage( 'SD unknown source wiki' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Unknown wiki area' ] );

		$this->assertCount( 2, $crumbs );
		$this->assertSame( [], $this->sourceWikiConnections, 'no cross-wiki query' );
		$this->assertCount( 1, $this->loggedWarnings() );
		$this->assertStringContainsString( 'not in $wgConf', $this->loggedWarnings()[0] );
	}

	/**
	 * A source wiki whose database cannot be reached: one that $wgConf lists
	 * but the database server does not have, through the real connection, or
	 * a server that is down.
	 */
	public static function provideUnreachableSourceWikis() {
		return [
			'no such database' => [ null ],
			'server down' => [ static function () {
				throw new DBConnectionError( null, 'Cannot reach the source wiki' );
			} ],
		];
	}

	/**
	 * @dataProvider provideUnreachableSourceWikis
	 */
	public function testAnUnreachableSourceWikiGivesNoCrumb( ?Closure $database ) {
		$this->useSourceWiki( $database, [ self::SOURCE_WIKI ] );
		if ( !$database ) {
			// The real connection, to a database that does not exist
			$this->sourceWikiDatabase = null;
		}
		$this->makeLocalPortal( 'Local portal of an unreachable wiki', 'Unreachable wiki area' );
		$title = $this->makePage( 'SD unreachable source wiki' );
		$hooks = $this->newHooks();

		// As on a web request, where the request's own connection is in a
		// transaction when the page is parsed
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );
		try {
			$this->getDb()->newSelectQueryBuilder()->select( '1' )->from( 'page' )->caller( __METHOD__ )->fetchField();
			$crumbs = $this->crumbs( $hooks, $title, [ 'ArticleContentArea' => 'Unreachable wiki area' ] );
			// The request's own connection still works afterwards.
			$this->assertSame( $title->getArticleID(), (int)$this->getDb()->newSelectQueryBuilder()
				->select( 'page_id' )->from( 'page' )
				->where( [ 'page_namespace' => NS_MAIN, 'page_title' => $title->getDBkey() ] )
				->caller( __METHOD__ )->fetchField() );
			$lbFactory->commitPrimaryChanges( __METHOD__ );
		} finally {
			if ( $lbFactory->hasTransactionRound() ) {
				$lbFactory->rollbackPrimaryChanges( __METHOD__ );
			}
		}

		$this->assertCount( 2, $crumbs );
		$this->assertCount( 1, $this->loggedWarnings() );
		$this->assertStringContainsString( 'Could not query', $this->loggedWarnings()[0] );
	}

	public function testALinkToTheRedirectTargetWinsOverALinkToTheAreasName() {
		$this->makeRedirectedForeignArea( 'Twice linked area', 'Twice linked portal' );
		// The older article links to the name, the newer one to the target.
		$this->makeLocalPortal( 'Local page linking to the name', 'Twice linked area' );
		$portal = $this->makeLocalPortal( 'Local page linking to the target', 'Twice linked portal' );
		$title = $this->makePage( 'SD twice linked area' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Twice linked area' ] );

		$this->assertSame( $portal->getCanonicalURL(), $crumbs[1]['item'] );
	}

	public function testTheOldestOfSeveralLocalPortalsWins() {
		$this->makeRedirectedForeignArea( 'Shared area', 'Shared portal' );
		$oldest = $this->makeLocalPortal( 'Older local portal', 'Shared portal' );
		$this->makeLocalPortal( 'Newer local portal', 'Shared portal' );
		$title = $this->makePage( 'SD shared area' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Shared area' ] );

		$this->assertSame( $oldest->getCanonicalURL(), $crumbs[1]['item'] );
	}

	public static function provideSkippedContentAreas() {
		return [
			'no portal of that name' => [ 'Nonexistent area' ],
			'only a category of that name' => [ 'Category only area' ],
			'unknown' => [ 'unknown' ],
			'empty' => [ '' ],
		];
	}

	/**
	 * @dataProvider provideSkippedContentAreas
	 */
	public function testContentAreaCrumbIsSkipped( string $area ) {
		$this->makePage( 'Category only area', NS_CATEGORY );
		$title = $this->makePage( 'SD no area ' . md5( $area ) );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => $area ] );

		$this->assertCount( 2, $crumbs );
	}

	public function testNoPortalForAnotherLanguagesArea() {
		$this->useSourceWiki();
		// An article with the area's name exists here, but it is not this wiki's portal.
		$this->makePage( 'Foreign only area' );
		$title = $this->makePage( 'SD no langlink' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Foreign only area' ] );

		$this->assertCount( 2, $crumbs );
	}

	public function testThePortalItselfGetsNoMiddleCrumb() {
		$title = $this->makePage( 'Self area' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Self area' ] );

		$this->assertCount( 2, $crumbs );
	}

	public function testContentAreaSwitch() {
		$this->overrideConfigValue( 'StructuredDataBreadcrumbContentArea', false );
		$this->makePage( 'Switched area' );
		$title = $this->makePage( 'SD area off' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Switched area' ] );

		$this->assertCount( 2, $crumbs );
	}

	public function testDefaultImage() {
		$this->overrideConfigValue( 'StructuredDataDefaultImage', '/default.png' );
		$title = $this->makePage( 'SD default image' );

		$nodes = self::byType( $this->render( $this->newHooks(), $this->newView( $title ) ) );

		$this->assertSame( 'https://example.org/default.png', $nodes['Article']['image'] );
	}

	public function testMissingPageImageFileIsIgnored() {
		$title = $this->makePage( 'SD missing image' );
		$hooks = $this->newHooks();
		$parserOutput = $this->parse( $hooks, $title, [ 'page_image_free' => 'No_such_file_here.png' ] );

		$nodes = self::byType( $this->render( $hooks, $this->newView( $title ), $parserOutput ) );

		$this->assertArrayNotHasKey( 'image', $nodes['Article'] );
	}

	public function testFaqPage() {
		$title = $this->makePage( 'SD FAQ' );
		$hooks = $this->newHooks();

		$doc = $this->render( $hooks, $this->newView( $title ), $this->parse( $hooks, $title, [], self::FAQ_ITEMS ) );
		$faq = self::byType( $doc )['FAQPage'];

		$this->assertSame( $title->getCanonicalURL(), $faq['url'] );
		$this->assertCount( 2, $faq['mainEntity'] );
		$this->assertSame( 'Who can apply?', $faq['mainEntity'][0]['name'] );
		$this->assertSame( 'Any resident over 18.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function testFaqPageLeftToFaqElementWhileItStillEmitsOne() {
		$this->overrideConfigValue( 'FAQElementOutputSchema', true );
		$title = $this->makePage( 'SD FAQ left' );
		$hooks = $this->newHooks();

		$doc = $this->render( $hooks, $this->newView( $title ), $this->parse( $hooks, $title, [], self::FAQ_ITEMS ) );

		$this->assertArrayNotHasKey( 'FAQPage', self::byType( $doc ) );
	}

	public function testRealParse() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'FAQElement' ) ) {
			$this->markTestSkipped( 'FAQElement is not loaded' );
		}
		$title = $this->makePage( 'SD FAQElement' );
		ConvertibleTimestamp::setFakeTime( self::EDITED );
		$this->editPage( $title, "{{#faq: Who can &quot;apply&quot;? | Any '''resident''' over&nbsp;18. }}\n" .
			"{{#faq_group: Group title | {{#faq: Is it free? | Yes. }} }}" );
		ConvertibleTimestamp::setFakeTime( false );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$parserOutput = $this->getServiceContainer()->getParserOutputAccess()->getParserOutput(
			$page, $page->makeParserOptions( 'canonical' )
		)->getValue();
		$hooks = $this->newHooks();
		// As the wiki runs it, in ContentAlterParserOutput during the parse
		$hooks->onContentAlterParserOutput( new WikitextContent( 'x' ), $title, $parserOutput );

		$nodes = self::byType( $this->render( $hooks, $this->newView( $title ), $parserOutput ) );

		$this->assertSame( '2020-01-02T03:04:05Z', $nodes['Article']['datePublished'] );
		$faq = $nodes['FAQPage'];
		$this->assertSame( [ 'Who can "apply"?', 'Is it free?' ], array_column( $faq['mainEntity'], 'name' ) );
		$this->assertSame( 'Any resident over 18.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function testTypeSwitches() {
		$this->overrideConfigValues( [
			'StructuredDataBreadcrumb' => false,
			'StructuredDataArticle' => false,
			'StructuredDataFAQ' => false,
		] );
		$title = $this->makePage( 'SD off' );
		$hooks = $this->newHooks();

		$this->assertNull(
			$this->render( $hooks, $this->newView( $title ), $this->parse( $hooks, $title, [], self::FAQ_ITEMS ) )
		);
	}

	public function testHeadlineIsTheDisplayTitleAsText() {
		$title = $this->makePage( 'SD title' );
		$out = $this->newView( $title );
		self::showTitle( $out, '<i>Shown</i> &amp; title' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Shown & title', $nodes['Article']['headline'] );
		$this->assertSame( 'Shown & title', $nodes['BreadcrumbList']['itemListElement'][1]['name'] );
	}

	public function testHeadlineDropsTheArticleTypeSuffix() {
		$title = $this->makePage( 'SD typed' );
		$out = $this->newView( $title );
		// What WRArticleType makes of the display title
		self::showTitle( $out, '<span class="mw-page-title-main">Typed</span>'
			. '<span class="article-type"> (rights)</span>' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Typed', $nodes['Article']['headline'] );
	}

	/**
	 * A content namespace other than the main one, as teen's articles and
	 * portals live in.
	 */
	private function useRightsNamespace(): int {
		$this->overrideConfigValues( [
			MainConfigNames::ExtraNamespaces => [ 100 => 'Right', 101 => 'Right_talk' ],
			MainConfigNames::ContentNamespaces => [ NS_MAIN, 100 ],
			MainConfigNames::RestrictDisplayTitle => false,
		] );
		return 100;
	}

	public function testHeadlineInAnotherContentNamespaceFollowsTheHeading() {
		$title = $this->makePage( 'SD prefixed', $this->useRightsNamespace() );
		$hooks = $this->newHooks();
		$parserOutput = $this->realParse( $hooks, $title );
		$out = $this->newView( $title );
		self::showTitle( $out, $parserOutput->getTitleText() );

		// As core leaves it, the <h1> carries the namespace, and so does the headline.
		$nodes = self::byType( $this->render( $hooks, $out, $parserOutput ) );
		$this->assertSame( 'Right:SD prefixed', $nodes['Article']['headline'] );

		// Teen's KolzchutYoung drops the namespace from the <h1> alone, in its
		// BeforePageDisplay handler.
		$out = $this->newView( $title );
		self::showTitle( $out, $parserOutput->getTitleText() );
		$out->setPageTitle( $title->getText() );

		$nodes = self::byType( $this->render( $hooks, $out, $parserOutput ) );
		$this->assertSame( 'SD prefixed', $nodes['Article']['headline'] );
		$crumbs = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertSame( 'SD prefixed', end( $crumbs )['name'] );
	}

	public function testHeadlineIsTheFinalHeadingWhateverTheLoadOrder() {
		$title = $this->makePage( 'SD late', $this->useRightsNamespace() );
		$hooks = $this->newHooks();
		$parserOutput = $this->realParse( $hooks, $title );
		$out = $this->newView( $title );
		self::showTitle( $out, $parserOutput->getTitleText() );

		// An extension loaded after this one drops the namespace from the <h1>
		// in its BeforePageDisplay handler, as KolzchutYoung does on teen.
		$nodes = self::byType( $this->renderThroughHooks( $hooks, $out, $parserOutput,
			static function ( OutputPage $out ) {
				$out->setPageTitle( $out->getTitle()->getText() );
			}
		) );

		$this->assertSame( 'SD late', $nodes['Article']['headline'] );
		$crumbs = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertSame( 'SD late', end( $crumbs )['name'] );
	}

	public function testHeadlineIsTheDisplayTitleWhereItDiffersFromTheTitle() {
		$title = $this->makePage( 'SD display', $this->useRightsNamespace() );
		ConvertibleTimestamp::setFakeTime( self::EDITED );
		$this->editPage( $title, '{{DISPLAYTITLE:Shown <i>name</i>}} Text' );
		ConvertibleTimestamp::setFakeTime( false );
		$hooks = $this->newHooks();
		$parserOutput = $this->realParse( $hooks, $title );
		$out = $this->newView( $title );
		self::showTitle( $out, $parserOutput->getTitleText() );

		$nodes = self::byType( $this->render( $hooks, $out, $parserOutput ) );

		$this->assertSame( 'Shown name', $nodes['Article']['headline'] );
		$crumbs = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertSame( 'Shown name', end( $crumbs )['name'] );
	}

	public function testConfiguredNamespaces() {
		$title = $this->makePage( 'SD project', NS_PROJECT );

		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title ) ) );

		$this->overrideConfigValue( 'StructuredDataNamespaces', [ NS_MAIN, NS_PROJECT ] );
		$this->assertNotNull( $this->render( $this->newHooks(), $this->newView( $title ) ) );
	}

	public function testOldRevision() {
		$title = $this->makePage( 'SD old revision' );
		$oldId = $this->getServiceContainer()->getRevisionLookup()->getFirstRevision( $title )->getId();
		$out = $this->newView( $title, [ 'oldid' => $oldId ], $oldId );

		$this->assertNull( $this->render( $this->newHooks(), $out ) );
	}

	public static function provideSkippedRequests() {
		return [
			'diff' => [ [ 'diff' => 'prev' ] ],
			'edit' => [ [ 'action' => 'edit' ] ],
			'history' => [ [ 'action' => 'history' ] ],
			'info' => [ [ 'action' => 'info' ] ],
			'raw' => [ [ 'action' => 'raw' ] ],
		];
	}

	/**
	 * @dataProvider provideSkippedRequests
	 */
	public function testSkippedRequests( array $query ) {
		$title = $this->makePage( 'SD requests ' . md5( serialize( $query ) ) );

		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title, $query ) ) );
	}

	/**
	 * The <head> that api.php?action=parse&prop=headhtml writes for the page,
	 * with this extension's handlers as $hooks.
	 */
	private function apiHeadHtml( Hooks $hooks, Title $title ): string {
		$ownHooks = [ 'ContentAlterParserOutput', 'OutputPageParserOutput', 'OutputPageAfterGetHeadLinksArray' ];
		foreach ( $ownHooks as $hook ) {
			$this->setTemporaryHook( $hook, [ $hooks, "on$hook" ] );
		}
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [
			'action' => 'parse',
			'page' => $title->getPrefixedText(),
			'prop' => 'headhtml',
			'formatversion' => 2,
		] ) );
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous() );
		$api = new ApiMain( $context );
		$api->execute();
		return $api->getResult()->getResultData( [ 'parse', 'headhtml' ] );
	}

	public function testNothingForTheApi() {
		$title = $this->makePage( 'SD api' );

		// action=parse&prop=headhtml makes its OutputPage look like a plain
		// view, so it would carry the JSON-LD from anywhere but the API.
		$this->assertStringContainsString( '<script type="application/ld+json">',
			$this->apiHeadHtml( $this->newHooks(), $title ), 'outside api.php' );

		$this->assertStringNotContainsString( 'application/ld+json',
			$this->apiHeadHtml( $this->newHooks( false, 'api' ), $title ), 'api.php' );
	}

	public function testNotAnArticleView() {
		$title = $this->makePage( 'SD not article' );
		$out = $this->newView( $title );
		$out->setArticleFlag( false );

		$this->assertNull( $this->render( $this->newHooks(), $out ) );
	}

	public function testSkippedPages() {
		$hooks = $this->newHooks();

		$redirect = Title::makeTitle( NS_MAIN, 'SD redirect' );
		$this->editPage( $redirect, '#REDIRECT [[SD target]]' );
		$this->assertNull( $this->render( $hooks, $this->newView( $redirect ) ), 'redirect' );

		$talk = $this->makePage( 'SD talk', NS_TALK );
		$this->assertNull( $this->render( $hooks, $this->newView( $talk ), new ParserOutput() ), 'talk page' );

		$missing = Title::makeTitle( NS_MAIN, 'SD missing page' );
		$this->getNonexistingTestPage( $missing );
		$this->assertNull( $this->render( $hooks, $this->newView( $missing, [], 0 ) ), 'missing page' );

		$special = Title::makeTitle( NS_SPECIAL, 'RecentChanges' );
		$this->assertNull( $this->render( $hooks, $this->newView( $special, [], 0 ), new ParserOutput() ),
			'special page' );

		$main = Title::newMainPage();
		$this->getExistingTestPage( $main );
		$this->assertNull( $this->render( $hooks, $this->newView( $main ) ), 'main page' );
	}

	public function testNoFactsOutsideTheNamespaces() {
		$title = $this->makePage( 'SD talk facts', NS_TALK );

		$parserOutput = $this->parse( $this->newHooks(), $title );

		$this->assertNull( $parserOutput->getExtensionData( PageFacts::DATA_KEY ) );
	}
}
