StructuredData extension for MediaWiki
======================================

StructuredData adds [schema.org](https://schema.org) JSON-LD structured data
(a BreadcrumbList, an Article and, where the page has questions and answers, an
FAQPage) to the content pages of the Kol-Zchut wikis, so that search engines can
read a page's place on the site, its dates and its publisher. It targets
MediaWiki 1.43.

## Installation

```php
wfLoadExtension( 'WikiRights/StructuredData' );
```

## What it emits

One `<script type="application/ld+json">` in the `<head>`, holding a single
`@graph`. The nodes refer to each other by `@id`, so the Organization is written
once and the Article points at it as both publisher and author.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "BreadcrumbList",
      "@id": "https://www.kolzchut.org.il/he/Page#breadcrumb",
      "itemListElement": [
        { "@type": "ListItem", "position": 1, "name": "עמוד ראשי", "item": "https://www.kolzchut.org.il/he/עמוד_ראשי" },
        { "@type": "ListItem", "position": 2, "name": "חינוך", "item": "https://www.kolzchut.org.il/he/קטגוריה:חינוך" },
        { "@type": "ListItem", "position": 3, "name": "Page", "item": "https://www.kolzchut.org.il/he/Page" }
      ]
    },
    {
      "@type": "Organization",
      "@id": "https://www.kolzchut.org.il/he/עמוד_ראשי#organization",
      "name": "כל-זכות",
      "url": "https://www.kolzchut.org.il/he/עמוד_ראשי",
      "logo": { "@type": "ImageObject", "url": "https://www.kolzchut.org.il/logo.png" }
    },
    {
      "@type": "Article",
      "@id": "https://www.kolzchut.org.il/he/Page#article",
      "headline": "Page",
      "url": "https://www.kolzchut.org.il/he/Page",
      "mainEntityOfPage": "https://www.kolzchut.org.il/he/Page",
      "inLanguage": "he",
      "datePublished": "2012-03-04T05:06:07Z",
      "dateModified": "2026-11-11T09:30:00Z",
      "publisher": { "@id": "https://www.kolzchut.org.il/he/עמוד_ראשי#organization" },
      "author": { "@id": "https://www.kolzchut.org.il/he/עמוד_ראשי#organization" }
    },
    {
      "@type": "FAQPage",
      "@id": "https://www.kolzchut.org.il/he/Page#faq",
      "url": "https://www.kolzchut.org.il/he/Page",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "Who can apply?",
          "acceptedAnswer": { "@type": "Answer", "text": "Any resident over 18." }
        }
      ]
    }
  ]
}
```

(URLs are shown decoded for readability; the real ones are percent-encoded.)

### BreadcrumbList

The main page (named by the `mainpage-description` message in the wiki's
language), the page's content area, and the page. The content area is what
[ArticleContentArea](https://github.com/kolzchut/mediawiki-extensions-ArticleContentArea)
recorded for the page, linked to the category of the same name. It is left out
when the page has no content area, when it is `unknown`, or when that category
page does not exist on this wiki.

### Article

- `headline`: the displayed title as plain text, without the " (type)" suffix
  that WRArticleType adds to it.
- `datePublished`: the page's first revision.
- `dateModified`: the last edit by a human, as
  [RealLastUpdate](https://github.com/kolzchut/mediawiki-extensions-RealLastUpdate)
  records it, so that bot edits do not make a page look updated. When
  RealLastUpdate knows of no human edit, `dateModified` is left out. Without
  RealLastUpdate, it is the page's latest revision.
- `publisher` and `author`: the Organization, named by
  `$wgStructuredDataPublisherName`.

### FAQPage

The questions that [FAQElement](https://github.com/kolzchut/mediawiki-extensions-FAQElement)'s
`{{#faq:}}` collects on the page, in page order, as plain text. Questions inside
a `{{#faq_group:}}` are included; group titles are not. FAQElement keeps the
parser functions and the collapsible widgets. It records each question in the
parser output's extension data under `faqelement_items`, as
`[ 'question' => text, 'answer' => text ]`, and this extension reads that key.

While a FAQElement that still emits its own FAQPage is loaded, with
`$wgFAQElementOutputSchema` true, this extension does not add one, so that a
page never carries two.

## Where it emits nothing

Only a plain view of the current revision of an existing page in one of
`$wgStructuredDataNamespaces` gets structured data. Special pages, other
namespaces, redirects, missing pages, old revisions, diffs and every action
other than `view` get none. Neither does the main page, which is the site, not
an article.

## Dependencies

ArticleContentArea, RealLastUpdate and FAQElement are all optional. The
extension reads what they leave behind (an OutputPage property, RealLastUpdate's
lookup, parser output extension data) and does without when one is not loaded.
That keeps it loadable on a wiki that has none of them, such as the teen site.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgStructuredDataNamespaces` | `null` | Namespaces whose pages get structured data. `null` means the content namespaces (`$wgContentNamespaces`). |
| `$wgStructuredDataPublisherName` | `null` | Name of the publishing organisation. `null` means `$wgSitename`. |
| `$wgStructuredDataPublisherLogo` | `null` | URL of its logo, absolute or relative to `$wgServer`. Google wants a raster image of at least 112x112 pixels. `null` leaves the logo out. |
| `$wgStructuredDataBreadcrumb` | `true` | Add the BreadcrumbList. |
| `$wgStructuredDataBreadcrumbContentArea` | `true` | Include the content area in the BreadcrumbList. |
| `$wgStructuredDataArticle` | `true` | Add the Article and its Organization. |
| `$wgStructuredDataFAQ` | `true` | Add the FAQPage. |

## Escaping

The JSON is encoded by `FormatJson`, which escapes `<`, `>` and `&`, so no
value can close the `<script>` element or open a comment inside it.

## Development

```sh
composer install && composer test     # parallel-lint, phpcs, minus-x
npm install && npm test               # eslint, stylelint, banana
```

The PHPUnit suite has unit tests for the builders and an integration test
(`@group Database`) that runs the hooks against real pages. On the Kol-Zchut
main dev stack, run it on real tables, because Semantic MediaWiki cannot use
cloned temporary ones:

```sh
docker exec -e PHPUNIT_WIKI=he -e MW_SKIP_EXTERNAL_DEPENDENCIES=1 \
  -e PHPUNIT_USE_NORMAL_TABLES=1 -w /var/www/html kz-main-mediawiki \
  php vendor/bin/phpunit extensions/WikiRights/StructuredData/tests/phpunit
```
