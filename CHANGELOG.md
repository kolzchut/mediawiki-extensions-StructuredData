# Changelog

All notable changes to the StructuredData extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Breaking:** `$wgStructuredDataContentAreaSourceWiki`, the ID of the wiki
  whose content-area names ArticleContentArea stores, replaces
  `$wgStructuredDataContentAreaLanguage` and `$wgStructuredDataContentAreaWiki`,
  which said the same thing twice and could disagree. The source wiki's
  language now comes from `$wgConf` (its `wgLanguageCode`), which the site must
  fill in. MediaWiki ignores the old settings silently: a site that still sets
  only them gets no content-area crumb on its other wikis

### Fixed
- A content-area source wiki that `$wgConf` does not list, or whose database
  cannot be queried, no longer fails the parse of every page with a content
  area. The crumb is left out and a warning is logged to the `StructuredData`
  channel. The cross-wiki query runs on a connection of its own, so its failure
  cannot leave the request's own transaction in an error state
- The Article `headline` and the last breadcrumb are the page's `<h1>`, so on
  teen's article and portal namespaces they no longer carry the namespace
  prefix (`זכות:`, `פורטל:`) that KolzchutYoung drops from the heading. The
  JSON-LD is now written with the `<head>`, after every `BeforePageDisplay`
  handler, so the headline is the final `<h1>` whatever the load order
- The ar and ru breadcrumb has its middle crumb when the content area's Hebrew
  name is a redirect to its portal: the name is resolved to the redirect's
  target on the source wiki at parse time, and a local portal linking to either
  counts

## [0.1.0] - 2026-11-11

### Added
- One schema.org JSON-LD `@graph` on current-revision views of content pages
- BreadcrumbList: main page, the portal of the page's content area (from
  ArticleContentArea) in the wiki's own language, page
- Article, with `datePublished` from the first revision, `dateModified` from the
  last human edit (from RealLastUpdate), the page image (from PageImages), and
  an Organization as its publisher and author
- No query on a page view: what needs one is collected at parse time and
  parser-cached
- FAQPage from FAQElement's `{{#faq:}}` questions, taken over from FAQElement,
  which emitted it itself; left out while FAQElement still does
- `$wgStructuredDataNamespaces`, publisher name and logo, content-area
  language, default image, and an on/off switch for each type
- Unit tests for the builders and an integration test on real pages
