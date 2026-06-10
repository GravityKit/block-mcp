<?php
/**
 * REST API endpoint registration.
 *
 * Registers all gk-block-api/v1 routes, handles input sanitization and
 * validation, and delegates to the appropriate service classes.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

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
	 * Site-wide block inventory.
	 *
	 * @var Block_Inventory
	 */
	private $block_inventory;

	/**
	 * Post manager instance (create_post, update_post).
	 *
	 * @var Post_Manager
	 */
	private $post_manager;

	/**
	 * Term manager instance (list_terms).
	 *
	 * @var Term_Manager
	 */
	private $term_manager;

	/**
	 * Media manager instance (upload_media).
	 *
	 * @var Media_Manager
	 */
	private $media_manager;

	/**
	 * Preferences instance for tier classification.
	 *
	 * Used by the summary builder to classify blocks by tier without hardcoded
	 * namespace lists.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Constructor.
	 *
	 * @param Block_Registry  $block_registry  Block registry.
	 * @param Pattern_Manager $pattern_manager Pattern manager.
	 * @param Block_CRUD      $block_crud      Block CRUD.
	 * @param Block_Inventory $block_inventory Site-wide block inventory.
	 * @param Block_Mutator   $block_mutator   Block mutator.
	 * @param Post_Manager    $post_manager    Post manager.
	 * @param Term_Manager    $term_manager    Term manager.
	 * @param Media_Manager   $media_manager   Media manager.
	 * @param Preferences     $preferences     Preferences (tier classification source).
	 */
	public function __construct(
		Block_Registry $block_registry,
		Pattern_Manager $pattern_manager,
		Block_CRUD $block_crud,
		Block_Inventory $block_inventory,
		Block_Mutator $block_mutator,
		Post_Manager $post_manager,
		Term_Manager $term_manager,
		Media_Manager $media_manager,
		Preferences $preferences
	) {
		$this->block_registry  = $block_registry;
		$this->pattern_manager = $pattern_manager;
		$this->block_crud      = $block_crud;
		$this->block_inventory = $block_inventory;
		$this->block_mutator   = $block_mutator;
		$this->post_manager    = $post_manager;
		$this->term_manager    = $term_manager;
		$this->media_manager   = $media_manager;
		$this->preferences     = $preferences;
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
					'namespace'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'preferred_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'tier'           => array(
						'type' => 'string',
						'enum' => array( 'preferred', 'acceptable', 'avoid', 'legacy' ),
					),
					'storage_mode'   => array(
						'type' => 'string',
						'enum' => array( 'static', 'dynamic', 'dual' ),
					),
					'search'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'usage_only'     => array(
						'type'    => 'boolean',
						'default' => false,
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
					'q'     => array(
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

		// Storage-mode auto-discovery scan (BLOCK-13). Capability is
		// `manage_options` (not `edit_posts`) — the scan walks every public
		// post type and writes a site-wide option. Editor-role users should
		// not be able to trigger it.
		register_rest_route(
			self::NAMESPACE,
			'/storage-modes/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_storage_modes' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
				'args'                => array(),
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
						'id'                   => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'fields'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Comma-separated list of fields to include (e.g. "path,name,attributes"). Omit for all fields.',
						),
						'search'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Filter blocks by text content (searches innerHTML).',
						),
						'block_name'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Filter blocks by block name (e.g. "core/button").',
						),
						'render'               => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Include rendered output for dynamic blocks, expand shortcodes, resolve synced pattern content.',
						),
						'outline'              => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Return only headings and section markers as a flat outline for fast page structure scanning.',
						),
						'summary_only'         => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Return only the top-level summary object (no blocks array). Useful for quick page inspection.',
						),
						'include_legacy_paths' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Add summary.legacy_blocks.paths. Aggregate counts always included.',
						),
						'persist_refs'         => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Assign and persist stable gk_ref UUIDs on blocks missing them. Default true. Set false for read-only callers that do not want write side effects (refs in response will not resolve later).',
						),
						'cursor'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Opaque cursor for paginating top-level blocks. Pass the previous response\'s pagination.next_cursor here. Omit on the first request.',
						),
						'limit'                => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => 'Number of top-level blocks per page (each retains its full nested innerBlocks tree). Default 25, max 100. Triggers paginated response.',
						),
					),
				),
				// POST — insert blocks.
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'insert_blocks' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'after'      => array(
							'type' => array( 'integer', 'string' ),
						),
						'before'     => array(
							'type' => 'integer',
						),
						'after_ref'  => array(
							'type'        => 'string',
							'description' => 'Insert after the top-level block with this gk_ref (alternative to "after").',
						),
						'before_ref' => array(
							'type'        => 'string',
							'description' => 'Insert before the top-level block with this gk_ref (alternative to "before").',
						),
						'blocks'     => array(
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
						'id'     => array(
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

		// POST — atomic batch update of N independent blocks in ONE revision.
		// Each item targets one block by ref XOR flat_index; all-or-nothing
		// validation prevents partial writes when any item is invalid.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/blocks/batch-update',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_blocks_batch' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'updates' => array(
							'type'        => 'array',
							'required'    => true,
							'description' => 'List of update items. Each item targets one block by ref XOR flat_index, with attributes and/or innerHTML.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'ref'        => array( 'type' => 'string' ),
									'flat_index' => array( 'type' => 'integer' ),
									'attributes' => array( 'type' => 'object' ),
									'innerHTML'  => array( 'type' => 'string' ),
								),
							),
						),
						'verbose' => array(
							'type'        => 'boolean',
							'required'    => false,
							'default'     => false,
							'description' => 'When true, each result includes a `saved` snapshot (post-save innerHTML + attributes). Default false to keep batch responses compact.',
						),
					),
				),
			)
		);

		// GET — single-block fetch by ref or flat_index. Returns the same
		// `saved` shape that write endpoints echo, so verification reads use
		// the identical contract as the writes that produced them.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/block',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_block' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'ref'        => array(
							'type'        => 'string',
							'required'    => false,
							'description' => 'Stable gk_ref. Provide this OR flat_index.',
						),
						'flat_index' => array(
							'type'        => 'integer',
							'required'    => false,
							'description' => 'Flat block index. Provide this OR ref.',
						),
					),
				),
			)
		);

		// POST — atomic replace of a top-level block range.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/blocks/replace',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'replace_blocks_range' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'start'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'count'  => array(
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
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'index'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'attributes' => array(
							'type' => 'object',
						),
						'innerHTML'  => array(
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
						'id'    => array(
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

		// Ref-addressed PATCH/DELETE — same semantics as index-addressed routes,
		// but the target is resolved via attrs.metadata.gk_ref instead of a flat
		// index. Refs survive sibling shifts so chained mutations don't go stale.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/blocks/by-ref/(?P<ref>blk_[a-f0-9]+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_block_by_ref' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'ref'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'attributes' => array( 'type' => 'object' ),
						'innerHTML'  => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_block_by_ref' ),
					'permission_callback' => array( $this, 'check_edit_permissions' ),
					'args'                => array(
						'id'    => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'ref'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
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
					'id'         => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'pattern_id' => array(
						'type'     => array( 'integer', 'string' ),
						'required' => true,
					),
					'after'      => array(
						'type' => array( 'integer', 'string' ),
					),
					'before'     => array(
						'type' => 'integer',
					),
					'synced'     => array(
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

		// Search posts by title/slug/content with filters.
		register_rest_route(
			self::NAMESPACE,
			'/find-posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'find_posts' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'search'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Free-text across title + content.',
					),
					'post_type'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Single or comma-separated. Default: public types.',
					),
					'post_status' => array(
						'type'              => 'string',
						'default'           => 'publish',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'publish | draft | private | any | comma-separated.',
					),
					'per_page'    => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'description'       => 'Capped at 100.',
					),
					'page'        => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Single-post metadata lookup (post_id | url | slug+post_type).
		register_rest_route(
			self::NAMESPACE,
			'/post-info',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'post_info' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'post_id'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => 'One of post_id, url, or slug.',
					),
					'url'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Full URL or path. Resolved via url_to_postid.',
					),
					'slug'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'post_name. Combine with post_type for uniqueness.',
					),
					'post_type' => array(
						'type'              => 'string',
						'default'           => 'any',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Scope a slug lookup. Default: any.',
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
					'id'              => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'op'              => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array(
							'update-attrs',
							'update-html',
							'replace-block',
							'remove-block',
							'wrap-in-group',
							'unwrap-group',
							'insert-child',
							'duplicate',
							'move',
						),
					),
					'path'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Integer path to the target block. Provide this OR "ref".',
					),
					'ref'             => array(
						'type'        => 'string',
						'description' => 'Stable gk_ref of the target block (alternative to "path"). Survives sibling shifts.',
					),
					'attributes'      => array( 'type' => 'object' ),
					'innerHTML'       => array( 'type' => 'string' ),
					'block'           => array( 'type' => 'object' ),
					'wrapper'         => array( 'type' => 'object' ),
					'position'        => array( 'type' => array( 'integer', 'string' ) ),
					'destination'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Path the block(s) should land at after the move (move op).',
					),
					'destination_ref' => array(
						'type'        => 'string',
						'description' => 'Resolve destination from this ref instead of a path (move op).',
					),
					'count'           => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'dry_run'         => array(
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
					'id'          => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'revision_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// =====================================================================
		// v1.2 — Docs lifecycle (create_post, update_post, list_terms, upload_media).
		// =====================================================================

		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => array( $this, 'check_edit_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => array( $this, 'check_edit_permissions' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/terms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_terms' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'taxonomy'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'category',
					),
					'search'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'parent'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'hide_empty' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'per_page'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 100,
					),
					'page'       => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'orderby'    => array(
						'type'    => 'string',
						'enum'    => array( 'name', 'count', 'term_id', 'slug' ),
						'default' => 'name',
					),
					'order'      => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'asc',
					),
					'include'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'slug'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/media',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_media' ),
				'permission_callback' => array( $this, 'check_upload_permissions' ),
			)
		);

		// Per-site MCP serverInfo instructions addendum. PUBLIC: the value
		// reaches every connected MCP client at handshake before any
		// tool-call auth, so this endpoint must be readable unauthenticated.
		// Rate-limited at Instructions::RATE_LIMIT_PER_MIN per IP to deter
		// scraping. Admins MUST NOT put secrets in the option value; the UI
		// copy on the settings page warns about this.
		register_rest_route(
			self::NAMESPACE,
			'/instructions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_instructions' ),
				'permission_callback' => '__return_true',
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
				__( 'You do not have permission to access this endpoint.', 'gk-block-mcp' ),
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
				__( 'You do not have permission to edit posts.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for media upload.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_upload_permissions() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'rest_cannot_upload',
				__( 'You do not have permission to upload files.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /instructions — serve the site's MCP serverInfo addendum.
	 *
	 * Public endpoint by design. The MCP server fetches this at startup
	 * (before any tool call), combines with its hard-coded baseline, and
	 * passes the result to `McpServer`'s `instructions` field.
	 *
	 * Response shape: `{ addendum, length, max_length, updated_at }`. Empty
	 * addendum is returned as an empty string (NOT 404) so the client
	 * doesn't have to special-case missing-vs-empty.
	 *
	 * Cache-Control: `public, max-age=60` — fresh enough that admin edits
	 * land quickly in dev; long enough that legitimate clients don't hammer
	 * the endpoint. Caller-side cache key is the WordPress URL + path.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_instructions() {
		try {
			$ip = isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
				: '';

			if ( '' !== $ip && ! Instructions::check_rate_limit( $ip ) ) {
				return new \WP_Error(
					'rate_limit_exceeded',
					__( 'Too many requests. Try again in a minute.', 'gk-block-mcp' ),
					array( 'status' => 429 )
				);
			}

			$addendum   = Instructions::get_addendum();
			$updated_at = Instructions::get_updated_at();

			$response = rest_ensure_response(
				array(
					'addendum'   => $addendum,
					// `length` reports UTF-8 character count to match
					// Instructions::MAX_LENGTH semantics (also characters,
					// not bytes). Clients comparing the two stay
					// apples-to-apples.
					'length'     => mb_strlen( $addendum, 'UTF-8' ),
					'max_length' => Instructions::MAX_LENGTH,
					'updated_at' => $updated_at,
				)
			);

			// Short TTL public cache. Surrogates and reverse proxies are
			// welcome to cache; private intermediaries should not because
			// every visitor receives the same payload.
			$response->header( 'Cache-Control', 'public, max-age=60' );

			return $response;
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	// =========================================================================
	// v1.2 — Docs lifecycle handlers.
	// =========================================================================

	/**
	 * POST /posts — create a new post or page.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_post( $request ) {
		try {
			$args = $request->get_json_params();
			if ( ! is_array( $args ) ) {
				$args = $request->get_params();
			}
			$result = $this->post_manager->create_post( (array) $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * PATCH /posts/{id} — update post metadata or status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_post( $request ) {
		try {
			$post_id   = (int) $request['id'];
			$cap_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $cap_check ) ) {
				return $cap_check;
			}
			$args = $request->get_json_params();
			if ( ! is_array( $args ) ) {
				$args = $request->get_params();
			}
			// Strip the route 'id' param so we don't accidentally treat it as a body field.
			unset( $args['id'] );
			$result = $this->post_manager->update_post( $post_id, (array) $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /terms — list taxonomy terms.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_terms( $request ) {
		try {
			// get_params() merges route schema defaults (taxonomy, per_page,
			// page, orderby, order, hide_empty) and sanitized values into the
			// returned array. get_query_params() returns ONLY the raw query
			// string, which means the route's defaults and sanitize_callback
			// pipeline are bypassed for any missing key.
			$args   = (array) $request->get_params();
			$result = $this->term_manager->list_terms( $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /media — upload to the media library.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_media( $request ) {
		try {
			$args        = $request->get_params();
			$file_params = $request->get_file_params();
			if ( ! empty( $file_params ) ) {
				$first              = array_keys( $file_params )[0];
				$args['file_field'] = (string) $first;
			}
			$result = $this->media_manager->upload( (array) $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
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
		// Always log; never include exception detail in the API response. A
		// production site that accidentally has WP_DEBUG=true should not leak
		// filesystem paths or SQL/PHP internals to remote callers.
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
			error_log( 'Block MCP error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new \WP_Error(
			'internal_error',
			__( 'An unexpected error occurred.', 'gk-block-mcp' ),
			array( 'status' => 500 )
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
				'preferred_only' => (bool) $request->get_param( 'preferred_only' ),
				'tier'           => $request->get_param( 'tier' ),
				'storage_mode'   => $request->get_param( 'storage_mode' ),
				'search'         => $request->get_param( 'search' ),
				'usage_only'     => (bool) $request->get_param( 'usage_only' ),
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
	 * @return \WP_REST_Response|\WP_Error
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
				'refresh'   => $request->get_param( 'refresh' ),
			);

			// Cache busting is an admin concern. The base /patterns route
			// requires `edit_posts`, but rebuilding the reference-count cache
			// is expensive enough that we don't want any editor able to force
			// it on demand. Without this gate an authenticated user can loop
			// `refresh=true` to repeatedly trigger the full post_content scan.
			if ( ! empty( $args['refresh'] ) && ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error(
					'rest_forbidden_refresh',
					__( 'You do not have permission to refresh the pattern cache.', 'gk-block-mcp' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

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
	 * @return \WP_REST_Response|\WP_Error
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
					__( 'Pattern not found.', 'gk-block-mcp' ),
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
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_site_usage( $request ) {
		try {
			$refresh = (bool) $request->get_param( 'refresh' );
			$stats   = $this->block_inventory->get_stats( $refresh );

			return new \WP_REST_Response( $stats, 200 );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * POST /storage-modes/scan (BLOCK-13)
	 *
	 * Walks every published post, samples each distinct block name, and
	 * persists `block_name => storage_mode` to wp_options. Subsequent
	 * `get_page_blocks` annotations and BLOCK-14 dual-storage enforcement
	 * use the live classification instead of the filter defaults.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scan_storage_modes( $request ) {
		unset( $request );
		try {
			$result = $this->block_inventory->scan_storage_modes();
			return new \WP_REST_Response( $result, 200 );
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
					__( 'No post found for this URL.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			$post = get_post( $post_id );

			// Visibility gate matching post_info / find_posts. url_to_postid
			// resolves drafts and private posts too on logged-in front-ends,
			// so without this gate an Author could resolve another user's
			// draft URL and pull its title / status.
			if ( ! $post || ! \GravityKit\BlockMCP\Block_CRUD::is_post_readable( $post ) ) {
				return new \WP_Error(
					'not_found',
					__( 'No post found for this URL.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			return new \WP_REST_Response(
				array(
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
					'title'     => $post->post_title,
					'status'    => $post->post_status,
					'slug'      => $post->post_name,
					'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
				),
				200
			);
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /find-posts
	 *
	 * Cheap WP_Query-backed search. Returns a flat list of post stubs
	 * (id, title, slug, post_type, post_status, post_url, modified)
	 * — no block parsing, no full content. Use this before drilling
	 * into a specific post via /post-info or /posts/{id}/blocks.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function find_posts( $request ) {
		try {
			$search      = $request->get_param( 'search' );
			$post_type   = $request->get_param( 'post_type' );
			$post_status = $request->get_param( 'post_status' );
			$per_page    = (int) $request->get_param( 'per_page' );
			$page        = (int) $request->get_param( 'page' );

			$per_page = max( 1, min( 100, $per_page ? $per_page : 20 ) );
			$page     = max( 1, $page ? $page : 1 );

			$pt_param = $post_type
				? array_filter( array_map( 'trim', explode( ',', $post_type ) ) )
				: get_post_types( array( 'public' => true ), 'names' );

			// `any` is exclusive — combining it with explicit statuses (e.g.,
			// "publish,any") used to silently drop the explicit ones. Treat
			// `any` as "any" only when it's the sole value.
			$ps_param = $post_status
				? array_filter( array_map( 'trim', explode( ',', $post_status ) ) )
				: array( 'publish' );
			if ( count( $ps_param ) === 1 && reset( $ps_param ) === 'any' ) {
				$ps_param = 'any';
			} else {
				$ps_param = array_values( array_diff( $ps_param, array( 'any' ) ) );
				if ( empty( $ps_param ) ) {
					$ps_param = array( 'publish' );
				}
			}

			$args = array(
				'post_type'           => array_values( $pt_param ),
				'post_status'         => $ps_param,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'suppress_filters'    => false,
				// `perm: readable` pushes the user-cap filter into WP_Query's
				// SQL via posts_where_paged (built-in WP behaviour). Without
				// this, requests for post_status=draft / private / pending
				// would return every matching post regardless of caller —
				// Author-level users could see each other's drafts. Filtering
				// in the query (not the result loop) also keeps found_posts
				// and pagination counts honest.
				//
				// Note: WP's `perm` check is status + ownership only — it does
				// NOT consider post_password. The post-result loop below
				// applies Block_CRUD::is_post_readable() to catch the
				// password-protected case.
				'perm'                => 'readable',
			);
			if ( ! empty( $search ) ) {
				$args['s'] = $search;
			}

			$query = new \WP_Query( $args );
			$out   = array();
			foreach ( $query->posts as $p ) {
				// Post-filter for password-protected (perm:'readable' misses it).
				if ( ! \GravityKit\BlockMCP\Block_CRUD::is_post_readable( $p ) ) {
					continue;
				}
				$out[] = array(
					'post_id'     => (int) $p->ID,
					'title'       => $p->post_title,
					'slug'        => $p->post_name,
					'post_type'   => $p->post_type,
					'post_status' => $p->post_status,
					'post_url'    => get_permalink( $p ),
					'modified'    => $p->post_modified_gmt . 'Z',
				);
			}

			// $query->found_posts reflects the SQL `perm:'readable'` filter but
			// NOT the post-loop password gate above — so reporting it as `total`
			// can leak the existence of password-protected publish posts the
			// caller cannot see. Derive both `total` and `total_pages` from the
			// visible `$out` instead so the metadata mirrors what the caller
			// actually receives.
			$visible_total = count( $out );
			$total_pages   = $per_page > 0 ? (int) ceil( $visible_total / max( 1, (int) $per_page ) ) : 0;

			return new \WP_REST_Response(
				array(
					'posts'       => $out,
					'count'       => $visible_total,
					'total'       => $visible_total,
					'total_pages' => $total_pages,
					'page'        => (int) $page,
					'per_page'    => (int) $per_page,
				),
				200
			);
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * GET /post-info
	 *
	 * Single-post metadata lookup. Resolves a post via post_id, url,
	 * or (slug + post_type) — whichever is supplied first wins.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_info( $request ) {
		try {
			$post_id       = (int) $request->get_param( 'post_id' );
			$url           = $request->get_param( 'url' );
			$slug          = $request->get_param( 'slug' );
			$post_type_raw = $request->get_param( 'post_type' );
			$post_type     = $post_type_raw ? $post_type_raw : 'any';

			$post = null;
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
			} elseif ( ! empty( $url ) ) {
				$path     = false !== strpos( $url, '://' ) ? wp_parse_url( $url, PHP_URL_PATH ) : $url;
				$resolved = url_to_postid( home_url( $path ) );
				if ( $resolved ) {
					$post = get_post( $resolved );
				}
			} elseif ( ! empty( $slug ) ) {
				$found = get_posts(
					array(
						'name'           => $slug,
						'post_type'      => $post_type,
						'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
						'posts_per_page' => 1,
						'no_found_rows'  => true,
					)
				);
				if ( ! empty( $found ) ) {
					$post = $found[0];
				}
			} else {
				return new \WP_Error(
					'missing_lookup',
					__( 'Provide one of: post_id, url, or slug.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $post ) {
				return new \WP_Error(
					'not_found',
					__( 'No post matched the lookup.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			// Visibility gate. post_info hands back title, author, parent,
			// timestamps, and status for whatever post resolves — direct id
			// lookup did NO cap check at all, and slug-based lookup includes
			// draft / private / pending statuses unconditionally. Force the
			// caller through the standard read-post gate so Author-level
			// users can't read each other's drafts.
			if ( ! \GravityKit\BlockMCP\Block_CRUD::is_post_readable( $post ) ) {
				return new \WP_Error(
					'not_found',
					__( 'No post matched the lookup.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			$author = get_userdata( (int) $post->post_author );

			return new \WP_REST_Response(
				array(
					'post_id'       => (int) $post->ID,
					'title'         => $post->post_title,
					'slug'          => $post->post_name,
					'post_type'     => $post->post_type,
					'post_status'   => $post->post_status,
					'post_url'      => get_permalink( $post ),
					'edit_url'      => get_edit_post_link( $post->ID, 'raw' ),
					'modified'      => $post->post_modified_gmt . 'Z',
					'created'       => $post->post_date_gmt . 'Z',
					'parent_id'     => (int) $post->post_parent,
					'author'        => array(
						'id'           => (int) $post->post_author,
						'display_name' => $author ? $author->display_name : '',
					),
					'mime_type'     => $post->post_mime_type,
					'comment_count' => (int) $post->comment_count,
				),
				200
			);
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

			$if_match = $this->check_if_match_for_post( $post_id, $request );
			if ( is_wp_error( $if_match ) ) {
				return $if_match;
			}

			$render       = (bool) $request->get_param( 'render' );
			$persist_refs = null === $request->get_param( 'persist_refs' ) ? true : (bool) $request->get_param( 'persist_refs' );
			$blocks       = $this->block_crud->get_blocks( $post_id, $render, $persist_refs );

			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}

			// Build summary BEFORE search/filter so it reflects the whole page.
			$include_legacy_paths = (bool) $request->get_param( 'include_legacy_paths' );
			$summary              = $this->build_blocks_summary( $blocks, $include_legacy_paths );

			// Search/filter runs FIRST on full data (needs innerHTML to search).
			$search     = $request->get_param( 'search' );
			$block_name = $request->get_param( 'block_name' );
			$is_search  = ! empty( $search ) || ! empty( $block_name );

			if ( $is_search ) {
				$blocks = $this->search_blocks( $blocks, $search ? $search : '', $block_name ? $block_name : '' );
			}

			// Outline mode: flatten to just headings with section names.
			$outline_mode = (bool) $request->get_param( 'outline' );
			if ( $outline_mode ) {
				$blocks = $this->extract_outline( $blocks );
			}

			// Summary-only mode: skip the blocks payload entirely.
			$summary_only = (bool) $request->get_param( 'summary_only' );
			if ( $summary_only ) {
				return new \WP_REST_Response( array( 'summary' => $summary ), 200 );
			}

			// Fields filter runs AFTER search to strip unneeded data.
			$fields = $request->get_param( 'fields' );
			if ( ! empty( $fields ) ) {
				$allowed = array_map( 'trim', explode( ',', $fields ) );
				$blocks  = $this->filter_block_fields( $blocks, $allowed );
			}

			// Cursor-based pagination. Opt-in: kicks in only when the caller
			// passes `cursor` or `limit`. Walks top-level blocks and
			// preserves each block's full nested innerBlocks tree, so
			// edit-precision semantics aren't broken across pages.
			$cursor_param = $request->get_param( 'cursor' );
			$limit_param  = $request->get_param( 'limit' );
			$paginated    = null !== $cursor_param || null !== $limit_param;

			$pagination_meta = null;
			if ( $paginated ) {
				$total  = count( $blocks );
				$offset = $this->decode_blocks_cursor( $cursor_param );
				if ( is_wp_error( $offset ) ) {
					return $offset;
				}
				$limit = $this->normalize_blocks_limit( $limit_param );
				if ( is_wp_error( $limit ) ) {
					return $limit;
				}

				$blocks = array_slice( $blocks, $offset, $limit );

				$next_offset     = $offset + $limit;
				$pagination_meta = array(
					'limit'       => $limit,
					'offset'      => $offset,
					'total'       => $total,
					'next_cursor' => $next_offset < $total ? 'idx_' . $next_offset : null,
				);
			}

			$response = array(
				'summary' => $summary,
				'blocks'  => $blocks,
			);
			if ( $is_search ) {
				$response['match_count'] = count( $blocks );
			}
			if ( null !== $pagination_meta ) {
				$response['pagination'] = $pagination_meta;
			}

			// Surface the current revision as a weak ETag so callers can do
			// optimistic concurrency control on follow-up writes via the
			// `If-Match` header (or `expected_revision` body field for
			// transports that can't set headers).
			$current_revision        = $this->block_crud->get_latest_revision_id( $post_id );
			$response['revision_id'] = $current_revision;
			$rest_response           = new \WP_REST_Response( $response, 200 );
			$rest_response->header( 'ETag', sprintf( 'W/"%d"', $current_revision ) );
			return $rest_response;
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * Decode the `cursor` query param for top-level-block pagination.
	 *
	 * Format: `idx_<int>`. Bare integer-as-string is also accepted for
	 * convenience. Anything else returns a WP_Error so we never silently
	 * paginate from offset 0 on a malformed cursor.
	 *
	 * @param string|null $cursor Raw cursor param (or null = start).
	 *
	 * @return int|\WP_Error Non-negative offset, or WP_Error 400.
	 */
	private function decode_blocks_cursor( $cursor ) {
		if ( null === $cursor || '' === $cursor ) {
			return 0;
		}
		if ( ! is_string( $cursor ) && ! is_int( $cursor ) ) {
			return new \WP_Error( 'invalid_cursor', __( 'cursor must be a string or integer.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$cursor = (string) $cursor;

		// Tolerate the prefix-stripped form for callers passing through their
		// own bookkeeping. The canonical form is `idx_<n>`.
		if ( 0 === strpos( $cursor, 'idx_' ) ) {
			$cursor = substr( $cursor, 4 );
		}

		if ( ! preg_match( '/^[0-9]+$/', $cursor ) ) {
			return new \WP_Error( 'invalid_cursor', __( 'cursor must be of the form "idx_<n>".', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		return (int) $cursor;
	}

	/**
	 * Normalize the `limit` query param for top-level-block pagination.
	 *
	 * Default 25, hard cap 100. Out-of-range values produce a WP_Error
	 * rather than silently clamping, so callers learn they overshot.
	 *
	 * @param mixed $limit Raw limit param (or null = default).
	 *
	 * @return int|\WP_Error Effective limit, or WP_Error 400.
	 */
	private function normalize_blocks_limit( $limit ) {
		if ( null === $limit || '' === $limit ) {
			return 25;
		}
		if ( ! is_numeric( $limit ) ) {
			return new \WP_Error( 'invalid_limit', __( 'limit must be a positive integer.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$n = (int) $limit;
		if ( $n < 1 ) {
			return new \WP_Error( 'invalid_limit', __( 'limit must be >= 1.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( $n > 100 ) {
			return new \WP_Error( 'invalid_limit', __( 'limit must be <= 100.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		return $n;
	}

	/**
	 * Build a compact summary of a block tree.
	 *
	 * Provides AI agents a quick overview: total blocks, block type counts,
	 * named sections with their paths, and warning counts. Avoids the need
	 * to walk the full tree manually for basic page inspection.
	 *
	 * @param array $blocks               Formatted blocks (from get_blocks).
	 * @param bool  $include_legacy_paths Whether to include per-block paths in legacy block data.
	 *
	 * @return array Summary data.
	 */
	private function build_blocks_summary( $blocks, $include_legacy_paths = false ) {
		$summary = array(
			'total_blocks'     => 0,
			'top_level_blocks' => count( $blocks ),
			'block_types'      => array(),
			'sections'         => array(),
			'headings'         => array(),
			// `legacy_blocks` is dropped entirely when total === 0.
			// Pass `include_legacy_paths=true` to also surface per-block paths.
			'legacy_blocks'    => array(
				'total'         => 0,
				'by_namespace'  => array(),
				'by_block_name' => array(),
			),
			'max_path_depth'   => 0,
		);

		if ( $include_legacy_paths ) {
			$summary['legacy_blocks']['paths'] = array();
		}

		$this->walk_blocks_for_summary( $blocks, $summary, 0, $include_legacy_paths );

		// Sort block_types by count descending.
		arsort( $summary['block_types'] );
		$summary['block_types'] = array_map( 'intval', $summary['block_types'] );

		// Drop the `legacy_blocks` key entirely on clean pages — no point
		// padding every response with an empty stub.
		if ( 0 === $summary['legacy_blocks']['total'] && ! $include_legacy_paths ) {
			unset( $summary['legacy_blocks'] );
		} else {
			arsort( $summary['legacy_blocks']['by_block_name'] );
			$summary['legacy_blocks']['by_block_name'] = array_map( 'intval', $summary['legacy_blocks']['by_block_name'] );
			arsort( $summary['legacy_blocks']['by_namespace'] );
			$summary['legacy_blocks']['by_namespace'] = array_map( 'intval', $summary['legacy_blocks']['by_namespace'] );
		}

		return $summary;
	}

	/**
	 * Recursive walker for build_blocks_summary.
	 *
	 * @param array $blocks               Blocks to walk.
	 * @param array $summary              Summary accumulator (by reference).
	 * @param int   $depth                Current depth for max_path_depth tracking.
	 * @param bool  $include_legacy_paths Whether to collect per-block paths for legacy blocks.
	 */
	private function walk_blocks_for_summary( $blocks, &$summary, $depth, $include_legacy_paths = false ) {
		foreach ( $blocks as $block ) {
			++$summary['total_blocks'];
			$summary['max_path_depth'] = max( $summary['max_path_depth'], $depth );

			$name = isset( $block['name'] ) ? $block['name'] : '';
			if ( $name ) {
				if ( ! isset( $summary['block_types'][ $name ] ) ) {
					$summary['block_types'][ $name ] = 0;
				}
				++$summary['block_types'][ $name ];
			}

			// Track sections (group blocks with metadata.name).
			if ( ! empty( $block['section'] ) ) {
				$summary['sections'][] = array(
					'name' => $block['section'],
					'path' => isset( $block['path'] ) ? $block['path'] : array(),
				);
			}

			// Track headings.
			if ( 'core/heading' === $name ) {
				$level                 = isset( $block['attributes']['level'] ) ? (int) $block['attributes']['level'] : 2;
				$text                  = isset( $block['text_preview'] ) ? $block['text_preview'] : '';
				$summary['headings'][] = array(
					'path'  => isset( $block['path'] ) ? $block['path'] : array(),
					'level' => $level,
					'text'  => $text,
				);
			}

			// Track legacy blocks (aggregate counts by default; full
			// path list only when `include_legacy_paths` is requested).
			// Tier classification comes from the Preferences config (option-
			// backed, admin-editable, filter-extensible) — there are no
			// hardcoded namespace lists in this scanner.
			if ( $name && $this->preferences ) {
				$tier = $this->preferences->get_block_score( $name );
				if ( isset( $tier['tier'] ) && in_array( $tier['tier'], array( 'avoid', 'legacy' ), true ) ) {
					$namespace = explode( '/', $name )[0];
					++$summary['legacy_blocks']['total'];
					if ( ! isset( $summary['legacy_blocks']['by_namespace'][ $namespace ] ) ) {
						$summary['legacy_blocks']['by_namespace'][ $namespace ] = 0;
					}
					++$summary['legacy_blocks']['by_namespace'][ $namespace ];
					if ( ! isset( $summary['legacy_blocks']['by_block_name'][ $name ] ) ) {
						$summary['legacy_blocks']['by_block_name'][ $name ] = 0;
					}
					++$summary['legacy_blocks']['by_block_name'][ $name ];
					if ( $include_legacy_paths ) {
						$summary['legacy_blocks']['paths'][] = array(
							'name' => $name,
							'path' => isset( $block['path'] ) ? $block['path'] : array(),
						);
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks_for_summary( $block['innerBlocks'], $summary, $depth + 1, $include_legacy_paths );
			}
		}
	}

	/**
	 * Extract an outline (flat list of headings and sections) from a block tree.
	 *
	 * @param array $blocks Formatted blocks.
	 *
	 * @return array Flat list of heading/section entries with path, level, text, section_name.
	 */
	private function extract_outline( $blocks ) {
		$outline = array();
		$this->walk_blocks_for_outline( $blocks, $outline );
		return $outline;
	}

	/**
	 * Recursive walker for extract_outline.
	 *
	 * @param array $blocks  Formatted blocks to walk.
	 * @param array $outline Outline accumulator (by reference).
	 */
	private function walk_blocks_for_outline( $blocks, &$outline ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['name'] ) ? $block['name'] : '';
			$path = isset( $block['path'] ) ? $block['path'] : array();

			// Section marker (any block with metadata.name).
			if ( ! empty( $block['section'] ) ) {
				$outline[] = array(
					'type'         => 'section',
					'path'         => $path,
					'section_name' => $block['section'],
					'block_name'   => $name,
				);
			}

			// Heading.
			if ( 'core/heading' === $name ) {
				$level     = isset( $block['attributes']['level'] ) ? (int) $block['attributes']['level'] : 2;
				$outline[] = array(
					'type'  => 'heading',
					'path'  => $path,
					'level' => $level,
					'text'  => isset( $block['text_preview'] ) ? $block['text_preview'] : '',
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks_for_outline( $block['innerBlocks'], $outline );
			}
		}
	}

	/**
	 * PATCH /posts/{id}/blocks/by-ref/{ref}
	 *
	 * Ref-based update. Resolves the ref to a flat index, then calls the
	 * existing update_block path. Returns ref_stale (404) if not found.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_block_by_ref( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$ref   = (string) $req->get_param( 'ref' );
				$index = $this->block_crud->resolve_ref_to_index( $post_id, $ref );
				if ( is_wp_error( $index ) ) {
					return $index;
				}

				$attributes = $req->get_param( 'attributes' );
				$inner_html = $req->get_param( 'innerHTML' );

				if ( null === $attributes && null === $inner_html ) {
					return new \WP_Error(
						'missing_data',
						__( 'At least one of "attributes" or "innerHTML" must be provided.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				$allow_bound_writes = filter_var(
					$req->get_param( 'allow_bound_writes' ),
					FILTER_VALIDATE_BOOLEAN
				);

				return $this->block_crud->update_block(
					$post_id,
					$index,
					is_array( $attributes ) ? $attributes : array(),
					$inner_html,
					array( 'allow_bound_writes' => $allow_bound_writes )
				);
			}
		);
	}

	/**
	 * DELETE /posts/{id}/blocks/by-ref/{ref}
	 *
	 * Ref-based delete. Resolves the ref to a flat index, then calls the
	 * existing delete_blocks path.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_block_by_ref( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$ref   = (string) $req->get_param( 'ref' );
				$index = $this->block_crud->resolve_ref_to_index( $post_id, $ref );
				if ( is_wp_error( $index ) ) {
					return $index;
				}
				$count = (int) $req->get_param( 'count' );
				return $this->block_crud->delete_blocks( $post_id, $index, $count );
			}
		);
	}

	/**
	 * PATCH /posts/{id}/blocks/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_block( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$index      = (int) $req->get_param( 'index' );
				$attributes = $req->get_param( 'attributes' );
				$inner_html = $req->get_param( 'innerHTML' );

				if ( null === $attributes && null === $inner_html ) {
					return new \WP_Error(
						'missing_data',
						__( 'At least one of "attributes" or "innerHTML" must be provided.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				// Forward the Block Bindings override flag so the REST surface
				// actually exposes the feature added in the bindings task —
				// without this, the underlying $options['allow_bound_writes']
				// path is dead code reachable only from internal PHP callers.
				$allow_bound_writes = filter_var(
					$req->get_param( 'allow_bound_writes' ),
					FILTER_VALIDATE_BOOLEAN
				);

				return $this->block_crud->update_block(
					$post_id,
					$index,
					is_array( $attributes ) ? $attributes : array(),
					$inner_html,
					array( 'allow_bound_writes' => $allow_bound_writes )
				);
			}
		);
	}

	/**
	 * POST /posts/{id}/blocks/batch-update
	 *
	 * Apply N independent block updates in a single revision. Validation is
	 * all-or-nothing: any item-level failure aborts the batch with an
	 * itemized `errors` payload.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_blocks_batch( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$updates = $req->get_param( 'updates' );
				if ( ! is_array( $updates ) ) {
					return new \WP_Error(
						'invalid_updates',
						__( '"updates" must be an array.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}
				$verbose = (bool) $req->get_param( 'verbose' );
				return $this->block_crud->update_blocks_batch( $post_id, $updates, $verbose );
			}
		);
	}

	/**
	 * GET /posts/{id}/block?ref={ref}|flat_index={n}
	 *
	 * Single-block fetch. Returns the same `saved` snapshot shape that write
	 * endpoints echo, so verification reads use the identical contract as the
	 * writes that produced them.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_block( $request ) {
		try {
			$post_id = (int) $request->get_param( 'id' );
			$perm    = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm ) ) {
				return $perm;
			}

			$ref        = $request->get_param( 'ref' );
			$flat_index = $request->get_param( 'flat_index' );
			$ref        = ( is_string( $ref ) && '' !== $ref ) ? $ref : null;
			$flat_index = ( null !== $flat_index && '' !== $flat_index ) ? (int) $flat_index : null;

			$result = $this->block_crud->get_block( $post_id, $ref, $flat_index );

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
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$blocks = $req->get_param( 'blocks' );

				// Determine position. after_ref/before_ref take precedence over after/before
				// when both are supplied (the ref is the more stable identifier).
				$after_ref  = $req->get_param( 'after_ref' );
				$before_ref = $req->get_param( 'before_ref' );
				$position   = null;
				if ( is_string( $after_ref ) && '' !== $after_ref ) {
					$resolved = $this->block_crud->resolve_ref_to_top_level( $post_id, $after_ref );
					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}
					$position = $resolved;
				} elseif ( is_string( $before_ref ) && '' !== $before_ref ) {
					$resolved = $this->block_crud->resolve_ref_to_top_level( $post_id, $before_ref );
					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}
					$position = $resolved > 0 ? $resolved - 1 : 'start';
				} elseif ( null !== $req->get_param( 'after' ) ) {
					$position = $req->get_param( 'after' );
				} elseif ( null !== $req->get_param( 'before' ) ) {
					// "before" index N = "after" index N-1.
					$before   = (int) $req->get_param( 'before' );
					$position = $before > 0 ? $before - 1 : 'start';
				}

				if ( empty( $blocks ) || ! is_array( $blocks ) ) {
					return new \WP_Error(
						'missing_blocks',
						__( 'The "blocks" parameter is required and must be a non-empty array.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				$sanitized_blocks = array_map( array( $this, 'sanitize_block_def' ), $blocks );
				return $this->block_crud->insert_blocks( $post_id, $position, $sanitized_blocks );
			},
			201
		);
	}

	/**
	 * POST /posts/{id}/blocks/replace
	 *
	 * Atomically replace a range of top-level blocks with a new shape, in a
	 * single revision. Distinct from `replace_all_blocks` (which rewrites
	 * the entire post).
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replace_blocks_range( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$start  = (int) $req->get_param( 'start' );
				$count  = (int) $req->get_param( 'count' );
				$blocks = $req->get_param( 'blocks' );

				if ( ! is_array( $blocks ) ) {
					return new \WP_Error(
						'missing_blocks',
						__( 'The "blocks" parameter is required and must be an array (may be empty for a pure delete).', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				$sanitized_blocks = array_map( array( $this, 'sanitize_block_def' ), $blocks );
				return $this->block_crud->replace_blocks_range( $post_id, $start, $count, $sanitized_blocks );
			}
		);
	}

	/**
	 * DELETE /posts/{id}/blocks/{index}
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_block( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$index = (int) $req->get_param( 'index' );
				$count = (int) $req->get_param( 'count' );
				return $this->block_crud->delete_blocks( $post_id, $index, $count );
			}
		);
	}

	/**
	 * PUT /posts/{id}/blocks
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replace_all_blocks( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$blocks = $req->get_param( 'blocks' );

				if ( empty( $blocks ) || ! is_array( $blocks ) ) {
					return new \WP_Error(
						'missing_blocks',
						__( 'The "blocks" parameter is required and must be a non-empty array.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				// Sanitize block definitions recursively (preserves innerBlocks).
				$sanitized_blocks = array_map( array( $this, 'sanitize_block_def' ), $blocks );
				return $this->block_crud->replace_all_blocks( $post_id, $sanitized_blocks );
			}
		);
	}

	/**
	 * POST /posts/{id}/insert-pattern
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insert_pattern( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$pattern_id = $req->get_param( 'pattern_id' );
				$synced     = (bool) $req->get_param( 'synced' );

				// Determine position.
				$position = null;
				if ( null !== $req->get_param( 'after' ) ) {
					$position = $req->get_param( 'after' );
				} elseif ( null !== $req->get_param( 'before' ) ) {
					$before   = (int) $req->get_param( 'before' );
					$position = $before > 0 ? $before - 1 : 'start';
				}

				return $this->block_crud->insert_pattern( $post_id, $pattern_id, $position, $synced );
			},
			201
		);
	}

	/**
	 * POST /posts/{id}/mutate
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mutate_block_tree( $request ) {
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$op   = $req->get_param( 'op' );
				$path = $req->get_param( 'path' );
				$ref  = $req->get_param( 'ref' );

				// Resolve ref → path if no explicit path supplied.
				if ( ( null === $path || ( is_array( $path ) && empty( $path ) ) ) && is_string( $ref ) && '' !== $ref ) {
					$resolved = $this->block_crud->resolve_ref( $post_id, $ref );
					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}
					$path = $resolved;
				}

				if ( ! is_array( $path ) || empty( $path ) ) {
					return new \WP_Error(
						'missing_target',
						__( 'Either "path" or "ref" is required.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}

				// Cast path elements to integers.
				$path = array_map( 'intval', $path );

				$params = array(
					'attributes'  => $req->get_param( 'attributes' ),
					'innerHTML'   => $req->get_param( 'innerHTML' ),
					'block'       => $req->get_param( 'block' ),
					'wrapper'     => $req->get_param( 'wrapper' ),
					'position'    => $req->get_param( 'position' ),
					'destination' => $req->get_param( 'destination' ),
					'count'       => $req->get_param( 'count' ),
				);

				// Resolve destination_ref to a path for the move op.
				$dest_ref = $req->get_param( 'destination_ref' );
				if ( null === $params['destination'] && is_string( $dest_ref ) && '' !== $dest_ref ) {
					$resolved = $this->block_crud->resolve_ref( $post_id, $dest_ref );
					if ( is_wp_error( $resolved ) ) {
						return $resolved;
					}
					$params['destination'] = $resolved;
				}

				// Cast destination to integers if present.
				if ( is_array( $params['destination'] ) ) {
					$params['destination'] = array_map( 'intval', $params['destination'] );
				}

				$dry_run = (bool) $req->get_param( 'dry_run' );
				return $this->block_mutator->mutate( $post_id, $op, $path, $params, $dry_run );
			}
		);
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
		return $this->with_post_edit_context(
			$request,
			function ( $post_id, $req ) {
				$revision_id = (int) $req->get_param( 'revision_id' );
				return $this->block_crud->revert_to_revision( $post_id, $revision_id );
			}
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Execute a post-scoped write operation with the standard preamble.
	 *
	 * Absorbs the boilerplate shared by every post-scoped write handler:
	 *
	 *   1. Wrap everything in try/catch → handle_error() on any \Throwable.
	 *   2. Extract post_id from $request['id'] (int cast).
	 *   3. check_post_edit_permission() — return WP_Error on failure.
	 *   4. check_if_match_for_post() — return WP_Error on stale ETag.
	 *   5. Invoke $operation( $post_id, $request ) and wrap the result.
	 *
	 * The $operation callable receives ($post_id, $request) and must return
	 * either the raw value to wrap in WP_REST_Response, or a WP_Error.
	 * Any \Throwable thrown by the callable is caught and converted to a
	 * 500 internal_error response via handle_error().
	 *
	 * @param \WP_REST_Request $request   Incoming request (used for param
	 *                                    extraction and If-Match check).
	 * @param callable         $operation Callable accepting (int $post_id,
	 *                                    \WP_REST_Request $request) that
	 *                                    returns the operation result or
	 *                                    \WP_Error.
	 * @param int              $status    HTTP status code for a successful
	 *                                    response. Default 200; use 201 for
	 *                                    resource-creation operations.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function with_post_edit_context( \WP_REST_Request $request, callable $operation, $status = 200 ) {
		try {
			$post_id    = (int) $request->get_param( 'id' );
			$perm_check = $this->check_post_edit_permission( $post_id );
			if ( is_wp_error( $perm_check ) ) {
				return $perm_check;
			}

			$if_match = $this->check_if_match_for_post( $post_id, $request );
			if ( is_wp_error( $if_match ) ) {
				return $if_match;
			}

			$result = $operation( $post_id, $request );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( $result, $status );
		} catch ( \Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * Optimistic-concurrency precondition check.
	 *
	 * Reads the `If-Match` header (or the optional body field
	 * `expected_revision` for transports that can't set headers) and
	 * delegates to Block_CRUD::check_if_match. No-op when neither is set,
	 * so existing callers see no behavior change.
	 *
	 * @param int              $post_id Post being written.
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return null|\WP_Error null = proceed; WP_Error 412 = stale revision.
	 */
	private function check_if_match_for_post( $post_id, $request ) {
		$expected = '';
		if ( $request && method_exists( $request, 'get_header' ) ) {
			$header = $request->get_header( 'if_match' );
			if ( is_string( $header ) && '' !== $header ) {
				$expected = $header;
			}
		}
		if ( '' === $expected ) {
			$body_field = $request ? $request->get_param( 'expected_revision' ) : null;
			if ( null !== $body_field && '' !== $body_field ) {
				$expected = (string) $body_field;
			}
		}
		if ( '' === $expected ) {
			return null;
		}
		return $this->block_crud->check_if_match( $post_id, $expected );
	}

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

		$post            = get_post( $post_id );
		$post_type_obj   = $post ? get_post_type_object( $post->post_type ) : null;
		$required_cap    = $post_type_obj ? $post_type_obj->cap->edit_post : 'edit_post';
		$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'post';

		return new \WP_Error(
			'rest_forbidden',
			sprintf(
				/* translators: 1: post type label, 2: required capability */
				__( 'You do not have permission to edit this %1$s. Required capability: %2$s.', 'gk-block-mcp' ),
				$post_type_label,
				$required_cap
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Recursively sanitizes a block definition from REST input.
	 * Preserves innerBlocks so nested structures survive sanitization.
	 *
	 * @param array $block_def Raw block definition from the request.
	 * @return array Sanitized block definition.
	 */
	private function sanitize_block_def( array $block_def ): array {
		$sanitized = array(
			'name'       => isset( $block_def['name'] ) ? sanitize_text_field( $block_def['name'] ) : '',
			'attributes' => isset( $block_def['attributes'] ) ? $block_def['attributes'] : array(),
			'innerHTML'  => isset( $block_def['innerHTML'] ) ? wp_kses_post( $block_def['innerHTML'] ) : '',
		);
		if ( ! empty( $block_def['innerBlocks'] ) && is_array( $block_def['innerBlocks'] ) ) {
			$sanitized['innerBlocks'] = array_map( array( $this, 'sanitize_block_def' ), $block_def['innerBlocks'] );
		}
		return $sanitized;
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
				$text = isset( $block['innerHTML'] ) ? wp_strip_all_tags( $block['innerHTML'] ) : '';
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
				$results       = array_merge( $results, $child_results );
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
				'sanitize_callback' => 'absint',
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
			'refresh'   => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Bust the cached pattern-reference counts before listing.',
			),
		);
	}
}
