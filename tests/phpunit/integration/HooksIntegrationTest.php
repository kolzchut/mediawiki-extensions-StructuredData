<?php

namespace MediaWiki\Extension\StructuredData\Tests\Integration;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\StructuredData\Hooks;
use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\Extension\StructuredData\PageFacts;
use MediaWiki\Language\LanguageCode;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Skin;
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

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'StructuredDataNamespaces' => null,
			'StructuredDataPublisherName' => 'Kol-Zchut',
			'StructuredDataPublisherLogo' => '/logo.png',
			'StructuredDataDefaultImage' => null,
			'StructuredDataBreadcrumb' => true,
			'StructuredDataBreadcrumbContentArea' => true,
			'StructuredDataContentAreaLanguage' => null,
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

	private function newHooks( bool $realLastUpdate = false ): Hooks {
		$services = $this->getServiceContainer();
		return new Hooks(
			$services->getMainConfig(),
			$services->getNamespaceInfo(),
			$services->getTitleFactory(),
			$services->getUrlUtils(),
			new LastHumanEditLookup( $realLastUpdate ),
			new PageFacts(
				$services->getMainConfig(),
				$services->getRevisionLookup(),
				$services->getPageStore(),
				$services->getRedirectLookup(),
				$services->getConnectionProvider(),
				$services->getRepoGroup(),
				$services->getUrlUtils()
			)
		);
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
	 * An OutputPage as Article::view() leaves it for a plain view.
	 */
	private function newView( Title $title, array $query = [], ?int $revId = null ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setRequest( new FauxRequest( $query ) );
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous() );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		$out->setRevisionId( $revId ?? $title->getLatestRevID() );
		$out->setRevisionTimestamp( self::EDITED );
		return $out;
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
		$hooks->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
		$head = $out->getHeadItemsArray()[Hooks::HEAD_ITEM] ?? null;
		if ( $head === null ) {
			return null;
		}
		$this->assertMatchesRegularExpression( '#^<script type="application/ld\+json">.*</script>$#s', $head );
		$json = substr( $head, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
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

	public function testContentAreaPortalOnAWikiInTheAreasLanguage() {
		$this->makePage( 'Education' );
		$title = $this->makePage( 'SD same language' );

		$crumbs = $this->crumbs( $this->newHooks(), $title, [ 'ArticleContentArea' => 'Education' ] );

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Education', $crumbs[1]['name'] );
		$this->assertSame( Title::makeTitle( NS_MAIN, 'Education' )->getCanonicalURL(), $crumbs[1]['item'] );
		$this->assertSame( 3, $crumbs[2]['position'] );
	}

	public function testContentAreaRedirectLeadsToThePortal() {
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
		$this->overrideConfigValue( 'StructuredDataContentAreaLanguage', 'xx' );
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
		$this->overrideConfigValue( 'StructuredDataContentAreaLanguage', 'xx' );
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
		$out->setDisplayTitle( '<i>Shown</i> &amp; title' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Shown & title', $nodes['Article']['headline'] );
		$this->assertSame( 'Shown & title', $nodes['BreadcrumbList']['itemListElement'][1]['name'] );
	}

	public function testHeadlineDropsTheArticleTypeSuffix() {
		$title = $this->makePage( 'SD typed' );
		$out = $this->newView( $title );
		// What WRArticleType makes of the display title
		$out->setDisplayTitle( '<span class="mw-page-title-main">Typed</span>'
			. '<span class="article-type"> (rights)</span>' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Typed', $nodes['Article']['headline'] );
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
