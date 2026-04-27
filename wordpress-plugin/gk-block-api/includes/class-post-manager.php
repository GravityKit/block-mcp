<?php
/**
 * Post-level CRUD (metadata + status). Block-content edits stay on
 * the existing per-block endpoints.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Manager
 *
 * Owns create_post and update_post. Reuses Block_CRUD's preference
 * pipeline when callers pass structured blocks at create time.
 */
class Post_Manager {

	/**
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * @var Block_CRUD
	 */
	private $block_crud;

	public function __construct( Preferences $preferences, Block_CRUD $block_crud ) {
		$this->preferences = $preferences;
		$this->block_crud  = $block_crud;
	}

	/**
	 * Create a new post or page.
	 *
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.1.
	 * @return array|\WP_Error
	 */
	public function create_post( array $args ) {
		return new \WP_Error( 'not_implemented', 'create_post not implemented yet' );
	}

	/**
	 * Update post metadata, status, or terms. Block content edits stay on
	 * the per-block endpoints.
	 *
	 * @param int   $post_id
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.2.
	 * @return array|\WP_Error
	 */
	public function update_post( $post_id, array $args ) {
		return new \WP_Error( 'not_implemented', 'update_post not implemented yet' );
	}

	/**
	 * Built-in allow-list when no override option is set.
	 *
	 * @return string[]
	 */
	private function default_allowed_post_types() {
		$built_in     = array( 'post', 'page' );
		$rest_enabled = function_exists( 'get_post_types' )
			? array_values( get_post_types( array( 'show_in_rest' => true ), 'names' ) )
			: array();
		return array_values( array_unique( array_merge( $built_in, $rest_enabled ) ) );
	}
}
