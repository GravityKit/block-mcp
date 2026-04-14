<?php
/**
 * REST API endpoint registration.
 *
 * Registers all gk-block-api/v1 routes, handles input sanitization and
 * validation, and delegates to the appropriate service classes.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class REST_Controller
 *
 * Registers and handles all REST endpoints for the Block API.
 */
class REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'gk-block-api/v1';

	/**
	 * Block registry instance.
	 *
	 * @var Block_Registry
	 */
	private $block_registry;

	/**
	 * Pattern manager instance.
	 *
	 * @var Pattern_Manager
	 */
	private $pattern_manager;

	/**
	 * Block CRUD instance.
	 *
	 * @var Block_CRUD
	 */
	private $block_crud;

	/**
	 * Block mutator instance.
	 *
	 * @var Block_Mutator
	 */
	private $block_mutator;

	/**
	 * Usage stats instance.
	 *
	 * @var Usage_Stats
	 */
	private $usage_stats;

	/**
	 * Constructor.
	 *
	 * @param Block_Registry  $block_registry  Block registry.
	 * @param Pattern_Manager $pattern_manager Pattern manager.
	 * @param Block_CRUD      $block_crud      Block CRUD.
	 * @param Usage_Stats     $usage_stats     Usage stats.
	 * @param Block_Mutator   $block_mutator   Block mutator.
	 */
	public function __construct(
		Block_Registry $block_registry,
		Pattern_Manager $pattern_manager,
		Block_CRUD $block_crud,
		Usage_Stats $usage_stats,
		Block_Mutator $block_mutator
	) {
		$this->block_registry  = $block_registry;
		$this->pattern_manager = $pattern_manager;
		$this->block_crud      = $block_crud;
		$this->usage_stats     = $usage_stats;
		$this->block_mutator   = $block_mutator;
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes() {
		// Block type discovery.
		register_rest_route(
			self::NAMESPACE,
			'/block-types',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_block_types' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'namespace' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'preferred' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/block-types/(?P<namespace>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_block_types_by_namespace' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'namespace' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Patterns.
		register_rest_route(
			self::NAMESPACE,
			'/patterns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_patterns' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => $this->get_pattern_query_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/patterns/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_patterns' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/patterns/(?P<id>[\w-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pattern' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// Site usage.
		register_rest_route(
			self::NAMESPACE,
			'/site-usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_site_usage' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Post blocks — GET.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/blocks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_blocks' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'fields' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Comma-separated list of fields to include (e.g. "path,name,attributes"). Omit for all fields.',
						),
						'search' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Filter blocks by text content (searches innerHTML).',
						),
						'block_name' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Filter blocks by block name (e.g. "core/button").',
						),
						'render' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Include rendered output for dynamic blocks, expand shortcodes, resolve synced pattern content, and mark blocks as dynamic/static.',
						),
					),
				),
				// POST — insert blocks.
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'insert_blocks' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'after'  => array(
							'type' => array( 'integer', 'string' ),
						),
						'before' => array(
							'type' => 'integer',
						),
						'blocks' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'object',
							),
						),
					),
				),
				// PUT — replace all blocks.
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'replace_all_blocks' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'blocks' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'object',
							),
						),
					),
				),
			)
		);

		// PATCH — update a single block.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/blocks/(?P<index>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_block' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'index' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'attributes' => array(
							'type' => 'object',
						),
						'innerHTML' => array(
							'type' => 'string',
						),
					),
				),
				// DELETE — remove a block.
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_block' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'index' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'count' => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Insert pattern.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/insert-pattern',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'insert_pattern' ),
				'permission_callback' => array( $this, 'check_edit_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'pattern_id' => array(
						'type'     => array( 'integer', 'string' ),
						'required' => true,
					),
					'after'  => array(
						'type' => array( 'integer', 'string' ),
					),
					'before' => array(
						'type' => 'integer',
					),
					'synced' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// URL-to-post resolver.
		register_rest_route(
			self::NAMESPACE,
			'/resolve',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve_url' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'URL path or full URL to resolve (e.g. "/products/gravityedit/" or "https://www.gravitykit.com/products/gravityedit/")',
					),
				),
			)
		);

		// Mutate block tree (path-based operations).
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/mutate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mutate_block_tree' ),
				'permission_callback' => array( $this, 'check_edit_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'op' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array(
							'update-attrs', 'update-html', 'replace-block', 'remove-block',
							'wrap-in-group', 'unwrap-group', 'insert-child', 'duplicate', 'move',
						),
					),
					'path' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'attributes'  => array( 'type' => 'object' ),
					'innerHTML'   => array( 'type' => 'string' ),
					'block'       => array( 'type' => 'object' ),
					'wrapper'     => array( 'type' => 'object' ),
					'position'    => array( 'type' => array( 'integer', 'string' ) ),
					'destination' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'before' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'count' => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'dry_run' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Validate and simulate the mutation without saving. Returns what would happen.',
					),
				),
			)
		);

		// Revert to revision.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/revert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revert_to_revision' ),
				'permission_callback' => array( $this, 'check_edit_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'revision_id' => array(
						'type'     => 'integer',
						'required' => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for read endpoints.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permissions() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'gk-block-api' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for write endpoints.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_edit_permissions() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit posts.', 'gk-block-api' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// =========================================================================
	// Error Handler
	// =========================================================================

	/**
	 * Convert an uncaught exception into a WP_Error REST response.
	 *
	 * @param \Throwable $e The caught exception.
	 *
	 * @return \WP_Error
	 */
	private function handle_error( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GK Block API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}

		return new \WP_Error(
			'internal_error',
			__( 'An unexpected error occurred.', 'gk-block-api' ),
			array(
				'status' => 500,
				'detail' => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : '',
			)
		);
	}

	// =========================================================================
	// Block Type Endpoints
	// =========================================================================

	/**
	 * GET /block-types
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_block_types( $request ) {
		try {
			$args = array(
				'namespace'      => $request->get_param( 'namespace' ),
				'category'       => $request->get_param( 'category' ),
				'preferred_only' => (bool) $request->get_param( 'preferred' ),
			);

			$block_types = $this->block_registry->get_block_types( $args );

			return new \WP_REST_Response( array( 'block_types' => $block_types ), 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /block-types/{namespace}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_block_types_by_namespace( $request ) {
		try {
			$args = array(
				'namespace' => $request->get_param( 'namespace' ),
			);

			$block_types = $this->block_registry->get_block_types( $args );

			return new \WP_REST_Response( array( 'block_types' => $block_types ), 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// Pattern Endpoints
	// =========================================================================

	/**
	 * GET /patterns
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_patterns( $request ) {
		try {
			$args = array(
				'q'         => $request->get_param( 'q' ),
				'synced'    => $request->get_param( 'synced' ),
				'min_score' => $request->get_param( 'min_score' ),
				'category'  => $request->get_param( 'category' ),
				'limit'     => $request->get_param( 'limit' ),
				'order_by'  => $request->get_param( 'order_by' ),
			);

			// Normalize synced param: "true"/"false" strings to booleans, null if not set.
			if ( null !== $args['synced'] ) {
				$args['synced'] = rest_sanitize_boolean( $args['synced'] );
			}

			$patterns = $this->pattern_manager->get_patterns( $args );

			return new \WP_REST_Response( array( 'patterns' => $patterns ), 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /patterns/search?q={term}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function search_patterns( $request ) {
		try {
			$args = array(
				'q'     => $request->get_param( 'q' ),
				'limit' => $request->get_param( 'limit' ),
			);

			$patterns = $this->pattern_manager->get_patterns( $args );

			return new \WP_REST_Response( array( 'patterns' => $patterns ), 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /patterns/{id}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pattern( $request ) {
		try {
			$id = $request->get_param( 'id' );

			$pattern = $this->pattern_manager->get_pattern( $id );

			if ( null === $pattern ) {
				return new \WP_Error(
					'pattern_not_found',
					__( 'Pattern not found.', 'gk-block-api' ),
					array( 'status' => 404 )
				);
			}

			// Include parsed block content, formatted for consistent output.
			$raw_blocks        = $this->pattern_manager->get_pattern_blocks( $pattern );
			$pattern['blocks'] = $this->block_crud->format_blocks( $raw_blocks );

			return new \WP_REST_Response( $pattern, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// Site Usage Endpoint
	// =========================================================================

	/**
	 * GET /site-usage
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_site_usage( $request ) {
		try {
			$refresh = (bool) $request->get_param( 'refresh' );
			$stats   = $this->usage_stats->get_stats( $refresh );

			return new \WP_REST_Response( $stats, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// URL Resolver Endpoint
	// =========================================================================

	/**
	 * GET /resolve
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve_url( $request ) {
		try {
			$url = $request->get_param( 'url' );

			// Extract path from full URL if needed.
			if ( false !== strpos( $url, '://' ) ) {
				$url = wp_parse_url( $url, PHP_URL_PATH );
			}

			// Use url_to_postid() which handles all post types, permalinks, etc.
			$post_id = url_to_postid( home_url( $url ) );

			if ( ! $post_id ) {
				return new \WP_Error(
					'not_found',
					__( 'No post found for this URL.', 'gk-block-api' ),
					array( 'status' => 404 )
				);
			}

			$post = get_post( $post_id );

			return new \WP_REST_Response( array(
				'post_id'   => $post_id,
				'post_type' => $post->post_type,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'slug'      => $post->post_name,
				'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			), 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// Post Block Endpoints
	// =========================================================================

	/**
	 * GET /posts/{id}/blocks
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_blocks( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );

			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$render = (bool) $request->get_param( 'render' );
			$blocks = $this->block_crud->get_blocks( $post_id, $render );

			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}

			// Search/filter runs FIRST on full data (needs innerHTML to search).
			$search     = $request->get_param( 'search' );
			$block_name = $request->get_param( 'block_name' );
			$is_search  = ! empty( $search ) || ! empty( $block_name );

			if ( $is_search ) {
				$blocks = $this->search_blocks( $blocks, $search ?: '', $block_name ?: '' );
			}

			// Fields filter runs AFTER search to strip unneeded data.
			$fields = $request->get_param( 'fields' );
			if ( ! empty( $fields ) ) {
				$allowed = array_map( 'trim', explode( ',', $fields ) );
				$blocks  = $this->filter_block_fields( $blocks, $allowed );
			}

			$response = array( 'blocks' => $blocks );
			if ( $is_search ) {
				$response['match_count'] = count( $blocks );
			}

			return new \WP_REST_Response( $response, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * PATCH /posts/{id}/blocks/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_block( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$index      = (int) $request->get_param( 'index' );
			$attributes = $request->get_param( 'attributes' );
			$inner_html = $request->get_param( 'innerHTML' );

			if ( null === $attributes && null === $inner_html ) {
				return new \WP_Error(
					'missing_data',
					__( 'At least one of "attributes" or "innerHTML" must be provided.', 'gk-block-api' ),
					array( 'status' => 400 )
				);
			}

			$result = $this->block_crud->update_block(
				$post_id,
				$index,
				is_array( $attributes ) ? $attributes : array(),
				$inner_html
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /posts/{id}/blocks
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insert_blocks( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$blocks = $request->get_param( 'blocks' );

			// Determine position.
			$position = null;
			if ( null !== $request->get_param( 'after' ) ) {
				$position = $request->get_param( 'after' );
			} elseif ( null !== $request->get_param( 'before' ) ) {
				// "before" index N = "after" index N-1.
				$before   = (int) $request->get_param( 'before' );
				$position = $before > 0 ? $before - 1 : 'start';
			}

			if ( empty( $blocks ) || ! is_array( $blocks ) ) {
				return new \WP_Error(
					'missing_blocks',
					__( 'The "blocks" parameter is required and must be a non-empty array.', 'gk-block-api' ),
					array( 'status' => 400 )
				);
			}

			// Sanitize block definitions.
			$sanitized_blocks = array();
			foreach ( $blocks as $block_def ) {
				$sanitized_blocks[] = array(
					'name'       => isset( $block_def['name'] ) ? sanitize_text_field( $block_def['name'] ) : '',
					'attributes' => isset( $block_def['attributes'] ) ? $block_def['attributes'] : array(),
					'innerHTML'  => isset( $block_def['innerHTML'] ) ? wp_kses_post( $block_def['innerHTML'] ) : '',
				);
			}

			$result = $this->block_crud->insert_blocks( $post_id, $position, $sanitized_blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 201 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * DELETE /posts/{id}/blocks/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_block( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$index = (int) $request->get_param( 'index' );
			$count   = (int) $request->get_param( 'count' );

			$result = $this->block_crud->delete_blocks( $post_id, $index, $count );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * PUT /posts/{id}/blocks
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replace_all_blocks( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$blocks = $request->get_param( 'blocks' );

			if ( empty( $blocks ) || ! is_array( $blocks ) ) {
				return new \WP_Error(
					'missing_blocks',
					__( 'The "blocks" parameter is required and must be a non-empty array.', 'gk-block-api' ),
					array( 'status' => 400 )
				);
			}

			// Sanitize block definitions.
			$sanitized_blocks = array();
			foreach ( $blocks as $block_def ) {
				$sanitized_blocks[] = array(
					'name'       => isset( $block_def['name'] ) ? sanitize_text_field( $block_def['name'] ) : '',
					'attributes' => isset( $block_def['attributes'] ) ? $block_def['attributes'] : array(),
					'innerHTML'  => isset( $block_def['innerHTML'] ) ? wp_kses_post( $block_def['innerHTML'] ) : '',
				);
			}

			$result = $this->block_crud->replace_all_blocks( $post_id, $sanitized_blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /posts/{id}/insert-pattern
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insert_pattern( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$pattern_id = $request->get_param( 'pattern_id' );
			$synced     = (bool) $request->get_param( 'synced' );

			// Determine position.
			$position = null;
			if ( null !== $request->get_param( 'after' ) ) {
				$position = $request->get_param( 'after' );
			} elseif ( null !== $request->get_param( 'before' ) ) {
				$before   = (int) $request->get_param( 'before' );
				$position = $before > 0 ? $before - 1 : 'start';
			}

			$result = $this->block_crud->insert_pattern( $post_id, $pattern_id, $position, $synced );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 201 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /posts/{id}/mutate
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mutate_block_tree( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );

			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$op   = $request->get_param( 'op' );
			$path = $request->get_param( 'path' );

			// Cast path elements to integers.
			$path = array_map( 'intval', $path );

			$params = array(
				'attributes'  => $request->get_param( 'attributes' ),
				'innerHTML'   => $request->get_param( 'innerHTML' ),
				'block'       => $request->get_param( 'block' ),
				'wrapper'     => $request->get_param( 'wrapper' ),
				'position'    => $request->get_param( 'position' ),
				'destination' => $request->get_param( 'destination' ),
				'before'      => $request->get_param( 'before' ),
				'count'       => $request->get_param( 'count' ),
			);

			// Cast destination to integers if present.
			if ( is_array( $params['destination'] ) ) {
				$params['destination'] = array_map( 'intval', $params['destination'] );
			}

			// Cast before to integers if present.
			if ( is_array( $params['before'] ) ) {
				$params['before'] = array_map( 'intval', $params['before'] );
			}

			$dry_run = (bool) $request->get_param( 'dry_run' );
			$result  = $this->block_mutator->mutate( $post_id, $op, $path, $params, $dry_run );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /posts/{id}/revert
	 *
	 * Revert a post to a specific revision.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revert_to_revision( $request ) {
		try {
			$post_id     = (int) $request->get_param( 'id' );
			$revision_id = (int) $request->get_param( 'revision_id' );

			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$result = $this->block_crud->revert_to_revision( $post_id, $revision_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Check if the current user can edit a specific post, with detailed error.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return true|\WP_Error True if allowed, WP_Error with details if not.
	 */
	private function check_post_edit_permission( $post_id ) {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		$post = get_post( $post_id );
		$post_type_obj = $post ? get_post_type_object( $post->post_type ) : null;
		$required_cap = $post_type_obj ? $post_type_obj->cap->edit_post : 'edit_post';
		$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'post';

		return new \WP_Error(
			'rest_forbidden',
			sprintf(
				/* translators: 1: post type label, 2: required capability */
				__( 'You do not have permission to edit this %1$s. Required capability: %2$s.', 'gk-block-api' ),
				$post_type_label,
				$required_cap
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Filter blocks by search text and/or block name.
	 *
	 * Returns a flat list of matching blocks from the tree (not nested).
	 *
	 * @param array  $blocks    Formatted blocks.
	 * @param string $search    Text to search in innerHTML.
	 * @param string $block_name Block name to filter by.
	 *
	 * @return array Flat list of matching blocks.
	 */
	private function search_blocks( $blocks, $search = '', $block_name = '' ) {
		$results = array();

		foreach ( $blocks as $block ) {
			$matches = true;

			if ( ! empty( $search ) ) {
				$text = isset( $block['innerHTML'] ) ? strip_tags( $block['innerHTML'] ) : '';
				if ( false === stripos( $text, $search ) ) {
					$matches = false;
				}
			}

			if ( ! empty( $block_name ) ) {
				if ( $block['name'] !== $block_name ) {
					$matches = false;
				}
			}

			if ( $matches ) {
				// Return a flat copy without innerBlocks to keep results clean.
				$result = $block;
				unset( $result['innerBlocks'] );
				$results[] = $result;
			}

			// Always recurse into children.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$child_results = $this->search_blocks( $block['innerBlocks'], $search, $block_name );
				$results = array_merge( $results, $child_results );
			}
		}

		return $results;
	}

	/**
	 * Filter block data to include only specified fields.
	 *
	 * @param array $blocks   Formatted blocks.
	 * @param array $allowed  List of field names to keep.
	 * @return array Filtered blocks.
	 */
	private function filter_block_fields( $blocks, $allowed ) {
		// Always include innerBlocks for tree structure.
		$always_keep = array( 'innerBlocks' );
		$keep        = array_unique( array_merge( $allowed, $always_keep ) );

		$filtered = array();
		foreach ( $blocks as $block ) {
			$item = array();
			foreach ( $keep as $field ) {
				if ( isset( $block[ $field ] ) ) {
					$item[ $field ] = $block[ $field ];
				}
			}
			// Recurse into innerBlocks.
			if ( isset( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				$item['innerBlocks'] = $this->filter_block_fields( $block['innerBlocks'], $allowed );
			}
			$filtered[] = $item;
		}
		return $filtered;
	}

	/**
	 * Get the standard pattern query args for route registration.
	 *
	 * @return array
	 */
	private function get_pattern_query_args() {
		return array(
			'q'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'synced'    => array(
				'type' => 'string', // Will be cast to bool if provided.
			),
			'min_score' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'intval',
			),
			'category'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'     => array(
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
			'order_by'  => array(
				'type'              => 'string',
				'default'           => 'score',
				'enum'              => array( 'score', 'usage', 'date', 'name' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
