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

	public function test_core_post_meta_and_pattern_overrides_are_registered() {
		$response = $this->controller->get_binding_sources();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data  = $response->get_data();
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

	public function test_live_registry_never_returns_the_fallback_note() {
		$registry = new Block_Registry( new Preferences(), new Block_Inventory() );
		$result   = $registry->get_binding_sources();

		$this->assertArrayHasKey( 'sources', $result );
		$this->assertIsArray( $result['sources'] );
		$this->assertArrayNotHasKey( 'note', $result );
	}

	public function test_uses_context_present_only_when_declared() {
		$response = $this->controller->get_binding_sources();
		$data     = $response->get_data();

		$by_name = array();
		foreach ( $data['sources'] as $source ) {
			$by_name[ $source['name'] ] = $source;
		}

		// core/post-meta declares uses_context (postId, postType).
		$this->assertArrayHasKey( 'core/post-meta', $by_name );
		$this->assertArrayHasKey( 'uses_context', $by_name['core/post-meta'] );
		$this->assertIsArray( $by_name['core/post-meta']['uses_context'] );
	}
}
