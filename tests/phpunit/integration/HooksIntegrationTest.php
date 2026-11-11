<?php

namespace MediaWiki\Extension\StructuredData\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\StructuredData\Hooks;
use MediaWiki\Extension\StructuredData\LastHumanEditLookup;
use MediaWiki\Language\LanguageCode;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Skin;

/**
 * Runs the hooks against real pages, revisions and titles.
 *
 * @group Database
 * @covers \MediaWiki\Extension\StructuredData\Hooks
 * @covers \MediaWiki\Extension\StructuredData\JsonLd
 * @covers \MediaWiki\Extension\StructuredData\LastHumanEditLookup
 */
class HooksIntegrationTest extends MediaWikiIntegrationTestCase {

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
			'StructuredDataBreadcrumb' => true,
			'StructuredDataBreadcrumbContentArea' => true,
			'StructuredDataArticle' => true,
			'StructuredDataFAQ' => true,
			'FAQElementOutputSchema' => false,
			'Server' => 'https://example.org',
			'CanonicalServer' => 'https://example.org',
			'ArticlePath' => '/wiki/$1',
		] );
	}

	private function newHooks( ?string $lastHumanEdit = null, bool $realLastUpdate = false ): Hooks {
		$services = $this->getServiceContainer();
		$lookup = $this->createMock( LastHumanEditLookup::class );
		$lookup->method( 'isAvailable' )->willReturn( $realLastUpdate );
		$lookup->method( 'getTimestamp' )->willReturn( $lastHumanEdit );
		return new Hooks(
			$services->getMainConfig(),
			$services->getNamespaceInfo(),
			$services->getRevisionLookup(),
			$services->getPageStore(),
			$services->getTitleFactory(),
			$services->getUrlUtils(),
			$lookup
		);
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
		return $out;
	}

	private function render( Hooks $hooks, OutputPage $out, ?ParserOutput $parserOutput = null ): ?array {
		$hooks->onOutputPageParserOutput( $out, $parserOutput ?? new ParserOutput() );
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

	private function faqOutput(): ParserOutput {
		$parserOutput = new ParserOutput();
		$parserOutput->setExtensionData( Hooks::FAQ_DATA_KEY, self::FAQ_ITEMS );
		return $parserOutput;
	}

	public function testArticleView() {
		$page = $this->getExistingTestPage( 'StructuredData article' );
		$title = $page->getTitle();
		$this->editPage( $page, 'Second revision' );
		$first = $this->getServiceContainer()->getRevisionLookup()->getFirstRevision( $title );

		$doc = $this->render( $this->newHooks( '20261111093000', true ), $this->newView( $title ) );

		$this->assertSame( 'https://schema.org', $doc['@context'] );
		$nodes = self::byType( $doc );
		$this->assertSame( [ 'BreadcrumbList', 'Organization', 'Article' ], array_keys( $nodes ) );

		$url = $title->getCanonicalURL();
		$home = Title::newMainPage()->getCanonicalURL();
		$crumbs = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertCount( 2, $crumbs );
		$this->assertSame( $home, $crumbs[0]['item'] );
		$this->assertSame( [ '@type' => 'ListItem', 'position' => 2, 'name' => 'StructuredData article',
			'item' => $url ], $crumbs[1] );

		$this->assertSame( 'Kol-Zchut', $nodes['Organization']['name'] );
		$this->assertSame( 'https://example.org/logo.png', $nodes['Organization']['logo']['url'] );

		$article = $nodes['Article'];
		$this->assertSame( 'StructuredData article', $article['headline'] );
		$this->assertSame( $url, $article['url'] );
		$this->assertSame(
			LanguageCode::bcp47( $title->getPageLanguage()->getCode() ),
			$article['inLanguage']
		);
		$this->assertSame(
			wfTimestamp( TS_ISO_8601, $first->getTimestamp() ),
			$article['datePublished']
		);
		$this->assertSame( '2026-11-11T09:30:00Z', $article['dateModified'] );
		$this->assertSame( $nodes['Organization']['@id'], $article['publisher']['@id'] );
		$this->assertSame( $nodes['Organization']['@id'], $article['author']['@id'] );
	}

	public function testNoHumanEditOnRecordLeavesDateModifiedOut() {
		$title = $this->getExistingTestPage( 'StructuredData bot-only' )->getTitle();

		$nodes = self::byType( $this->render( $this->newHooks( null, true ), $this->newView( $title ) ) );

		$this->assertArrayHasKey( 'datePublished', $nodes['Article'] );
		$this->assertArrayNotHasKey( 'dateModified', $nodes['Article'] );
	}

	public function testWithoutRealLastUpdateTheLatestRevisionIsTheModifiedDate() {
		$page = $this->getExistingTestPage( 'StructuredData no RLU' );
		$status = $this->editPage( $page, 'Later revision' );
		$latest = $status->getNewRevision();

		$nodes = self::byType( $this->render( $this->newHooks( null, false ), $this->newView( $page->getTitle() ) ) );

		$this->assertSame(
			wfTimestamp( TS_ISO_8601, $latest->getTimestamp() ),
			$nodes['Article']['dateModified']
		);
	}

	public function testContentAreaCrumbLinksToItsCategory() {
		$this->getExistingTestPage( Title::makeTitle( NS_CATEGORY, 'Education' ) );
		$title = $this->getExistingTestPage( 'StructuredData area' )->getTitle();
		$out = $this->newView( $title );
		$out->setProperty( 'ArticleContentArea', 'Education' );

		$crumbs = self::byType( $this->render( $this->newHooks(), $out ) )['BreadcrumbList']['itemListElement'];

		$this->assertCount( 3, $crumbs );
		$this->assertSame( 'Education', $crumbs[1]['name'] );
		$this->assertSame( Title::makeTitle( NS_CATEGORY, 'Education' )->getCanonicalURL(), $crumbs[1]['item'] );
		$this->assertSame( 3, $crumbs[2]['position'] );
	}

	public static function provideSkippedContentAreas() {
		return [
			'no such category page' => [ 'Nonexistent area ' . __CLASS__ ],
			'unknown' => [ 'unknown' ],
			'empty' => [ '' ],
			'unset' => [ null ],
		];
	}

	/**
	 * @dataProvider provideSkippedContentAreas
	 */
	public function testContentAreaCrumbIsSkipped( ?string $area ) {
		$title = $this->getExistingTestPage( 'StructuredData no area' )->getTitle();
		$out = $this->newView( $title );
		$out->setProperty( 'ArticleContentArea', $area );

		$crumbs = self::byType( $this->render( $this->newHooks(), $out ) )['BreadcrumbList']['itemListElement'];

		$this->assertCount( 2, $crumbs );
	}

	public function testContentAreaSwitch() {
		$this->getExistingTestPage( Title::makeTitle( NS_CATEGORY, 'Education' ) );
		$this->overrideConfigValue( 'StructuredDataBreadcrumbContentArea', false );
		$title = $this->getExistingTestPage( 'StructuredData area off' )->getTitle();
		$out = $this->newView( $title );
		$out->setProperty( 'ArticleContentArea', 'Education' );

		$crumbs = self::byType( $this->render( $this->newHooks(), $out ) )['BreadcrumbList']['itemListElement'];

		$this->assertCount( 2, $crumbs );
	}

	public function testFaqPage() {
		$title = $this->getExistingTestPage( 'StructuredData FAQ' )->getTitle();

		$doc = $this->render( $this->newHooks(), $this->newView( $title ), $this->faqOutput() );
		$faq = self::byType( $doc )['FAQPage'];

		$this->assertSame( $title->getCanonicalURL(), $faq['url'] );
		$this->assertCount( 2, $faq['mainEntity'] );
		$this->assertSame( 'Who can apply?', $faq['mainEntity'][0]['name'] );
		$this->assertSame( 'Any resident over 18.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function testFaqPageLeftToFaqElementWhileItStillEmitsOne() {
		$this->overrideConfigValue( 'FAQElementOutputSchema', true );
		$title = $this->getExistingTestPage( 'StructuredData FAQ' )->getTitle();

		$doc = $this->render( $this->newHooks(), $this->newView( $title ), $this->faqOutput() );

		$this->assertArrayNotHasKey( 'FAQPage', self::byType( $doc ) );
	}

	public function testRealFaqElementParse() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'FAQElement' ) ) {
			$this->markTestSkipped( 'FAQElement is not loaded' );
		}
		$page = $this->getExistingTestPage( 'StructuredData FAQElement' );
		$this->editPage( $page, "{{#faq: Who can apply? | Any '''resident''' over 18. }}\n" .
			"{{#faq_group: Group title | {{#faq: Is it free? | Yes. }} }}" );
		$parserOutput = $this->getServiceContainer()->getParserOutputAccess()->getParserOutput(
			$page, $page->makeParserOptions( 'canonical' )
		)->getValue();

		$doc = $this->render( $this->newHooks(), $this->newView( $page->getTitle() ), $parserOutput );
		$faq = self::byType( $doc )['FAQPage'];

		$this->assertSame( [ 'Who can apply?', 'Is it free?' ], array_column( $faq['mainEntity'], 'name' ) );
		$this->assertSame( 'Any resident over 18.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function testTypeSwitches() {
		$this->overrideConfigValues( [
			'StructuredDataBreadcrumb' => false,
			'StructuredDataArticle' => false,
			'StructuredDataFAQ' => false,
		] );
		$title = $this->getExistingTestPage( 'StructuredData off' )->getTitle();

		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title ), $this->faqOutput() ) );
	}

	public function testHeadlineIsTheDisplayTitleAsText() {
		$title = $this->getExistingTestPage( 'StructuredData title' )->getTitle();
		$out = $this->newView( $title );
		$out->setDisplayTitle( '<i>Shown</i> &amp; title' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Shown & title', $nodes['Article']['headline'] );
		$this->assertSame( 'Shown & title', $nodes['BreadcrumbList']['itemListElement'][1]['name'] );
	}

	public function testHeadlineDropsTheArticleTypeSuffix() {
		$title = $this->getExistingTestPage( 'StructuredData typed' )->getTitle();
		$out = $this->newView( $title );
		// What WRArticleType makes of the display title
		$out->setDisplayTitle( '<span class="mw-page-title-main">Typed</span>'
			. '<span class="article-type"> (rights)</span>' );

		$nodes = self::byType( $this->render( $this->newHooks(), $out ) );

		$this->assertSame( 'Typed', $nodes['Article']['headline'] );
	}

	public function testConfiguredNamespaces() {
		$title = $this->getExistingTestPage( Title::makeTitle( NS_PROJECT, 'StructuredData project' ) )->getTitle();

		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title ) ) );

		$this->overrideConfigValue( 'StructuredDataNamespaces', [ NS_MAIN, NS_PROJECT ] );
		$this->assertNotNull( $this->render( $this->newHooks(), $this->newView( $title ) ) );
	}

	public function testOldRevision() {
		$page = $this->getExistingTestPage( 'StructuredData old revision' );
		$oldId = $page->getLatest();
		$this->editPage( $page, 'Newer text' );
		$title = $page->getTitle();

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
		$title = $this->getExistingTestPage( 'StructuredData requests' )->getTitle();

		$this->assertNull( $this->render( $this->newHooks(), $this->newView( $title, $query ) ) );
	}

	public function testNotAnArticleView() {
		$title = $this->getExistingTestPage( 'StructuredData not article' )->getTitle();
		$out = $this->newView( $title );
		$out->setArticleFlag( false );

		$this->assertNull( $this->render( $this->newHooks(), $out ) );
	}

	public function testSkippedPages() {
		$hooks = $this->newHooks();

		$redirect = Title::makeTitle( NS_MAIN, 'StructuredData redirect' );
		$this->editPage( $redirect, '#REDIRECT [[StructuredData target]]' );
		$this->assertNull( $this->render( $hooks, $this->newView( $redirect ) ), 'redirect' );

		$talk = $this->getExistingTestPage( Title::makeTitle( NS_TALK, 'StructuredData talk' ) )->getTitle();
		$this->assertNull( $this->render( $hooks, $this->newView( $talk ) ), 'talk page' );

		$missing = Title::makeTitle( NS_MAIN, 'StructuredData missing page' );
		$this->getNonexistingTestPage( $missing );
		$this->assertNull( $this->render( $hooks, $this->newView( $missing, [], 0 ) ), 'missing page' );

		$special = Title::makeTitle( NS_SPECIAL, 'RecentChanges' );
		$this->assertNull( $this->render( $hooks, $this->newView( $special, [], 0 ) ), 'special page' );

		$main = Title::newMainPage();
		$this->getExistingTestPage( $main );
		$this->assertNull( $this->render( $hooks, $this->newView( $main ) ), 'main page' );
	}
}
