# Changelog

All notable changes to the StructuredData extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-11

### Added
- One schema.org JSON-LD `@graph` on current-revision views of content pages
- BreadcrumbList: main page, content area (from ArticleContentArea), page
- Article, with `datePublished` from the first revision, `dateModified` from the
  last human edit (from RealLastUpdate), and an Organization as its publisher
  and author
- FAQPage from FAQElement's `{{#faq:}}` questions, taken over from FAQElement,
  which emitted it itself; left out while FAQElement still does
- `$wgStructuredDataNamespaces`, publisher name and logo, and an on/off switch
  for each type
- Unit tests for the builders and an integration test on real pages
