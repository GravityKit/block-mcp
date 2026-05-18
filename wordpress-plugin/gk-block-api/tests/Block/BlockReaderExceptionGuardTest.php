<?php
/**
 * Tests for Block_Reader::get_blocks() exception handling.
 *
 * Verifies that uncaught exceptions thrown by downstream collaborators
 * (per-block format filters, ref-assignment, schema-aware extraction)
 * are caught at the Reader and returned as a WP_Error('parse_error', 500)
 * instead of bubbling up through REST_Controller's catch-all.
 *
 * Borrowed from vip-block-data-api's ContentParser::parse() pattern, which
 * wraps the entire parse pipeline in try/catch and includes the exception's
 * __toString() in data.details when WP_DEBUG is on.
 *
 * @package GravityKit\BlockAPI\Tests
 */

class BlockReaderExceptionGuardTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_xguard1' ) ),
					'innerHTML'    => '<p>Hello</p>',
					'innerContent' => array( '<p>Hello</p>' ),
					'innerBlocks'  => array(),
				),
			)
		);
	}

	public function tear_down(): void {
		// Clean up any filters registered in test bodies.
		remove_all_filters( 'gk_block_api_format_block' );
		parent::tear_down();
	}

	// ── exception in per-block filter is caught and surfaced as WP_Error ─

	public function test_get_blocks_returns_wp_error_when_format_filter_throws() {
		add_filter(
			'gk_block_api_format_block',
			static function () {
				throw new \RuntimeException( 'simulated downstream failure' );
			}
		);

		$result = $this->crud->get_blocks( $this->post_id );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'get_blocks() must catch downstream exceptions and return WP_Error.'
		);
		$this->assertSame( 'parse_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 500, $data['status'] );
	}

	// ── error message includes the original exception message (always) ────

	public function test_get_blocks_wp_error_message_includes_exception_message() {
		add_filter(
			'gk_block_api_format_block',
			static function () {
				throw new \LogicException( 'unique-marker-string-zXq42' );
			}
		);

		$result = $this->crud->get_blocks( $this->post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString(
			'unique-marker-string-zXq42',
			$result->get_error_message(),
			'WP_Error message must surface the original exception message for debugging.'
		);
	}

	// ── details field only populated when WP_DEBUG is on ──────────────────

	public function test_get_blocks_wp_error_details_only_when_wp_debug_enabled() {
		add_filter(
			'gk_block_api_format_block',
			static function () {
				throw new \RuntimeException( 'debug-mode-probe' );
			}
		);

		$result = $this->crud->get_blocks( $this->post_id );
		$this->assertInstanceOf( \WP_Error::class, $result );

		$data        = $result->get_error_data();
		$wp_debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( $wp_debug_on ) {
			$this->assertArrayHasKey(
				'details',
				$data,
				'When WP_DEBUG is on, data.details must include the exception trace.'
			);
			$this->assertStringContainsString( 'debug-mode-probe', $data['details'] );
		} else {
			$this->assertArrayNotHasKey(
				'details',
				$data,
				'When WP_DEBUG is off, data.details must NOT leak the trace.'
			);
		}
	}

	// ── happy path remains unaffected ─────────────────────────────────────

	public function test_get_blocks_happy_path_still_returns_array() {
		$result = $this->crud->get_blocks( $this->post_id );

		$this->assertIsArray( $result, 'Happy path must still return an array, not WP_Error.' );
		$this->assertCount( 1, $result );
		$this->assertSame( 'core/paragraph', $result[0]['name'] );
	}

	// ── exception during render mode is also caught ───────────────────────

	public function test_get_blocks_render_mode_exception_is_caught() {
		add_filter(
			'gk_block_api_format_block',
			static function () {
				throw new \RuntimeException( 'render-time failure' );
			}
		);

		$result = $this->crud->get_blocks( $this->post_id, true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'parse_error', $result->get_error_code() );
	}

	// ── post context is restored even when render-mode body throws ────────

	public function test_get_blocks_restores_post_context_on_exception() {
		$other_post_id = self::factory()->post->create( array( 'post_title' => 'context-marker' ) );

		// Set a known global post before the call.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional setup.
		$GLOBALS['post'] = get_post( $other_post_id );

		add_filter(
			'gk_block_api_format_block',
			static function () {
				throw new \RuntimeException( 'restore probe' );
			}
		);

		$this->crud->get_blocks( $this->post_id, true );

		$this->assertNotNull( $GLOBALS['post'], 'Global $post must not be cleared by a thrown read.' );
		$this->assertSame(
			$other_post_id,
			$GLOBALS['post']->ID,
			'Global $post must be restored to the pre-call value even when the read throws.'
		);
	}
}
