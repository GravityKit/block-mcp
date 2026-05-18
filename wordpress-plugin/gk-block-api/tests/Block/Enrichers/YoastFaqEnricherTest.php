<?php
/**
 * Tests for Yoast_Faq_Enricher.
 *
 * Surfaces a `faq_summary` field on yoast/faq-block instances so an agent
 * can scan question/answer pairs without parsing the dual-storage
 * attributes.questions array shape.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_Enrichers\Yoast_Faq_Enricher;

class YoastFaqEnricherTest extends BlockApiTestCase {

	// ── happy path: surfaces a flat list of question + answer excerpt ─────

	public function test_enrich_surfaces_faq_summary() {
		$data = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array(
				'questions' => array(
					array(
						'id'           => 'q1',
						'jsonQuestion' => 'What is the answer to life?',
						'jsonAnswer'   => '42, obviously.',
					),
					array(
						'id'           => 'q2',
						'jsonQuestion' => 'Why?',
						'jsonAnswer'   => 'Because Douglas Adams said so. Long-winded justification follows that probably exceeds the excerpt length cap we set in the enricher implementation, so it must be truncated cleanly.',
					),
				),
			),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );

		$this->assertArrayHasKey( 'faq_summary', $result );
		$this->assertCount( 2, $result['faq_summary'] );
		$this->assertSame( 'What is the answer to life?', $result['faq_summary'][0]['question'] );
		$this->assertStringStartsWith( '42, obviously.', $result['faq_summary'][0]['answer_excerpt'] );
	}

	// ── multibyte answers are truncated at codepoint boundaries, not bytes ─

	public function test_enrich_excerpt_is_valid_utf8_for_multibyte_answer() {
		// Mix one ASCII char with many 3-byte Japanese chars so a byte-based
		// substr() lands MID-codepoint and produces broken UTF-8. A
		// codepoint-aware substr (mb_substr) cuts cleanly between chars.
		$answer = 'a' . str_repeat( 'あ', 200 );
		$data   = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array(
				'questions' => array(
					array(
						'jsonQuestion' => 'Q?',
						'jsonAnswer'   => $answer,
					),
				),
			),
		);

		$result  = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );
		$excerpt = $result['faq_summary'][0]['answer_excerpt'];

		$this->assertSame(
			1,
			preg_match( '//u', $excerpt ),
			'Excerpt must be valid UTF-8 — byte-based truncation cuts mid-codepoint and corrupts the string.'
		);
	}

	// ── long answers get an excerpt, not the full text ────────────────────

	public function test_enrich_truncates_long_answers() {
		$long_answer = str_repeat( 'a ', 200 );
		$data        = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array(
				'questions' => array(
					array(
						'jsonQuestion' => 'Q?',
						'jsonAnswer'   => $long_answer,
					),
				),
			),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );

		$this->assertLessThanOrEqual(
			200,
			strlen( $result['faq_summary'][0]['answer_excerpt'] ),
			'Answer excerpt must be capped to keep the response compact.'
		);
	}

	// ── empty questions array → no faq_summary attached ───────────────────

	public function test_enrich_skips_when_questions_empty() {
		$data = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array( 'questions' => array() ),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );

		$this->assertArrayNotHasKey( 'faq_summary', $result );
	}

	// ── missing questions attribute → no faq_summary attached ─────────────

	public function test_enrich_skips_when_questions_missing() {
		$data = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array(),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );

		$this->assertArrayNotHasKey( 'faq_summary', $result );
	}

	// ── non-FAQ blocks: untouched ─────────────────────────────────────────

	public function test_enrich_skips_non_faq_blocks() {
		$data = array(
			'name'       => 'core/paragraph',
			'attributes' => array( 'questions' => array( array( 'jsonQuestion' => 'Q?' ) ) ),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'core/paragraph' );

		$this->assertArrayNotHasKey( 'faq_summary', $result );
	}

	// ── HTML in answer is stripped from the excerpt ───────────────────────

	public function test_enrich_strips_html_from_excerpt() {
		$data = array(
			'name'       => 'yoast/faq-block',
			'attributes' => array(
				'questions' => array(
					array(
						'jsonQuestion' => 'Q?',
						'jsonAnswer'   => '<p>Hello <strong>world</strong></p>',
					),
				),
			),
		);

		$result = Yoast_Faq_Enricher::enrich( $data, 'yoast/faq-block' );

		$this->assertStringNotContainsString( '<p>', $result['faq_summary'][0]['answer_excerpt'] );
		$this->assertStringNotContainsString( '<strong>', $result['faq_summary'][0]['answer_excerpt'] );
		$this->assertStringContainsString( 'Hello world', $result['faq_summary'][0]['answer_excerpt'] );
	}
}
