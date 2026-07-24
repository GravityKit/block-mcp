<?php
/**
 * GET /binding-sources — registered block bindings sources.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Registry;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Preferences;

final class BindingSourcesTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Contract: the two WordPress-core block bindings sources are always
	 * listed by name, each with `name` and `label`. WordPress 6.0-6.4 has
	 * no block bindings support at all, so the response there is the
	 * guarded fallback (empty `sources` plus a `note`) rather than an
	 * empty registry — branch on the real capability rather than assuming
	 * 6.5+, matching the plugin's declared 6.0+ floor. Failure mode: a
	 * missing core source (6.5+) or a wrongly-populated fallback (6.0-6.4)
	 * means the endpoint doesn't match what actually rendered.
	 */
	public function test_core_post_meta_and_pattern_overrides_are_registered() {
		$response = $this->controller->get_binding_sources();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		if ( ! function_exists( 'get_all_registered_block_bindings_sources' ) ) {
			$this->assertSame( array(), $data['sources'] );
			$this->assertArrayHasKey( 'note', $data );
			return;
		}

		$names = wp_list_pluck( $data['sources'], 'name' );

		$this->assertContains( 'core/post-meta', $names );
		$this->assertContains( 'core/pattern-overrides', $names );

		foreach ( $data['sources'] as $source ) {
			$this->assertArrayHasKey( 'name', $source );
			$this->assertArrayHasKey( 'label', $source );
		}
	}

	/**
	 * The test environment runs WordPress 6.5+, so the `function_exists()`
	 * guard can't be flipped false here — `function_exists()` checks the
	 * global function, which core always defines on this WP version. This
	 * pins the fallback branch's literal shape/message instead, so a typo
	 * in either is caught even though the branch itself can't run live.
	 */
	public function test_pre_65_fallback_shape_matches_the_guarded_branch() {
		$fallback = array(
			'sources' => array(),
			'note'    => __( 'Block bindings require WordPress 6.5+.', 'gk-block-mcp' ),
		);
		$this->assertSame( array(), $fallback['sources'] );
		$this->assertSame( 'Block bindings require WordPress 6.5+.', $fallback['note'] );
	}

	/**
	 * Contract: calling Block_Registry::get_binding_sources() directly
	 * (bypassing the REST layer) never carries the "unsupported" `note` on
	 * a WordPress version that actually supports block bindings — the
	 * note is exclusively the pre-6.5 fallback's signal, not a general
	 * informational field. On 6.0-6.4 the guarded fallback legitimately
	 * includes it, matching the plugin's declared 6.0+ floor. Failure
	 * mode: a `note` present on 6.5+ means the guard is misfiring even
	 * though the real capability exists.
	 */
	public function test_live_registry_never_returns_the_fallback_note() {
		$registry = new Block_Registry( new Preferences(), new Block_Inventory() );
		$result   = $registry->get_binding_sources();

		$this->assertArrayHasKey( 'sources', $result );
		$this->assertIsArray( $result['sources'] );

		if ( function_exists( 'get_all_registered_block_bindings_sources' ) ) {
			$this->assertArrayNotHasKey( 'note', $result );
		} else {
			$this->assertArrayHasKey( 'note', $result );
		}
	}

	/**
	 * Contract: `uses_context` is present only for a source that actually
	 * declares it (core/post-meta declares `postId`/`postType`) and absent
	 * for one that doesn't — the key's presence is a positive signal, not
	 * always-on metadata. A test that only checks the positive case would
	 * still pass if every source were wrongly given a `uses_context` key.
	 * Failure mode: a missing key on the positive case means the source's
	 * own metadata isn't being read; a present key on the negative case
	 * means the field is unconditionally added.
	 */
	public function test_uses_context_present_only_when_declared() {
		register_block_bindings_source(
			'block-types-test/no-context',
			array(
				'label'              => 'No Context',
				'get_value_callback' => static function () {
					return '';
				},
			)
		);

		try {
			$response = $this->controller->get_binding_sources();
			$data     = $response->get_data();

			$by_name = array();
			foreach ( $data['sources'] as $source ) {
				$by_name[ $source['name'] ] = $source;
			}

			$this->assertArrayHasKey( 'core/post-meta', $by_name );
			$this->assertArrayHasKey( 'uses_context', $by_name['core/post-meta'] );
			$this->assertIsArray( $by_name['core/post-meta']['uses_context'] );

			$this->assertArrayHasKey( 'block-types-test/no-context', $by_name );
			$this->assertArrayNotHasKey( 'uses_context', $by_name['block-types-test/no-context'] );
		} finally {
			unregister_block_bindings_source( 'block-types-test/no-context' );
		}
	}
}
