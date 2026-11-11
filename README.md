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
        { "@type": "ListItem", "position": 2, "name": "חינוך", "item": "https://www.kolzchut.org.il/he/חינוך" },
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
      "image": "https://www.kolzchut.org.il/w/he/images/a/ab/Example.png",
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
language), the portal of the page's content area, and the page.

[ArticleContentArea](https://github.com/kolzchut/mediawiki-extensions-ArticleContentArea)
stores a page's content area by its name in one language on every wiki: on
Kol-Zchut, Hebrew, so an Arabic article's area reads `תעסוקה וזכויות עובדים`.
Set that language in `$wgStructuredDataContentAreaLanguage`. The middle crumb is
then:

- on the wiki in that language, the article of the area's name, or the article
  it redirects to (the area's portal, e.g. `חינוך`);
- on any other wiki, the article whose interlanguage link points at that
  portal, so the crumb carries the portal's name in the page's language
  (`التشغيل وحقوق العاملين` on ar, `Образование` on ru).

  Interlanguage links point at the portal, so when the area's name is a
  redirect on the wiki in that language (`המשפט הפלילי` to
  `אנשים בהליכים פליליים`), the local portal links to the target. Set
  `$wgStructuredDataContentAreaWiki` to that wiki's ID (`kz_he` on Kol-Zchut)
  and the name is resolved there first, at parse time; a link to either the
  name or the target then counts. Without it, only the name is matched and such
  areas get no middle crumb. Where several local articles match, a link to the
  target wins over a link to the name, and then the article with the lowest
  page ID.

Where there is no such article the middle crumb is left out, and the trail is
main page › page. It never names the area in another language, and never links
to the category of that name, which is `noindex,nofollow`.

### Article

- `headline`: the page's `<h1>` as plain text (a `{{DISPLAYTITLE}}` included),
  without the " (type)" suffix that WRArticleType adds to it. The last crumb of
  the BreadcrumbList carries the same name. It is the final `<h1>`, whatever
  the load order: the JSON-LD is written with the `<head>`, after every
  `BeforePageDisplay` handler, so where an extension changes the `<h1>` there
  (as KolzchutYoung drops the namespace prefix from teen's articles and
  portals), the headline follows.
- `datePublished`: the page's first revision.
- `dateModified`: the last edit by a human, as
  [RealLastUpdate](https://github.com/kolzchut/mediawiki-extensions-RealLastUpdate)
  records it, so that bot edits do not make a page look updated. When
  RealLastUpdate knows of no human edit, `dateModified` is left out. Without
  RealLastUpdate, it is the revision being shown, which is the latest.
- `image`: the page image that [PageImages](https://www.mediawiki.org/wiki/Extension:PageImages)
  chose (a freely licensed one first), else `$wgStructuredDataDefaultImage`,
  else none.
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
page never carries two. The text is plain: tags are stripped and HTML entities
decoded.

### Deploy order

**Load StructuredData on a site no later than the FAQElement 2.0.0 pointer bump
(PR kolzchut/mediawiki-extensions-FAQElement#2).** FAQElement 2.0.0 no longer
emits a FAQPage. On a site that has it but not StructuredData, no page carries
an FAQPage at all. Load StructuredData first, or in the same release. Loading
it earlier is safe: until the bump it leaves the FAQPage to the older
FAQElement, so a page never has two.

## Cost per page view: no queries

Everything that takes a database query is found out when the page is parsed,
in `ContentAlterParserOutput`, and stored in the ParserOutput's extension data,
so it is parser-cached with the page: the first revision's timestamp, the
content area's portal, and the page image's URL. Every edit, purge or template
change that re-parses the page collects them again.

The last human edit is not queried either. RealLastUpdate already looks it up
on every view, in its own `OutputPageParserOutput` handler, and leaves it in
the ParserOutput's `RealLastUpdateTimestamp` page property. This extension
reads that property when the `<head>` is written
(`OutputPageAfterGetHeadLinksArray`), after every `OutputPageParserOutput`
handler has run, so the order in which the two extensions are loaded does not
matter.

A page that was parsed before this extension was loaded has none of these
facts and gets no JSON-LD until it is next parsed; no query fills the gap. The
parser cache expires within `$wgParserCacheExpireTime` (a day on Kol-Zchut).

## Where it emits nothing

Only a plain view of the current revision of an existing page in one of
`$wgStructuredDataNamespaces` gets structured data. Special pages, other
namespaces, redirects, missing pages, old revisions, diffs and every action
other than `view` get none. Neither does the main page, which is the site, not
an article.

## Dependencies

ArticleContentArea, RealLastUpdate, FAQElement and PageImages are all optional.
The extension reads what they leave in the ParserOutput (page properties and
extension data) and does without when one is not loaded.
That keeps it loadable on a wiki that has none of them, such as the teen site.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgStructuredDataNamespaces` | `null` | Namespaces whose pages get structured data. `null` means the content namespaces (`$wgContentNamespaces`). |
| `$wgStructuredDataPublisherName` | `null` | Name of the publishing organisation. `null` means `$wgSitename`. |
| `$wgStructuredDataPublisherLogo` | `null` | URL of its logo, absolute or relative to `$wgServer`. Google wants a raster image of at least 112x112 pixels. `null` leaves the logo out. |
| `$wgStructuredDataBreadcrumb` | `true` | Add the BreadcrumbList. |
| `$wgStructuredDataBreadcrumbContentArea` | `true` | Include the content area's portal in the BreadcrumbList. |
| `$wgStructuredDataContentAreaLanguage` | `null` | Language code of the content-area names ArticleContentArea stores. `null` means this wiki's content language. |
| `$wgStructuredDataContentAreaWiki` | `null` | Wiki ID (database name) of the wiki in that language, e.g. `kz_he`, in which a content area's name is resolved to its redirect's target, at parse time. `null` matches the name only. |
| `$wgStructuredDataDefaultImage` | `null` | Image URL for an Article whose page has no page image, absolute or relative to `$wgServer`. Google wants at least 50,000 pixels (width x height). |
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
