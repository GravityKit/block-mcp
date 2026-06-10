<?php
/**
 * End-to-end REST test for the Yoast FAQ enricher.
 *
 * Dispatches a real `GET /gk-block-api/v1/posts/{id}/blocks` request
 * through WP_REST_Server with a post that contains a yoast/faq-block
 * and asserts that the response includes the faq_summary enrichment.
 *
 * This is the highest-fidelity test layer below a live MCP server call:
 * full WordPress bootstrap, real REST namespace registration, real
 * filter graph, real authentication path. Catches breakage that unit
 * tests against the enricher class in isolation would miss — e.g. the
 * enricher file failing to load, the filter not registering, the REST
 * controller stripping the faq_summary field via field filtering, or
 * permission gates rejecting the request before the enricher fires.
 *
 * @package GravityKit\BlockMCP\Tests
 */

class YoastFaqEnricherRestTest extends WP_UnitTestCase {

	const REST_ROUTE = '/gk-block-api/v1/posts/%d/blocks';

	/** @var int */
	private $editor_user_id;

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		// Force the REST namespace + plugin filter graph to register.
		do_action( 'rest_api_init' );

		$this->editor_user_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $this->editor_user_id );

		$faq_block_markup = $this->faq_block_markup(
			array(
				array(
					'id'           => 'faq-q1',
					'jsonQuestion' => 'Can the MCP edit one block at a time?',
					'jsonAnswer'   => 'Yes — pass a flat_index, a path, or a ref.',
				),
				array(
					'id'           => 'faq-q2',
					'jsonQuestion' => 'Does the FAQ block lose data on partial writes?',
					'jsonAnswer'   => 'No. The dual-storage guard rejects writes that touch only one of attributes.questions or innerHTML.',
				),
			)
		);

		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $faq_block_markup,
			)
		);
	}

	public function tear_down(): void {
		if ( $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( $this->editor_user_id ) {
			self::delete_user( $this->editor_user_id );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ── REST response embeds faq_summary on the yoast/faq-block ───────────

	public function test_rest_get_blocks_surfaces_faq_summary() {
		$request  = new \WP_REST_Request(
			'GET',
			sprintf( self::REST_ROUTE, $this->post_id )
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame(
			200,
			$response->get_status(),
			'REST endpoint must return 200 for a valid editor user.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'blocks', $data );
		$this->assertNotEmpty( $data['blocks'], 'Post must contain at least the FAQ block.' );

		$faq = $this->find_block( $data['blocks'], 'yoast/faq-block' );
		$this->assertNotNull( $faq, 'Response must include the yoast/faq-block in the parsed tree.' );

		$this->assertArrayHasKey(
			'faq_summary',
			$faq,
			'Yoast FAQ enricher must inject faq_summary into the REST response.'
		);
		$this->assertCount( 2, $faq['faq_summary'] );
		$this->assertSame(
			'Can the MCP edit one block at a time?',
			$faq['faq_summary'][0]['question']
		);
		$this->assertStringStartsWith(
			'Yes',
			$faq['faq_summary'][0]['answer_excerpt']
		);
	}

	// ── faq_summary survives a field-filtered request (`?fields=…`) ───────

	public function test_rest_get_blocks_with_fields_filter_keeps_faq_summary() {
		$request = new \WP_REST_Request(
			'GET',
			sprintf( self::REST_ROUTE, $this->post_id )
		);
		$request->set_param( 'fields', 'name,faq_summary' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$faq  = $this->find_block( $data['blocks'], 'yoast/faq-block' );
		$this->assertNotNull( $faq );
		$this->assertArrayHasKey(
			'faq_summary',
			$faq,
			'When fields filter explicitly includes faq_summary, it must be preserved.'
		);
	}

	// ── unauthenticated access is rejected before the enricher fires ──────

	public function test_rest_get_blocks_rejects_unauthenticated_caller() {
		wp_set_current_user( 0 );

		$request  = new \WP_REST_Request(
			'GET',
			sprintf( self::REST_ROUTE, $this->post_id )
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertGreaterThanOrEqual(
			400,
			$response->get_status(),
			'Anonymous callers must NOT be able to read post blocks through the API.'
		);
	}

	/**
	 * Build a minimal block-comment-delimited yoast/faq-block markup string.
	 *
	 * We don't rely on Yoast SEO being active — the block name carries the
	 * dual-storage shape we care about, and the enricher only reads
	 * attributes.questions. innerHTML is mirrored so parse_blocks emits a
	 * complete tree even without the Yoast block registered.
	 *
	 * @param array<int, array{id: string, jsonQuestion: string, jsonAnswer: string}> $questions
	 *
	 * @return string
	 */
	private function faq_block_markup( array $questions ): string {
		$attrs = wp_json_encode( array( 'questions' => $questions ) );

		$inner_parts = array();
		foreach ( $questions as $q ) {
			$inner_parts[] = sprintf(
				'<div class="schema-faq-question">%s</div><div class="schema-faq-answer">%s</div>',
				esc_html( $q['jsonQuestion'] ),
				esc_html( $q['jsonAnswer'] )
			);
		}
		$inner_html = '<div class="schema-faq wp-block-yoast-faq-block">' . implode( '', $inner_parts ) . '</div>';

		return '<!-- wp:yoast/faq-block ' . $attrs . ' -->' . $inner_html . '<!-- /wp:yoast/faq-block -->';
	}

	/**
	 * Walk the REST response blocks tree for the first block matching a name.
	 *
	 * @param array  $blocks Block tree.
	 * @param string $name   Block name to find.
	 *
	 * @return array|null
	 */
	private function find_block( array $blocks, string $name ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['name'] ) && $block['name'] === $name ) {
				return $block;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_block( $block['innerBlocks'], $name );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
