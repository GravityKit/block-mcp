<?php
/**
 * Yoast FAQ enricher — flattens the dual-storage questions array into a
 * scan-friendly faq_summary list.
 *
 * The yoast/faq-block stores its question list in both attributes.questions
 * and innerHTML (dual-storage). The dual-storage write guard already
 * prevents agents from corrupting it by sending only one side. This
 * enricher gives the read side an agent-friendly surface: a flat array of
 * { question, answer_excerpt } so an agent can scan FAQ blocks without
 * parsing the Yoast-specific shape.
 *
 * @package GravityKit\BlockAPI\Block_Enrichers
 */

namespace GravityKit\BlockAPI\Block_Enrichers;

defined( 'ABSPATH' ) || exit;

/**
 * Enricher for yoast/faq-block blocks.
 */
class Yoast_Faq_Enricher {

	/**
	 * Block name this enricher targets.
	 */
	const BLOCK_NAME = 'yoast/faq-block';

	/**
	 * Cap on answer excerpt length to keep responses compact. Agents that
	 * need the full text can request the block by ref.
	 */
	const ANSWER_EXCERPT_LENGTH = 160;

	/**
	 * Register the filter hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gk_block_api_format_block', array( __CLASS__, 'enrich' ), 10, 2 );
	}

	/**
	 * Attach faq_summary to a yoast/faq-block.
	 *
	 * @param array  $data       Formatted block data.
	 * @param string $block_name Block name being formatted.
	 *
	 * @return array Possibly-augmented block data.
	 */
	public static function enrich( $data, $block_name ) {
		if ( self::BLOCK_NAME !== $block_name ) {
			return $data;
		}

		$questions = isset( $data['attributes']['questions'] ) ? $data['attributes']['questions'] : null;
		if ( empty( $questions ) || ! is_array( $questions ) ) {
			return $data;
		}

		$summary = array();
		foreach ( $questions as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$q_text = isset( $question['jsonQuestion'] ) ? (string) $question['jsonQuestion'] : '';
			$a_text = isset( $question['jsonAnswer'] ) ? (string) $question['jsonAnswer'] : '';

			$summary[] = array(
				'question'       => wp_strip_all_tags( $q_text ),
				'answer_excerpt' => self::excerpt( $a_text ),
			);
		}

		if ( ! empty( $summary ) ) {
			$data['faq_summary'] = $summary;
		}

		return $data;
	}

	/**
	 * Build a stripped, length-capped excerpt of an answer body.
	 *
	 * @param string $html Raw answer body (may contain HTML).
	 *
	 * @return string Plain-text excerpt, no longer than ANSWER_EXCERPT_LENGTH.
	 */
	private static function excerpt( $html ) {
		$plain = wp_strip_all_tags( $html );
		$plain = trim( preg_replace( '/\s+/', ' ', $plain ) );
		if ( strlen( $plain ) > self::ANSWER_EXCERPT_LENGTH ) {
			$plain = substr( $plain, 0, self::ANSWER_EXCERPT_LENGTH - 1 ) . "\u{2026}";
		}
		return $plain;
	}
}

Yoast_Faq_Enricher::init();
