<?php
/**
 * StructuredData extension
 *
 * Adds schema.org JSON-LD to content pages: a BreadcrumbList, an Article with
 * its publisher, and an FAQPage when the page has questions and answers.
 *
 * Everything that needs a database query is found out when the page is parsed
 * (PageFacts) and travels in the parser-cached ParserOutput, so that a page
 * view adds no query.
 */

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Config\Config;
use MediaWiki\Content\Hook\ContentAlterParserOutputHook;
use MediaWiki\Extension\StructuredData\Schema\ArticleBuilder;
use MediaWiki\Extension\StructuredData\Schema\BreadcrumbListBuilder;
use MediaWiki\Extension\StructuredData\Schema\FaqPageBuilder;
use MediaWiki\Extension\StructuredData\Schema\OrganizationBuilder;
use MediaWiki\Language\LanguageCode;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\OutputPageAfterGetHeadLinksArrayHook;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;

class Hooks implements
	ContentAlterParserOutputHook,
	OutputPageParserOutputHook,
	OutputPageAfterGetHeadLinksArrayHook
{

	/**
	 * ParserOutput extension data key under which Extension:FAQElement collects
	 * a page's questions, as a list of [ 'question' => text, 'answer' => text ].
	 */
	public const FAQ_DATA_KEY = 'faqelement_items';

	/** Key, among the <head> links, of the tag that holds the JSON-LD */
	public const HEAD_ITEM = 'structureddata-jsonld';

	/**
	 * OutputPage property that carries the page's ParserOutput from
	 * OutputPageParserOutput to OutputPageAfterGetHeadLinksArray, which has no
	 * access to it
	 */
	private const PARSER_OUTPUT_PROPERTY = 'StructuredDataParserOutput';

	public function __construct(
		private readonly Config $config,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly TitleFactory $titleFactory,
		private readonly UrlUtils $urlUtils,
		private readonly LastHumanEditLookup $lastHumanEditLookup,
		private readonly PageFacts $pageFacts,
		private readonly string $entryPoint = MW_ENTRY_POINT
	) {
	}

	/**
	 * Collect the page's facts at parse time, so they are parser-cached.
	 *
	 * @inheritDoc
	 */
	public function onContentAlterParserOutput( $content, $title, $parserOutput ) {
		if ( !$title->canExist() || !$this->isStructuredNamespace( $title->getNamespace() ) ) {
			return;
		}
		$parserOutput->setExtensionData( PageFacts::DATA_KEY, $this->pageFacts->collect( $title, $parserOutput ) );
	}

	/**
	 * Keep the page's ParserOutput for OutputPageAfterGetHeadLinksArray. It is
	 * read there, not here, because RealLastUpdate fills in the last human edit
	 * in its own handler of this hook, which may run after this one.
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		if ( $parserOutput->getExtensionData( PageFacts::DATA_KEY ) !== null
			|| $parserOutput->getExtensionData( self::FAQ_DATA_KEY )
		) {
			$out->setProperty( self::PARSER_OUTPUT_PROPERTY, $parserOutput );
		}
	}

	/**
	 * Add the JSON-LD to the <head> as it is being written.
	 *
	 * OutputPage::headElement() runs this hook when the skin writes the
	 * <head>, which it does after the page body and after every
	 * BeforePageDisplay handler. So the <h1> read here is the final one, whatever
	 * the order in which extensions that change it are loaded. Only a skinned
	 * HTML page writes a <head>, so action=raw and most of the API never run
	 * it. The exception, action=parse with prop=headhtml, is turned away by
	 * isEligible().
	 *
	 * @inheritDoc
	 */
	public function onOutputPageAfterGetHeadLinksArray( &$tags, $out ): void {
		if ( !$this->isEligible( $out ) ) {
			return;
		}
		$parserOutput = $out->getProperty( self::PARSER_OUTPUT_PROPERTY );
		if ( !$parserOutput instanceof ParserOutput ) {
			return;
		}
		$script = JsonLd::toScript( $this->buildNodes( $out, $out->getTitle(), $parserOutput ) );
		if ( $script !== null ) {
			$tags[self::HEAD_ITEM] = $script;
		}
	}

	/**
	 * Only a plain view of the current revision of an existing page in one of
	 * our namespaces describes that page. Special pages, redirects, old
	 * revisions, diffs, edit/history/info and missing pages get nothing, and so
	 * does the main page, which is the site rather than an article.
	 *
	 * Nor does the API. action=parse with prop=headhtml writes a <head> for an
	 * OutputPage that it makes look like a plain view (action=view, article
	 * flag set), but it runs no BeforePageDisplay, so the <h1> there is not
	 * the reader's (on teen it keeps the namespace that KolzchutYoung drops).
	 * Its request is a FauxRequest, so the entry point is what tells it apart.
	 */
	public function isEligible( OutputPage $out ): bool {
		if ( $this->entryPoint === 'api' ) {
			return false;
		}
		$title = $out->getTitle();
		if ( !$title
			|| !$title->canExist()
			|| !$this->isStructuredNamespace( $title->getNamespace() )
			|| !$title->exists()
			|| $title->isRedirect()
			|| $title->isMainPage()
		) {
			return false;
		}
		if ( !$out->isArticle()
			|| !$out->isRevisionCurrent()
			|| $out->getContext()->getActionName() !== 'view'
			|| $out->getRequest()->getCheck( 'diff' )
		) {
			return false;
		}
		return true;
	}

	/**
	 * @param OutputPage $out
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @return array[] schema.org nodes for the page's @graph
	 */
	public function buildNodes( OutputPage $out, Title $title, ParserOutput $parserOutput ): array {
		$facts = $parserOutput->getExtensionData( PageFacts::DATA_KEY );
		$facts = is_array( $facts ) ? $facts : [];
		$url = $title->getCanonicalURL();
		$headline = $this->getHeadline( $out, $title );
		$homeUrl = $this->titleFactory->newMainPage()->getCanonicalURL();
		$nodes = [];

		if ( $this->config->get( 'StructuredDataBreadcrumb' ) ) {
			$breadcrumb = BreadcrumbListBuilder::build(
				$url . '#breadcrumb',
				$this->getCrumbs( $out, $homeUrl, $facts['contentArea'] ?? null, $url, $headline )
			);
			if ( $breadcrumb !== null ) {
				$nodes[] = $breadcrumb;
			}
		}

		if ( $this->config->get( 'StructuredDataArticle' ) ) {
			$organizationId = $homeUrl . '#organization';
			$nodes[] = OrganizationBuilder::build(
				$organizationId,
				$this->getPublisherName(),
				$homeUrl,
				$this->expandConfigUrl( 'StructuredDataPublisherLogo' )
			);
			$nodes[] = ArticleBuilder::build(
				$url . '#article',
				$headline,
				$url,
				LanguageCode::bcp47( $title->getPageLanguage()->getCode() ),
				$facts['firstRevision'] ?? null,
				$this->getModifiedTimestamp( $out, $parserOutput ),
				$organizationId,
				$facts['image'] ?? $this->expandConfigUrl( 'StructuredDataDefaultImage' )
			);
		}

		if ( $this->shouldEmitFaq() ) {
			$items = $parserOutput->getExtensionData( self::FAQ_DATA_KEY );
			if ( is_array( $items ) ) {
				$faq = FaqPageBuilder::build( $url . '#faq', $url, $items );
				if ( $faq !== null ) {
					$nodes[] = $faq;
				}
			}
		}

		return $nodes;
	}

	/**
	 * Home › content area › page. The content area's page was found at parse
	 * time (PageFacts); without one there is no middle crumb.
	 *
	 * @param OutputPage $out
	 * @param string $homeUrl
	 * @param array|null $contentArea [ 'namespace' => int, 'title' => DB key ]
	 * @param string $url
	 * @param string $headline
	 * @return array[]
	 */
	private function getCrumbs(
		OutputPage $out, string $homeUrl, ?array $contentArea, string $url, string $headline
	): array {
		$crumbs = [ [
			'name' => $out->msg( 'mainpage-description' )->inContentLanguage()->text(),
			'url' => $homeUrl,
		] ];

		if ( $this->config->get( 'StructuredDataBreadcrumbContentArea' )
			&& isset( $contentArea['namespace'] )
			&& isset( $contentArea['title'] )
		) {
			$areaTitle = $this->titleFactory->makeTitleSafe(
				(int)$contentArea['namespace'], (string)$contentArea['title']
			);
			if ( $areaTitle ) {
				$crumbs[] = [ 'name' => $areaTitle->getText(), 'url' => $areaTitle->getCanonicalURL() ];
			}
		}

		$crumbs[] = [ 'name' => $headline, 'url' => $url ];
		return $crumbs;
	}

	/**
	 * The last human edit when RealLastUpdate is loaded; with no human edit on
	 * record, nothing, so that a bot edit is never presented as an update.
	 * Without RealLastUpdate, the revision being shown, which is the latest.
	 */
	private function getModifiedTimestamp( OutputPage $out, ParserOutput $parserOutput ): ?string {
		if ( $this->lastHumanEditLookup->isAvailable() ) {
			return $this->lastHumanEditLookup->getTimestamp( $parserOutput );
		}
		$timestamp = $out->getRevisionTimestamp() ?: $parserOutput->getRevisionTimestamp();
		return $timestamp ?: null;
	}

	/**
	 * The page's <h1> as plain text, without the " (type)" suffix that
	 * Extension:WRArticleType appends to it: that is a label for readers, not
	 * part of the article's name.
	 *
	 * The <h1> is the OutputPage's page title, not its display title. Core
	 * sets both from the parse (DISPLAYTITLE included), but an extension may
	 * change the <h1> alone: on the teen site, KolzchutYoung drops the
	 * namespace prefix of its article and portal namespaces from it, while the
	 * display title keeps the prefix. It does so in its own BeforePageDisplay
	 * handler; this is read later, when the <head> is written, so it sees the
	 * final <h1> whatever the load order. Without a page title, the display
	 * title stands in.
	 */
	private function getHeadline( OutputPage $out, Title $title ): string {
		$pageTitle = $out->getPageTitle();
		$html = preg_replace(
			'#<span class="article-type">.*?</span>#s',
			'',
			$pageTitle !== '' ? $pageTitle : $out->getDisplayTitle()
		) ?? '';
		$headline = trim( Sanitizer::stripAllTags( $html ) );
		return $headline !== '' ? $headline : $title->getPrefixedText();
	}

	private function getPublisherName(): string {
		$name = $this->config->get( 'StructuredDataPublisherName' );
		return is_string( $name ) && $name !== ''
			? $name
			: (string)$this->config->get( MainConfigNames::Sitename );
	}

	private function expandConfigUrl( string $name ): ?string {
		$value = $this->config->get( $name );
		if ( !is_string( $value ) || $value === '' ) {
			return null;
		}
		return $this->urlUtils->expand( $value, PROTO_CANONICAL );
	}

	/**
	 * FAQElement emitted its own FAQPage before this extension took that over.
	 * While a FAQElement that still does so is loaded with the output switched
	 * on, this extension leaves the FAQPage to it, so that a page never carries
	 * two. Once FAQElement no longer defines $wgFAQElementOutputSchema, this
	 * check is always false and can be removed.
	 */
	private function shouldEmitFaq(): bool {
		if ( !$this->config->get( 'StructuredDataFAQ' ) ) {
			return false;
		}
		return !( $this->config->has( 'FAQElementOutputSchema' )
			&& $this->config->get( 'FAQElementOutputSchema' ) );
	}

	private function isStructuredNamespace( int $namespace ): bool {
		$namespaces = $this->config->get( 'StructuredDataNamespaces' );
		return $namespaces === null
			? $this->namespaceInfo->isContent( $namespace )
			: in_array( $namespace, (array)$namespaces, true );
	}
}
