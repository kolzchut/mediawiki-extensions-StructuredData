<?php
/**
 * StructuredData extension
 *
 * Adds schema.org JSON-LD to content pages: a BreadcrumbList, an Article with
 * its publisher, and an FAQPage when the page has questions and answers.
 */

namespace MediaWiki\Extension\StructuredData;

use MediaWiki\Config\Config;
use MediaWiki\Extension\StructuredData\Schema\ArticleBuilder;
use MediaWiki\Extension\StructuredData\Schema\BreadcrumbListBuilder;
use MediaWiki\Extension\StructuredData\Schema\FaqPageBuilder;
use MediaWiki\Extension\StructuredData\Schema\OrganizationBuilder;
use MediaWiki\Language\LanguageCode;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageStore;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;

class Hooks implements
	OutputPageParserOutputHook,
	BeforePageDisplayHook
{

	/**
	 * ParserOutput extension data key under which Extension:FAQElement collects
	 * a page's questions, as a list of [ 'question' => text, 'answer' => text ].
	 */
	public const FAQ_DATA_KEY = 'faqelement_items';

	/** Key of the <head> item that holds the JSON-LD */
	public const HEAD_ITEM = 'structureddata-jsonld';

	/** OutputPage property that carries the questions from the parser output */
	private const FAQ_PROPERTY = 'StructuredDataFAQ';

	/** OutputPage property that Extension:ArticleContentArea sets */
	private const CONTENT_AREA_PROPERTY = 'ArticleContentArea';

	/** The value ArticleContentArea stores for a content area it does not know */
	private const UNKNOWN_CONTENT_AREA = 'unknown';

	public function __construct(
		private readonly Config $config,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly RevisionLookup $revisionLookup,
		private readonly PageStore $pageStore,
		private readonly TitleFactory $titleFactory,
		private readonly UrlUtils $urlUtils,
		private readonly LastHumanEditLookup $lastHumanEditLookup
	) {
	}

	/**
	 * Carry FAQElement's questions from the (possibly cached) parser output to
	 * BeforePageDisplay, which has no access to the parser output.
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		$items = $parserOutput->getExtensionData( self::FAQ_DATA_KEY );
		if ( is_array( $items ) && $items ) {
			$out->setProperty( self::FAQ_PROPERTY, $items );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->isEligible( $out ) ) {
			return;
		}
		$script = JsonLd::toScript( $this->buildNodes( $out, $out->getTitle() ) );
		if ( $script !== null ) {
			$out->addHeadItem( self::HEAD_ITEM, $script );
		}
	}

	/**
	 * Only a plain view of the current revision of an existing page in one of
	 * our namespaces describes that page. Special pages, redirects, old
	 * revisions, diffs, edit/history/info and missing pages get nothing, and so
	 * does the main page, which is the site rather than an article.
	 */
	public function isEligible( OutputPage $out ): bool {
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
	 * @return array[] schema.org nodes for the page's @graph
	 */
	public function buildNodes( OutputPage $out, Title $title ): array {
		$url = $title->getCanonicalURL();
		$headline = $this->getHeadline( $out, $title );
		$nodes = [];

		if ( $this->config->get( 'StructuredDataBreadcrumb' ) ) {
			$breadcrumb = BreadcrumbListBuilder::build(
				$url . '#breadcrumb',
				$this->getCrumbs( $out, $url, $headline )
			);
			if ( $breadcrumb !== null ) {
				$nodes[] = $breadcrumb;
			}
		}

		if ( $this->config->get( 'StructuredDataArticle' ) ) {
			$homeUrl = $this->titleFactory->newMainPage()->getCanonicalURL();
			$organizationId = $homeUrl . '#organization';
			$nodes[] = OrganizationBuilder::build(
				$organizationId,
				$this->getPublisherName(),
				$homeUrl,
				$this->getPublisherLogo()
			);
			$nodes[] = ArticleBuilder::build(
				$url . '#article',
				$headline,
				$url,
				LanguageCode::bcp47( $title->getPageLanguage()->getCode() ),
				$this->revisionLookup->getFirstRevision( $title )?->getTimestamp(),
				$this->getModifiedTimestamp( $out, $title ),
				$organizationId
			);
		}

		if ( $this->shouldEmitFaq() ) {
			$items = $out->getProperty( self::FAQ_PROPERTY );
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
	 * Home › content area › page. The content area is left out when the page
	 * has none, or when no category page of that name exists on this wiki.
	 *
	 * @param OutputPage $out
	 * @param string $url
	 * @param string $headline
	 * @return array[]
	 */
	private function getCrumbs( OutputPage $out, string $url, string $headline ): array {
		$crumbs = [ [
			'name' => $out->msg( 'mainpage-description' )->inContentLanguage()->text(),
			'url' => $this->titleFactory->newMainPage()->getCanonicalURL(),
		] ];

		$area = $out->getProperty( self::CONTENT_AREA_PROPERTY );
		if ( $this->config->get( 'StructuredDataBreadcrumbContentArea' )
			&& is_string( $area )
			&& trim( $area ) !== ''
			&& $area !== self::UNKNOWN_CONTENT_AREA
		) {
			$category = $this->pageStore->getExistingPageByText( trim( $area ), NS_CATEGORY );
			if ( $category !== null ) {
				$crumbs[] = [
					'name' => trim( $area ),
					'url' => $this->titleFactory->newFromPageIdentity( $category )->getCanonicalURL(),
				];
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
	private function getModifiedTimestamp( OutputPage $out, Title $title ): ?string {
		if ( $this->lastHumanEditLookup->isAvailable() ) {
			return $this->lastHumanEditLookup->getTimestamp( $title->getArticleID() );
		}
		$timestamp = $out->getRevisionTimestamp();
		if ( $timestamp ) {
			return $timestamp;
		}
		return $this->revisionLookup->getRevisionByTitle( $title )?->getTimestamp();
	}

	/**
	 * The displayed title (DISPLAYTITLE included) as plain text, without the
	 * " (type)" suffix that Extension:WRArticleType appends to it: that is a
	 * label for readers, not part of the article's name.
	 */
	private function getHeadline( OutputPage $out, Title $title ): string {
		$html = preg_replace(
			'#<span class="article-type">.*?</span>#s', '', $out->getDisplayTitle()
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

	private function getPublisherLogo(): ?string {
		$logo = $this->config->get( 'StructuredDataPublisherLogo' );
		if ( !is_string( $logo ) || $logo === '' ) {
			return null;
		}
		return $this->urlUtils->expand( $logo, PROTO_CANONICAL );
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
