<?php

namespace MediaWiki\Extension\StructuredData\Schema;

/**
 * schema.org FAQPage, from the questions Extension:FAQElement collects.
 *
 * Ported from FAQElement, which built the same FAQPage itself: every question
 * on the page, standalone or inside a {{#faq_group:}}, goes into one flat
 * mainEntity list, as plain text. A group title is not a question and is never
 * included. Google requires a name on each Question and a text on its Answer,
 * so an item missing either is dropped.
 *
 * @see https://schema.org/FAQPage
 * @see https://developers.google.com/search/docs/appearance/structured-data/faqpage
 */
class FaqPageBuilder {

	/**
	 * @param string $id Node identifier (an IRI)
	 * @param string $url The page's canonical URL
	 * @param array[] $items Each [ 'question' => string, 'answer' => string ]
	 * @return array|null Null when no usable question remains
	 */
	public static function build( string $id, string $url, array $items ): ?array {
		$questions = [];
		foreach ( $items as $item ) {
			if ( !is_array( $item ) ) {
				continue;
			}
			$question = self::plain( $item['question'] ?? '' );
			$answer = self::plain( $item['answer'] ?? '' );
			if ( $question === '' || $answer === '' ) {
				continue;
			}
			$questions[] = [
				'@type' => 'Question',
				'name' => $question,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text' => $answer,
				],
			];
		}
		if ( !$questions ) {
			return null;
		}
		return [
			'@type' => 'FAQPage',
			'@id' => $id,
			'url' => $url,
			'mainEntity' => $questions,
		];
	}

	/**
	 * FAQElement stores the parsed HTML with its tags stripped, so the text can
	 * still hold entities (&quot;, &amp;, &#160;) that would otherwise reach
	 * the JSON literally. Tags are removed, entities decoded, and whitespace,
	 * no-break spaces included, collapsed.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function plain( $value ): string {
		if ( !is_string( $value ) ) {
			return '';
		}
		$text = html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}\x{202F}]+/u', ' ', $text ) ?? '' );
	}
}
