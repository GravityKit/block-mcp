<?php
/**
 * Executes Block MCP tools by delegating to REST_Controller (and Yoast_Bridge).
 *
 * Mirrors the npm MCP server's tool handlers: argument normalization, URL
 * resolution, and client-side pagination live here so Abilities return the
 * same shapes agents expect from block-mcp.
 *
 * @package GravityKit\BlockMCP
 * @since   2.1.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool execution layer for the Abilities API.
 *
 * @since 2.1.0
 */
class Tool_Executor {

	/**
	 * REST controller wired with the block service graph.
	 *
	 * @var REST_Controller
	 */
	private $controller;

	/**
	 * Optional Yoast SEO bridge (routes register only when Yoast is active).
	 *
	 * @var Yoast_Bridge|null
	 */
	private $yoast_bridge;

	/**
	 * Constructor.
	 *
	 * @since 2.1.0
	 *
	 * @param REST_Controller   $controller   REST controller instance.
	 * @param Yoast_Bridge|null $yoast_bridge Yoast bridge, when available.
	 */
	public function __construct( REST_Controller $controller, ?Yoast_Bridge $yoast_bridge = null ) {
		$this->controller   = $controller;
		$this->yoast_bridge = $yoast_bridge;
	}

	/**
	 * Run a named MCP tool with validated input.
	 *
	 * @since 2.1.0
	 *
	 * @param string               $tool_name MCP tool name (snake_case).
	 * @param array<string, mixed> $input     Pre-validated tool arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( string $tool_name, array $input ) {
		switch ( $tool_name ) {
			case 'list_block_types':
				return $this->execute_list_block_types( $input );
			case 'list_patterns':
				return $this->execute_list_patterns( $input );
			case 'get_pattern':
				return $this->execute_get_pattern( $input );
			case 'get_site_usage':
				return $this->execute_get_site_usage( $input );
			case 'scan_storage_modes':
				return $this->execute_scan_storage_modes( $input );
			case 'resolve_url':
				return $this->execute_resolve_url( $input );
			case 'list_posts':
				return $this->execute_list_posts( $input );
			case 'get_post_info':
				return $this->execute_get_post_info( $input );
			case 'get_page_blocks':
				return $this->execute_get_page_blocks( $input );
			case 'site_editor_context':
				return $this->execute_site_editor_context( $input );
			case 'get_block':
				return $this->execute_get_block( $input );
			case 'update_block':
				return $this->execute_update_block( $input );
			case 'update_blocks':
				return $this->execute_update_blocks( $input );
			case 'insert_blocks':
				return $this->execute_insert_blocks( $input );
			case 'delete_block':
				return $this->execute_delete_block( $input );
			case 'replace_block_range':
				return $this->execute_replace_block_range( $input );
			case 'rewrite_post_blocks':
				return $this->execute_rewrite_post_blocks( $input );
			case 'revert_to_revision':
				return $this->execute_revert_to_revision( $input );
			case 'edit_block_tree':
				return $this->execute_edit_block_tree( $input );
			case 'insert_pattern':
				return $this->execute_insert_pattern( $input );
			case 'create_post':
				return $this->execute_create_post( $input );
			case 'update_post':
				return $this->execute_update_post( $input );
			case 'list_terms':
				return $this->execute_list_terms( $input );
			case 'upload_media':
				return $this->execute_upload_media( $input );
			case 'yoast_get_seo':
				return $this->execute_yoast_get_seo( $input );
			case 'yoast_update_seo':
				return $this->execute_yoast_update_seo( $input );
			case 'yoast_bulk_update_seo':
				return $this->execute_yoast_bulk_update_seo( $input );
			default:
				return new \WP_Error(
					'unknown_tool',
					sprintf(
						/* translators: %s: tool name */
						__( 'Unknown Block MCP tool: %s', 'gk-block-mcp' ),
						$tool_name
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * List and paginate registered block types via the block-types REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_list_block_types( array $input ) {
		$params = array(
			'namespace'      => isset( $input['namespace'] ) ? (string) $input['namespace'] : null,
			'category'       => isset( $input['category'] ) ? (string) $input['category'] : null,
			'preferred_only' => ! empty( $input['preferred_only'] ),
			'tier'           => isset( $input['tier'] ) ? (string) $input['tier'] : null,
			'storage_mode'   => isset( $input['storage_mode'] ) ? (string) $input['storage_mode'] : null,
			'search'         => isset( $input['search'] ) ? (string) $input['search'] : null,
			'usage_only'     => ! empty( $input['usage_only'] ),
		);

		$data = $this->call_controller(
			array( $this->controller, 'get_block_types' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/block-types' ),
			$params
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$block_types = isset( $data['block_types'] ) && is_array( $data['block_types'] ) ? $data['block_types'] : array();
		$limit       = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
		$offset      = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$total       = count( $block_types );
		$page        = array_slice( $block_types, $offset, $limit );

		return array(
			'block_types' => $page,
			'count'       => count( $page ),
			'total'       => $total,
			'offset'      => $offset,
			'next_offset' => ( $offset + count( $page ) ) < $total ? $offset + count( $page ) : null,
		);
	}

	/**
	 * List and paginate registered patterns via the patterns REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_list_patterns( array $input ) {
		$limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 20;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$params = array(
			'q'         => isset( $input['search'] ) ? (string) $input['search'] : null,
			'synced'    => array_key_exists( 'synced', $input ) ? (bool) $input['synced'] : null,
			'min_score' => isset( $input['min_score'] ) ? (int) $input['min_score'] : null,
			// Fetch the full filtered set (get_patterns builds all matches before
			// its own limit slice regardless), so `total` below is the true count
			// and next_offset is accurate; paginate the window here.
			'limit'     => PHP_INT_MAX,
			'refresh'   => ! empty( $input['refresh'] ),
		);

		$data = $this->call_controller(
			array( $this->controller, 'get_patterns' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/patterns' ),
			$params
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$patterns = isset( $data['patterns'] ) && is_array( $data['patterns'] ) ? $data['patterns'] : array();
		$total    = count( $patterns );
		$page     = array_slice( $patterns, $offset, $limit );

		return array(
			'patterns'    => $page,
			'count'       => count( $page ),
			'total'       => $total,
			'offset'      => $offset,
			'next_offset' => ( $offset + count( $page ) ) < $total ? $offset + count( $page ) : null,
		);
	}

	/**
	 * Fetch a single pattern by id via the patterns REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_get_pattern( array $input ) {
		if ( ! isset( $input['pattern_id'] ) ) {
			return new \WP_Error( 'missing_pattern_id', __( 'pattern_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/patterns/' . rawurlencode( (string) $input['pattern_id'] ) );
		$request->set_param( 'id', (string) $input['pattern_id'] );

		return $this->call_controller( array( $this->controller, 'get_pattern' ), $request );
	}

	/**
	 * Fetch site-wide block and pattern usage stats via the site-usage REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_get_site_usage( array $input ) {
		return $this->call_controller(
			array( $this->controller, 'get_site_usage' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/site-usage' ),
			array( 'refresh' => ! empty( $input['refresh'] ) )
		);
	}

	/**
	 * Trigger a site-wide block storage-mode scan via the storage-modes REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_scan_storage_modes( array $input ) {
		unset( $input );
		return $this->call_controller(
			array( $this->controller, 'scan_storage_modes' ),
			new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/storage-modes/scan' )
		);
	}

	/**
	 * Resolve a front-end URL to its post id via the resolve REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_resolve_url( array $input ) {
		$url = isset( $input['url'] ) ? (string) $input['url'] : '';
		if ( '' === $url ) {
			return new \WP_Error( 'missing_url', __( 'url is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		return $this->call_controller(
			array( $this->controller, 'resolve_url' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/resolve' ),
			array( 'url' => $url )
		);
	}

	/**
	 * Search and list posts via the find-posts REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_list_posts( array $input ) {
		return $this->call_controller(
			array( $this->controller, 'find_posts' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/find-posts' ),
			$input
		);
	}

	/**
	 * Fetch post metadata via the post-info REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_get_post_info( array $input ) {
		return $this->call_controller(
			array( $this->controller, 'post_info' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/post-info' ),
			$input
		);
	}

	/**
	 * Resolve a post by id or URL and fetch its blocks via the post-blocks REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_get_page_blocks( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$url     = isset( $input['url'] ) ? (string) $input['url'] : '';

		if ( $post_id <= 0 && '' === $url ) {
			return new \WP_Error( 'missing_target', __( 'Either post_id or url is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		if ( $post_id <= 0 ) {
			$resolved = $this->execute_resolve_url( array( 'url' => $url ) );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$post_id = isset( $resolved['post_id'] ) ? absint( $resolved['post_id'] ) : 0;
		}

		$params = array(
			'id'                   => $post_id,
			'fields'               => isset( $input['fields'] ) ? (string) $input['fields'] : null,
			'search'               => isset( $input['search'] ) ? (string) $input['search'] : null,
			'block_name'           => isset( $input['block_name'] ) ? (string) $input['block_name'] : null,
			'render'               => ! empty( $input['render'] ),
			'outline'              => ! empty( $input['outline'] ),
			'summary_only'         => ! empty( $input['summary_only'] ),
			'include_legacy_paths' => ! empty( $input['include_legacy_paths'] ),
			'persist_refs'         => array_key_exists( 'persist_refs', $input ) ? (bool) $input['persist_refs'] : true,
			'limit'                => isset( $input['limit'] ) ? (int) $input['limit'] : null,
			'cursor'               => isset( $input['cursor'] ) ? (string) $input['cursor'] : null,
		);

		$request = new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks' );
		return $this->call_controller( array( $this->controller, 'get_post_blocks' ), $request, $params );
	}

	/**
	 * Build the site design context (theme + flattened theme.json presets) so an
	 * agent composes theme-aligned, valid block markup. Has no REST route
	 * equivalent — this ability reads global settings directly.
	 *
	 * @param array<string, mixed> $input Tool input (unused).
	 * @return array<string, mixed>
	 */
	private function execute_site_editor_context( array $input ) {
		unset( $input );

		$theme = wp_get_theme();

		return array(
			'theme'   => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => get_stylesheet(),
			),
			'presets' => array(
				'colors'        => $this->flatten_presets( $this->global_setting( array( 'color', 'palette' ) ) ),
				'gradients'     => $this->flatten_presets( $this->global_setting( array( 'color', 'gradients' ) ) ),
				'font_sizes'    => $this->flatten_presets( $this->global_setting( array( 'typography', 'fontSizes' ) ) ),
				'spacing_sizes' => $this->flatten_presets( $this->global_setting( array( 'spacing', 'spacingSizes' ) ) ),
			),
		);
	}

	/**
	 * Read a theme.json setting at the given path, or an empty array.
	 *
	 * @param string[] $path Settings path (e.g. ['color','palette']).
	 * @return mixed
	 */
	private function global_setting( array $path ) {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		return wp_get_global_settings( $path );
	}

	/**
	 * Flatten a preset value that may be keyed by origin (default/theme/custom)
	 * into a single list. Already-flat lists pass through unchanged.
	 *
	 * @param mixed $value Preset value from wp_get_global_settings().
	 * @return array<int, mixed>
	 */
	private function flatten_presets( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$origins   = array( 'default', 'theme', 'custom' );
		$by_origin = false;
		foreach ( $origins as $origin ) {
			if ( isset( $value[ $origin ] ) ) {
				$by_origin = true;
				break;
			}
		}

		if ( ! $by_origin ) {
			return array_values( $value );
		}

		$merged = array();
		foreach ( $origins as $origin ) {
			if ( isset( $value[ $origin ] ) && is_array( $value[ $origin ] ) ) {
				$merged = array_merge( $merged, $value[ $origin ] );
			}
		}
		return $merged;
	}

	/**
	 * Fetch a single block by ref or flat_index via the block REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_get_block( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$has_ref = isset( $input['ref'] ) && is_string( $input['ref'] ) && '' !== $input['ref'];
		$has_idx = isset( $input['flat_index'] ) && is_numeric( $input['flat_index'] );
		if ( $has_ref === $has_idx ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Provide exactly one of ref or flat_index.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$params = array( 'id' => $post_id );
		if ( $has_ref ) {
			$params['ref'] = (string) $input['ref'];
		} else {
			$params['flat_index'] = (int) $input['flat_index'];
		}

		$request = new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/block' );
		return $this->call_controller( array( $this->controller, 'get_block' ), $request, $params );
	}

	/**
	 * Update one block's attributes and/or innerHTML, addressed by ref or flat_index, via the block-update REST handlers.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_update_block( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$has_ref = isset( $input['ref'] ) && is_string( $input['ref'] ) && '' !== $input['ref'];
		$has_idx = isset( $input['flat_index'] ) && is_numeric( $input['flat_index'] ) && (int) $input['flat_index'] >= 0;
		if ( ! $has_ref && ! $has_idx ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Provide either flat_index or ref.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( $has_ref && $has_idx ) {
			return new \WP_Error(
				'ambiguous_target',
				__( 'Provide flat_index OR ref, not both.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$body = array();
		if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
			$body['attributes'] = $input['attributes'];
		}
		if ( isset( $input['innerHTML'] ) ) {
			$body['innerHTML'] = (string) $input['innerHTML'];
		}
		if ( empty( $body ) ) {
			return new \WP_Error(
				'missing_payload',
				__( 'At least one of attributes or innerHTML must be provided.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_ref ) {
			$ref     = rawurlencode( (string) $input['ref'] );
			$request = new \WP_REST_Request( 'PATCH', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/by-ref/' . $ref );
			return $this->call_controller(
				array( $this->controller, 'update_block_by_ref' ),
				$request,
				array(
					'id'  => $post_id,
					'ref' => (string) $input['ref'],
				),
				$body
			);
		}

		$index   = (int) $input['flat_index'];
		$request = new \WP_REST_Request( 'PATCH', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/' . $index );
		$request->set_param( 'index', $index );
		return $this->call_controller(
			array( $this->controller, 'update_block' ),
			$request,
			array(
				'id'    => $post_id,
				'index' => $index,
			),
			$body
		);
	}

	/**
	 * Batch-update multiple blocks in one revision via the blocks batch-update REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_update_blocks( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['updates'] ) || ! is_array( $input['updates'] ) ) {
			return new \WP_Error( 'missing_updates', __( 'updates must be a non-empty array.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = array( 'updates' => $input['updates'] );
		if ( ! empty( $input['verbose'] ) ) {
			$body['verbose'] = true;
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/batch-update' );
		return $this->call_controller(
			array( $this->controller, 'update_blocks_batch' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Insert one or more blocks into a post via the insert-blocks REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_insert_blocks( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['blocks'] ) || ! is_array( $input['blocks'] ) ) {
			return new \WP_Error( 'missing_blocks', __( 'At least one block is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = array( 'blocks' => $input['blocks'] );
		if ( isset( $input['after_top_level'] ) ) {
			$body['after'] = $input['after_top_level'];
		}
		if ( isset( $input['before_top_level'] ) ) {
			$body['before'] = (int) $input['before_top_level'];
		}
		if ( isset( $input['after_ref'] ) ) {
			$body['after_ref'] = (string) $input['after_ref'];
		}
		if ( isset( $input['before_ref'] ) ) {
			$body['before_ref'] = (string) $input['before_ref'];
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks' );
		return $this->call_controller(
			array( $this->controller, 'insert_blocks' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Delete one or more blocks, addressed by ref or top-level counter, via the block-delete REST handlers.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_delete_block( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$has_ref     = isset( $input['ref'] ) && is_string( $input['ref'] ) && '' !== $input['ref'];
		$has_counter = isset( $input['top_level_counter'] ) && is_numeric( $input['top_level_counter'] ) && (int) $input['top_level_counter'] >= 0;
		if ( ! $has_ref && ! $has_counter ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Provide either top_level_counter or ref.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( $has_ref && $has_counter ) {
			return new \WP_Error(
				'ambiguous_target',
				__( 'Provide top_level_counter OR ref, not both.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$params = array( 'id' => $post_id );
		if ( isset( $input['count'] ) ) {
			$params['count'] = (int) $input['count'];
		}

		if ( $has_ref ) {
			$ref     = rawurlencode( (string) $input['ref'] );
			$request = new \WP_REST_Request( 'DELETE', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/by-ref/' . $ref );
			$request->set_param( 'ref', (string) $input['ref'] );
			return $this->call_controller( array( $this->controller, 'delete_block_by_ref' ), $request, $params );
		}

		$index   = (int) $input['top_level_counter'];
		$request = new \WP_REST_Request( 'DELETE', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/' . $index );
		$request->set_param( 'index', $index );
		$params['index'] = $index;
		return $this->call_controller( array( $this->controller, 'delete_block' ), $request, $params );
	}

	/**
	 * Replace a contiguous range of top-level blocks via the blocks-replace REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_replace_block_range( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = array(
			'start'  => isset( $input['start'] ) ? (int) $input['start'] : 0,
			'count'  => isset( $input['count'] ) ? (int) $input['count'] : 0,
			'blocks' => isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array(),
		);

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks/replace' );
		return $this->call_controller(
			array( $this->controller, 'replace_blocks_range' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Replace all of a post's blocks in one write via the replace-all-blocks REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_rewrite_post_blocks( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['blocks'] ) || ! is_array( $input['blocks'] ) ) {
			return new \WP_Error( 'missing_blocks', __( 'At least one block is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( 'PUT', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/blocks' );
		return $this->call_controller(
			array( $this->controller, 'replace_all_blocks' ),
			$request,
			array( 'id' => $post_id ),
			array( 'blocks' => $input['blocks'] )
		);
	}

	/**
	 * Revert a post's blocks to a prior revision via the revert REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_revert_to_revision( array $input ) {
		$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;
		if ( $post_id <= 0 || $revision_id <= 0 ) {
			return new \WP_Error(
				'missing_ids',
				__( 'post_id and revision_id are required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/revert' );
		return $this->call_controller(
			array( $this->controller, 'revert_to_revision' ),
			$request,
			array( 'id' => $post_id ),
			array( 'revision_id' => $revision_id )
		);
	}

	/**
	 * Run a path-based block-tree mutation via the mutate REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_edit_block_tree( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = $input;
		unset( $body['post_id'] );

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/mutate' );
		return $this->call_controller(
			array( $this->controller, 'mutate_block_tree' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Insert a pattern (synced or inline) via the insert-pattern REST handler and annotate the result with a synced/inline note.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_insert_pattern( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 || ! isset( $input['pattern_id'] ) ) {
			return new \WP_Error(
				'missing_fields',
				__( 'post_id and pattern_id are required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'pattern_id' => $input['pattern_id'],
			'synced'     => array_key_exists( 'synced', $input ) ? (bool) $input['synced'] : true,
		);
		if ( isset( $input['after_top_level'] ) ) {
			$body['after'] = (int) $input['after_top_level'];
		}
		if ( isset( $input['before_top_level'] ) ) {
			$body['before'] = (int) $input['before_top_level'];
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id . '/insert-pattern' );
		$data    = $this->call_controller(
			array( $this->controller, 'insert_pattern' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$synced       = ! empty( $data['synced'] );
		$data['note'] = $synced
			? __( 'Pattern inserted as synced reference. Changes to the source pattern will update this page.', 'gk-block-mcp' )
			: __( 'Pattern blocks inserted inline. This copy is independent and can be edited per-page.', 'gk-block-mcp' );

		return $data;
	}

	/**
	 * Create a post via the posts REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_create_post( array $input ) {
		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/posts' );
		return $this->call_controller( array( $this->controller, 'create_post' ), $request, array(), $input );
	}

	/**
	 * Update a post's fields (status, terms, author, etc.) via the post-update REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_update_post( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = $input;
		unset( $body['post_id'] );
		if ( empty( $body ) ) {
			return new \WP_Error(
				'missing_fields',
				__( 'Provide at least one mutating field besides post_id.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$request = new \WP_REST_Request( 'PATCH', '/' . REST_Controller::NAMESPACE . '/posts/' . $post_id );
		return $this->call_controller(
			array( $this->controller, 'update_post' ),
			$request,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * List terms via the terms REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_list_terms( array $input ) {
		return $this->call_controller(
			array( $this->controller, 'list_terms' ),
			new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/terms' ),
			$input
		);
	}

	/**
	 * Validate a single media source (URL or base64) and upload it via the media REST handler.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_upload_media( array $input ) {
		if ( isset( $input['path'] ) && is_string( $input['path'] ) && '' !== $input['path'] ) {
			return new \WP_Error(
				'unsupported_path',
				__( 'upload_media path mode is only supported by the npm MCP server. Use url or data_base64 here.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$modes = array_filter(
			array( 'url', 'data_base64' ),
			static function ( $key ) use ( $input ) {
				return isset( $input[ $key ] ) && is_string( $input[ $key ] ) && '' !== $input[ $key ];
			}
		);
		if ( empty( $modes ) ) {
			return new \WP_Error(
				'missing_source',
				__( 'upload_media: provide one of url or data_base64.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $modes ) > 1 ) {
			return new \WP_Error(
				'ambiguous_source',
				__( 'upload_media: only one of url or data_base64 may be supplied.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( ! empty( $input['data_base64'] ) && empty( $input['filename'] ) ) {
			return new \WP_Error(
				'missing_filename',
				__( 'upload_media: filename is required when using data_base64.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/media' );
		return $this->call_controller( array( $this->controller, 'upload_media' ), $request, array(), $input );
	}

	/**
	 * Fetch a post's Yoast SEO data via the Yoast bridge, when Yoast is active.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_yoast_get_seo( array $input ) {
		if ( ! Yoast_Bridge::is_yoast_active() ) {
			return new \WP_Error(
				'yoast_unavailable',
				__( 'Yoast SEO is not active on this site.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( 'GET', '/' . REST_Controller::NAMESPACE . '/yoast/' . $post_id );
		return $this->call_controller( array( $this->yoast_bridge, 'get_seo' ), $request, array( 'post_id' => $post_id ) );
	}

	/**
	 * Update a post's Yoast SEO data via the Yoast bridge, when Yoast is active.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_yoast_update_seo( array $input ) {
		if ( ! Yoast_Bridge::is_yoast_active() ) {
			return new \WP_Error(
				'yoast_unavailable',
				__( 'Yoast SEO is not active on this site.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$body = $input;
		unset( $body['post_id'] );

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/yoast/' . $post_id );
		return $this->call_controller( array( $this->yoast_bridge, 'update_seo' ), $request, array( 'post_id' => $post_id ), $body );
	}

	/**
	 * Bulk-update Yoast SEO data for multiple posts via the Yoast bridge, when Yoast is active.
	 *
	 * @param array<string, mixed> $input Tool input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_yoast_bulk_update_seo( array $input ) {
		if ( ! Yoast_Bridge::is_yoast_active() ) {
			return new \WP_Error(
				'yoast_unavailable',
				__( 'Yoast SEO is not active on this site.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $input['posts'] ) || ! is_array( $input['posts'] ) ) {
			return new \WP_Error(
				'missing_posts',
				__( 'yoast_bulk_update_seo: non-empty posts array is required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$request = new \WP_REST_Request( 'POST', '/' . REST_Controller::NAMESPACE . '/yoast/bulk' );
		return $this->call_controller(
			array( $this->yoast_bridge, 'bulk_update_seo' ),
			$request,
			array(),
			array( 'posts' => $input['posts'] )
		);
	}

	/**
	 * Invoke a REST controller handler and normalize its response payload.
	 *
	 * Route params land in the request's URL parameter bucket via
	 * set_url_params(), never set_param(). WP_REST_Request::set_param()
	 * writes an unmatched key into whichever bucket is first in parameter
	 * priority order at that moment — the POST bucket for a PATCH/POST/PUT/
	 * DELETE request without a JSON content type yet — and set_body_params()
	 * below fully replaces the POST bucket, silently dropping anything
	 * written there beforehand. The URL bucket is never touched by
	 * set_body_params(), so route params (post id, block index, etc.)
	 * survive regardless of call order.
	 *
	 * @param callable             $callback Controller method.
	 * @param \WP_REST_Request     $request  Prepared request.
	 * @param array<string, mixed> $params   Route/query params to merge.
	 * @param array<string, mixed> $json     Optional JSON body params.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call_controller( callable $callback, \WP_REST_Request $request, array $params = array(), array $json = array() ) {
		$route_params = array_filter(
			$params,
			static function ( $value ) {
				return null !== $value;
			}
		);
		if ( ! empty( $route_params ) ) {
			$request->set_url_params( array_merge( $request->get_url_params(), $route_params ) );
		}

		if ( ! empty( $json ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $json ) );
			$request->set_body_params( $json );
		}

		$response = call_user_func( $callback, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response instanceof \WP_REST_Response ) {
			if ( $response->is_error() ) {
				return $response->as_error();
			}
			$data = $response->get_data();
			return is_array( $data ) ? $data : array( 'result' => $data );
		}

		return new \WP_Error(
			'unexpected_response',
			__( 'Unexpected response from Block MCP handler.', 'gk-block-mcp' ),
			array( 'status' => 500 )
		);
	}
}
