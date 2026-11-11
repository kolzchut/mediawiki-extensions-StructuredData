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
	 * FAQElement already stores plain text; this keeps the same shape for any
	 * markup that slips through (tags removed, whitespace collapsed).
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function plain( $value ): string {
		if ( !is_string( $value ) ) {
			return '';
		}
		return trim( preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ?? '' );
	}
}
