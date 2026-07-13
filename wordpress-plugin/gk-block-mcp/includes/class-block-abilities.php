<?php
/**
 * WordPress 6.9 Abilities API bridge.
 *
 * Exposes Block MCP's block-tree operations as native WordPress abilities so the
 * official MCP Adapter — and any other Abilities consumer (REST, JS, WP-CLI) —
 * can discover and invoke them. This is a thin adapter: every ability delegates
 * to the same service classes the REST layer uses, so behavior is identical
 * whichever front door a caller uses. Registration is feature-detected on the
 * Abilities API (WordPress 6.9+) and is a no-op on older cores.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Block MCP abilities with the WordPress Abilities API.
 */
class Block_Abilities {

	/**
	 * Ability namespace prefix (per-product, matching the text domain).
	 */
	const NAMESPACE_PREFIX = 'gk-block-mcp/';

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'gk-block-mcp';

	/**
	 * Option key for the "expose operations as WordPress Abilities" toggle.
	 *
	 * Stored as the string '0'/'1'; defaults to enabled (opt-out) when unset.
	 */
	const ENABLED_OPTION = 'gk_block_api_abilities_enabled';

	/**
	 * Block CRUD service.
	 *
	 * @var Block_CRUD
	 */
	private $crud;

	/**
	 * Post manager service.
	 *
	 * @var Post_Manager
	 */
	private $post_manager;

	/**
	 * Block registry service.
	 *
	 * @var Block_Registry
	 */
	private $registry;

	/**
	 * REST controller service graph.
	 *
	 * @var REST_Controller
	 */
	private $controller;

	/**
	 * Optional Yoast SEO bridge.
	 *
	 * @var Yoast_Bridge
	 */
	private $yoast_bridge;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD      $crud         Block CRUD service.
	 * @param Post_Manager    $post_manager Post manager service.
	 * @param Block_Registry  $registry     Block registry service.
	 * @param REST_Controller $controller   REST controller service graph.
	 * @param Yoast_Bridge    $yoast_bridge Optional Yoast SEO bridge.
	 */
	public function __construct(
		Block_CRUD $crud,
		Post_Manager $post_manager,
		Block_Registry $registry,
		REST_Controller $controller,
		Yoast_Bridge $yoast_bridge
	) {
		$this->crud         = $crud;
		$this->post_manager = $post_manager;
		$this->registry     = $registry;
		$this->controller   = $controller;
		$this->yoast_bridge = $yoast_bridge;
	}

	/**
	 * Whether the WordPress Abilities API is available on this site.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether registering Block MCP abilities is enabled for this site.
	 *
	 * Default on (opt-out): registration exposes the operations to the official
	 * MCP Adapter and the Abilities REST API — a capability-gated but
	 * network-reachable surface — so a site owner can turn it off. Stored as the
	 * `gk_block_api_abilities_enabled` option (Settings → Block MCP) and
	 * filterable via `gk/block-mcp/abilities/enabled` for programmatic control.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = '0' !== (string) get_option( self::ENABLED_OPTION, '1' );

		/**
		 * Filters whether Block MCP registers its operations as WordPress
		 * Abilities (exposing them to the official MCP Adapter and the Abilities
		 * REST API). Defaults to the Settings → Block MCP toggle.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Whether registration is enabled.
		 */
		return (bool) apply_filters( 'gk/block-mcp/abilities/enabled', $enabled );
	}

	/**
	 * Wire registration onto the Abilities init hooks.
	 *
	 * No-op when the Abilities API is absent (older cores) or when the site
	 * owner has disabled the toggle, so callers can invoke this unconditionally.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::is_available() || ! self::is_enabled() ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category. Idempotent.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Block MCP', 'gk-block-mcp' ),
				'description' => __( 'Block-tree reading and editing for AI agents — surgical, ref-stable, revision-backed.', 'gk-block-mcp' ),
			)
		);
	}

	/**
	 * Register all abilities. Idempotent — skips any already registered, so
	 * re-entrant or repeated firing of `wp_abilities_api_init` is safe.
	 *
	 * @return void
	 */
	public function register_abilities() {
		foreach ( $this->definitions() as $name => $args ) {
			if ( wp_has_ability( $name ) ) {
				continue;
			}
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * The fully-qualified names of every ability this bridge registers.
	 *
	 * @return string[]
	 */
	public function ability_names() {
		return array_keys( $this->definitions() );
	}

	/**
	 * Ability definitions keyed by namespaced name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function definitions() {
		$definitions = array(
			self::NAMESPACE_PREFIX . 'get-page-blocks'     => array(
				'label'               => __( 'Get page blocks', 'gk-block-mcp' ),
				'description'         => __( "Read a post's content as a structured block tree (paths, stable refs, attributes) instead of raw HTML.", 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID to read.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_get_page_blocks' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true ),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'update-block'        => array(
				'label'               => __( 'Update block', 'gk-block-mcp' ),
				'description'         => __( 'Update one block by its flat index, changing attributes and/or innerHTML without rewriting the page.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'gk-block-mcp' ),
						),
						'index'      => array(
							'type'        => 'integer',
							'description' => __( 'Flat index of the block to update.', 'gk-block-mcp' ),
						),
						'attributes' => array(
							'type'        => 'object',
							'description' => __( 'Block attributes to merge.', 'gk-block-mcp' ),
						),
						'inner_html' => array(
							'type'        => 'string',
							'description' => __( 'Replacement innerHTML for the block.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'index' ),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_update_block' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'insert-blocks'       => array(
				'label'               => __( 'Insert blocks', 'gk-block-mcp' ),
				'description'         => __( 'Insert one or more blocks at a position in the page (append when no position is given).', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'gk-block-mcp' ),
						),
						'position' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Zero-based insert position; null appends.', 'gk-block-mcp' ),
						),
						'blocks'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => __( 'Block definitions to insert (name, attributes, innerHTML).', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'blocks' ),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_insert_blocks' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'create-post'         => array(
				'label'               => __( 'Create post', 'gk-block-mcp' ),
				'description'         => __( 'Create a post or page from a block tree (or HTML). Drafts by default.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'Post title.', 'gk-block-mcp' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'Post status (draft, pending, private, publish, future).', 'gk-block-mcp' ),
						),
						'blocks'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => __( 'Block definitions for the new post content.', 'gk-block-mcp' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'Raw block markup (mutually exclusive with blocks).', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'title' ),
				),
				'permission_callback' => array( $this, 'can_create' ),
				'execute_callback'    => array( $this, 'execute_create_post' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'list-block-types'    => array(
				'label'               => __( 'List block types', 'gk-block-mcp' ),
				'description'         => __( 'List the block types registered on this site, with preference tiers — so an agent uses only blocks the site allows.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => __( 'Optional namespace filter (e.g. "core").', 'gk-block-mcp' ),
						),
					),
					'default'    => array(),
				),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_list_block_types' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true ),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'list-patterns'       => array(
				'label'               => __( 'List patterns', 'gk-block-mcp' ),
				'description'         => __( 'Block patterns sorted by preference score. Check before building from scratch. Server respects `limit`; `offset` slices client-side. Reference counts are cached for 1 hour — pass `refresh:true` to rebuild.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Search by name or keyword.', 'gk-block-mcp' ),
						),
						'synced'    => array(
							'type'        => 'boolean',
							'description' => __( 'true = synced only, false = registered only, omit = all.', 'gk-block-mcp' ),
						),
						'min_score' => array(
							'type'        => 'number',
							'description' => __( 'Min preference score; 0 excludes legacy.', 'gk-block-mcp' ),
						),
						'limit'     => array(
							'type'        => 'number',
							'description' => __( 'Max results. Default 20.', 'gk-block-mcp' ),
						),
						'offset'    => array(
							'type'        => 'number',
							'description' => __( 'Skip this many results. Default 0.', 'gk-block-mcp' ),
						),
						'refresh'   => array(
							'type'        => 'boolean',
							'description' => __( 'Bust the 1-hour reference-count cache before listing.', 'gk-block-mcp' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_list_patterns' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'get-pattern'         => array(
				'label'               => __( 'Get pattern', 'gk-block-mcp' ),
				'description'         => __( "Single pattern's full block content + metadata. Use after list_patterns.", 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'pattern_id' => array(
							'type'        => array( 'number', 'string' ),
							'description' => __( 'Numeric post ID (synced) or registered pattern name.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'pattern_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get_pattern' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'get-site-usage'      => array(
				'label'               => __( 'Get site usage', 'gk-block-mcp' ),
				'description'         => __( 'Site-wide block + pattern inventory: usage counts, namespace totals, pattern reference counts, legacy patterns.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'refresh' => array(
							'type'        => 'boolean',
							'description' => __( 'Bust the 1-hour cache and rebuild.', 'gk-block-mcp' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get_site_usage' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'scan-storage-modes'  => array(
				'label'               => __( 'Scan storage modes', 'gk-block-mcp' ),
				'description'         => __( 'Walk every published post and persist a `block_name → "static"|"dynamic"|"dual"` map. Slow on large sites; rate-limited to 1/hr. Returns the scan counts and classification map.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'execute_callback'    => array( $this, 'execute_scan_storage_modes' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'resolve-url'         => array(
				'label'               => __( 'Resolve URL to post', 'gk-block-mcp' ),
				'description'         => __( 'URL or path → post ID. Accepts full URLs or site-relative paths. Run this before block reads or writes when you only have a URL.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => __( 'Full URL or path (e.g. "/some/page/").', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_resolve_url' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'list-posts'          => array(
				'label'               => __( 'List/search posts', 'gk-block-mcp' ),
				'description'         => __( 'Search posts by title/content with pagination. Returns lightweight post metadata for drilling into a specific post.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'      => array(
							'type'        => 'string',
							'description' => __( 'Free-text across title + content. Omit to list.', 'gk-block-mcp' ),
						),
						'post_type'   => array(
							'type'        => 'string',
							'description' => __( 'Single or comma-separated. Default: public types.', 'gk-block-mcp' ),
						),
						'post_status' => array(
							'type'        => 'string',
							'description' => __( 'publish | draft | private | any | csv. Default: publish. (`any` is exclusive.)', 'gk-block-mcp' ),
						),
						'per_page'    => array(
							'type'        => 'number',
							'description' => __( 'Default 20, max 100.', 'gk-block-mcp' ),
						),
						'page'        => array(
							'type'        => 'number',
							'description' => __( 'Default 1.', 'gk-block-mcp' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_list_posts' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'get-post-info'       => array(
				'label'               => __( 'Get post info', 'gk-block-mcp' ),
				'description'         => __( 'Get post metadata by post_id, URL, or slug plus post type.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'number',
							'description' => __( 'One of post_id, url, or slug.', 'gk-block-mcp' ),
						),
						'url'       => array(
							'type'        => 'string',
							'description' => __( 'Full URL or path. Resolved via url_to_postid.', 'gk-block-mcp' ),
						),
						'slug'      => array(
							'type'        => 'string',
							'description' => __( 'post_name. Combine with post_type for uniqueness.', 'gk-block-mcp' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Scope a slug lookup. Default: any.', 'gk-block-mcp' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get_post_info' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'get-block'           => array(
				'label'               => __( 'Get one block', 'gk-block-mcp' ),
				'description'         => __( 'Fetch one block by stable ref OR flat_index and return the canonical saved snapshot from the database.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'ref'        => array(
							'type'        => 'string',
							'description' => __( 'Stable gk_ref. Provide this OR flat_index.', 'gk-block-mcp' ),
						),
						'flat_index' => array(
							'type'        => 'number',
							'description' => __( 'Flat block index. Provide this OR ref.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'saved'   => $this->saved_snapshot_schema(),
					),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_get_block' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'delete-block'        => array(
				'label'               => __( 'Delete block', 'gk-block-mcp' ),
				'description'         => __( 'Remove one or more top-level blocks starting at a flat index.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'gk-block-mcp' ),
						),
						'index'   => array(
							'type'        => 'integer',
							'description' => __( 'Flat index of the first block to remove.', 'gk-block-mcp' ),
						),
						'count'   => array(
							'type'        => 'integer',
							'description' => __( 'How many consecutive blocks to remove. Default 1.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'index' ),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_delete_block' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'update-blocks'       => array(
				'label'               => __( 'Batch-update blocks', 'gk-block-mcp' ),
				'description'         => __( 'Update multiple independent blocks atomically in one revision. Validation is all-or-nothing and the batch is limited to 50 items.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'updates' => array(
							'type'        => 'array',
							'description' => __( 'List of update items (1..50). Each item targets one block; same item shape as update_block.', 'gk-block-mcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'ref'        => array(
										'type'        => 'string',
										'description' => __( 'Stable gk_ref. Provide this OR flat_index.', 'gk-block-mcp' ),
									),
									'flat_index' => array(
										'type'        => 'number',
										'description' => __( 'Flat index from get_page_blocks. Provide this OR ref.', 'gk-block-mcp' ),
									),
									'block_name' => array(
										'type'        => 'string',
										'description' => __( 'Block type. Required for enrichers when attributes are provided.', 'gk-block-mcp' ),
									),
									'attributes' => array(
										'type'        => 'object',
										'description' => __( 'Partial attributes (top-level shallow merge).', 'gk-block-mcp' ),
									),
									'innerHTML'  => array(
										'type'        => 'string',
										'description' => __( 'Replacement innerHTML.', 'gk-block-mcp' ),
									),
								),
							),
						),
						'verbose' => array(
							'type'        => 'boolean',
							'description' => __( 'Include the canonical saved snapshot for each result. Default false.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'updates' ),
				),
				'output_schema'       => $this->update_blocks_output_schema(),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_update_blocks' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'replace-block-range' => array(
				'label'               => __( 'Replace a range of blocks', 'gk-block-mcp' ),
				'description'         => __( 'Atomically swap a range of top-level blocks for a new set in one revision.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'start'   => array(
							'type'        => 'number',
							'description' => __( 'top_level_counter of first block to replace.', 'gk-block-mcp' ),
						),
						'count'   => array(
							'type'        => 'number',
							'description' => __( 'How many top-level blocks to remove. Pass 0 to insert without removing.', 'gk-block-mcp' ),
						),
						'blocks'  => array(
							'type'        => 'array',
							'description' => __( 'Replacement blocks. May be empty for a pure delete.', 'gk-block-mcp' ),
							'items'       => $this->block_definition_schema(),
						),
					),
					'required'   => array( 'post_id', 'start', 'count', 'blocks' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'inserted'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'top_level_counter' => array( 'type' => 'number' ),
									'path'              => array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
									'ref'               => array( 'type' => 'string' ),
									'name'              => array( 'type' => 'string' ),
								),
							),
						),
						'warnings'           => array( 'type' => 'array' ),
						'before_revision_id' => array( 'type' => 'number' ),
						'revision_id'        => array( 'type' => 'number' ),
						'removed'            => array( 'type' => 'number' ),
					),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_replace_block_range' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'rewrite-post-blocks' => array(
				'label'               => __( 'Rewrite the entire post', 'gk-block-mcp' ),
				'description'         => __( 'Replace all blocks on a post in one revision. Prefer surgical block operations for smaller edits.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'blocks'  => array(
							'type'        => 'array',
							'description' => __( 'Complete blocks array that replaces all current blocks.', 'gk-block-mcp' ),
							'items'       => $this->block_definition_schema(),
						),
					),
					'required'   => array( 'post_id', 'blocks' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'            => array( 'type' => 'boolean' ),
						'blocks'             => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'index'      => array( 'type' => 'number' ),
									'name'       => array( 'type' => 'string' ),
									'attributes' => array( 'type' => 'object' ),
								),
							),
						),
						'warnings'           => array( 'type' => 'array' ),
						'before_revision_id' => array( 'type' => 'number' ),
						'revision_id'        => array( 'type' => 'number' ),
					),
				),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_rewrite_post_blocks' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'revert-to-revision'  => array(
				'label'               => __( 'Revert post to revision', 'gk-block-mcp' ),
				'description'         => __( 'Restore a post to a revision. Use before_revision_id from a prior write to undo that write.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'revision_id' => array(
							'type'        => 'number',
							'description' => __( 'Revision ID to restore.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'revision_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_revert_to_revision' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'insert-pattern'      => array(
				'label'               => __( 'Insert pattern', 'gk-block-mcp' ),
				'description'         => __( 'Insert a pattern as a synced reference by default, or inline its blocks for independent editing.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array(
							'type'        => 'number',
							'description' => __( 'Post ID.', 'gk-block-mcp' ),
						),
						'pattern_id'       => array(
							'type'        => array( 'number', 'string' ),
							'description' => __( 'Numeric post ID (synced) or registered pattern name.', 'gk-block-mcp' ),
						),
						'after_top_level'  => array(
							'type'        => 'number',
							'description' => __( 'top_level_counter to insert after. Omit to append.', 'gk-block-mcp' ),
						),
						'before_top_level' => array(
							'type'        => 'number',
							'description' => __( 'top_level_counter to insert before.', 'gk-block-mcp' ),
						),
						'synced'           => array(
							'type'        => 'boolean',
							'description' => __( 'true (default) = synced reference; false = inline copy.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id', 'pattern_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_insert_pattern' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'edit-block-tree'     => array(
				'label'               => __( 'Edit block tree', 'gk-block-mcp' ),
				'description'         => __( 'Run one structural operation on a nested block tree, targeting a block by stable ref or integer path.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->edit_block_tree_input_schema(),
				'output_schema'       => $this->edit_block_tree_output_schema(),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_edit_block_tree' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'update-post'         => array(
				'label'               => __( 'Update post', 'gk-block-mcp' ),
				'description'         => __( 'Partially update post metadata, status, or terms. Block content edits stay on the block-specific abilities.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->update_post_input_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_update_post' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'list-terms'          => array(
				'label'               => __( 'List terms', 'gk-block-mcp' ),
				'description'         => __( 'List terms in a taxonomy so category and tag IDs can be discovered before creating or updating posts.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->list_terms_input_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_list_terms' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'upload-media'        => array(
				'label'               => __( 'Upload media', 'gk-block-mcp' ),
				'description'         => __( 'Upload an item to the WordPress media library from a public URL or base64 data.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->upload_media_input_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_upload_files' ),
				'execute_callback'    => array( $this, 'execute_upload_media' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'site-editor-context' => array(
				'label'               => __( 'Site editor context', 'gk-block-mcp' ),
				'description'         => __( "Get the site's design tokens (theme name plus the color, gradient, font-size, and spacing presets) so block markup references theme-aligned preset slugs (e.g. has-primary-color) rather than hard-coded values.", 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'    => 'object',
					'default' => array(),
				),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_site_editor_context' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true ),
					'show_in_rest' => true,
				),
			),
		);

		if ( Yoast_Bridge::is_yoast_active() ) {
			$definitions = array_merge( $definitions, $this->yoast_definitions() );
		}

		return $definitions;
	}

	/**
	 * Canonical schema for a structured block definition.
	 *
	 * @return array<string, mixed>
	 */
	private function block_definition_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Fully-qualified block name (e.g. "core/heading").', 'gk-block-mcp' ),
				),
				'attributes'  => array(
					'type'        => 'object',
					'description' => __( 'Block attributes.', 'gk-block-mcp' ),
				),
				'innerHTML'   => array(
					'type'        => 'string',
					'description' => __( 'Wrapper HTML for containers or leaf-block HTML. Required when attributes include source-bound fields.', 'gk-block-mcp' ),
				),
				'innerBlocks' => array(
					'type'        => 'array',
					'description' => __( 'Child blocks. Nest recursively to build containers.', 'gk-block-mcp' ),
					'items'       => array( 'type' => 'object' ),
				),
			),
			'required'   => array( 'name' ),
		);
	}

	/**
	 * Canonical schema for a saved block snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function saved_snapshot_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'flat_index' => array( 'type' => 'number' ),
				'block_name' => array( 'type' => 'string' ),
				'attributes' => array( 'type' => 'object' ),
				'inner_html' => array( 'type' => 'string' ),
				'is_dynamic' => array( 'type' => 'boolean' ),
				'ref'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for atomic block batches.
	 *
	 * @return array<string, mixed>
	 */
	private function update_blocks_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'            => array( 'type' => 'boolean' ),
				'count'              => array( 'type' => 'number' ),
				'results'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'batch_index' => array( 'type' => 'number' ),
							'block'       => array(
								'type'       => 'object',
								'properties' => array(
									'index'      => array( 'type' => 'number' ),
									'name'       => array( 'type' => 'string' ),
									'attributes' => array( 'type' => 'object' ),
									'ref'        => array( 'type' => 'string' ),
								),
							),
							'saved'       => array_merge(
								$this->saved_snapshot_schema(),
								array( 'description' => __( 'Canonical post-save snapshot. Present only when verbose is true.', 'gk-block-mcp' ) )
							),
						),
					),
				),
				'before_revision_id' => array( 'type' => 'number' ),
				'revision_id'        => array( 'type' => 'number' ),
			),
		);
	}

	/**
	 * Input schema for path-based block-tree edits.
	 *
	 * @return array<string, mixed>
	 */
	private function edit_block_tree_input_schema() {
		$block_schema                = $this->block_definition_schema();
		$block_schema['description'] = __( 'replace-block / insert-child: a block definition. Containers need their empty wrapper innerHTML.', 'gk-block-mcp' );

		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'         => array(
					'type'        => 'number',
					'description' => __( 'Post ID.', 'gk-block-mcp' ),
				),
				'op'              => array(
					'type'        => 'string',
					'enum'        => array( 'update-attrs', 'update-html', 'replace-block', 'remove-block', 'wrap-in-group', 'unwrap-group', 'insert-child', 'duplicate', 'move' ),
					'description' => __( 'Operation to perform.', 'gk-block-mcp' ),
				),
				'path'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'Target block path. Provide this OR ref.', 'gk-block-mcp' ),
				),
				'ref'             => array(
					'type'        => 'string',
					'description' => __( 'Stable gk_ref of the target block. Provide this OR path.', 'gk-block-mcp' ),
				),
				'attributes'      => array(
					'type'        => 'object',
					'description' => __( 'update-attrs: attributes to merge.', 'gk-block-mcp' ),
				),
				'innerHTML'       => array(
					'type'        => 'string',
					'description' => __( 'update-html: replacement innerHTML.', 'gk-block-mcp' ),
				),
				'block'           => $block_schema,
				'wrapper'         => array(
					'type'        => 'object',
					'description' => __( 'wrap-in-group: optional wrapper block. Default core/group.', 'gk-block-mcp' ),
					'properties'  => array(
						'name'       => array(
							'type'        => 'string',
							'description' => __( 'Wrapper name. Default "core/group".', 'gk-block-mcp' ),
						),
						'attributes' => array( 'type' => 'object' ),
					),
				),
				'position'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'insert-child: index, "start", or "end" (default).', 'gk-block-mcp' ),
				),
				'destination'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'move: destination path using pre-move indexing.', 'gk-block-mcp' ),
				),
				'destination_ref' => array(
					'type'        => 'string',
					'description' => __( 'move: destination block ref, as an alternative to destination.', 'gk-block-mcp' ),
				),
				'count'           => array(
					'type'        => 'integer',
					'description' => __( 'move: consecutive blocks to move. Default 1.', 'gk-block-mcp' ),
				),
			),
			'required'   => array( 'post_id', 'op' ),
		);
	}

	/**
	 * Output schema for path-based block-tree edits.
	 *
	 * @return array<string, mixed>
	 */
	private function edit_block_tree_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'            => array( 'type' => 'boolean' ),
				'op'                 => array( 'type' => 'string' ),
				'path'               => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'block'              => array(
					'type'       => 'object',
					'properties' => array(
						'name'       => array( 'type' => 'string' ),
						'attributes' => array( 'type' => 'object' ),
						'ref'        => array(
							'type'        => 'string',
							'description' => __( 'New ref when the operation produced a new block.', 'gk-block-mcp' ),
						),
						'new_path'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'duplicate: path of the clone.', 'gk-block-mcp' ),
						),
					),
				),
				'warnings'           => array( 'type' => 'array' ),
				'formatted_warnings' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'before_revision_id' => array( 'type' => 'number' ),
				'revision_id'        => array( 'type' => 'number' ),
			),
		);
	}

	/**
	 * Input schema for post metadata updates.
	 *
	 * @return array<string, mixed>
	 */
	private function update_post_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'        => 'number',
					'description' => __( 'WordPress post ID.', 'gk-block-mcp' ),
				),
				'title'          => array( 'type' => 'string' ),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'private', 'publish', 'future', 'trash' ),
				),
				'slug'           => array( 'type' => 'string' ),
				'parent'         => array( 'type' => 'number' ),
				'excerpt'        => array( 'type' => 'string' ),
				'featured_media' => array(
					'type'        => 'number',
					'description' => __( 'Attachment ID. Send 0 to clear.', 'gk-block-mcp' ),
				),
				'categories'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'number' ),
				),
				'tags'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'number' ),
				),
				'terms'          => array( 'type' => 'object' ),
				'date'           => array( 'type' => 'string' ),
				'menu_order'     => array( 'type' => 'number' ),
				'comment_status' => array(
					'type' => 'string',
					'enum' => array( 'open', 'closed' ),
				),
				'ping_status'    => array(
					'type' => 'string',
					'enum' => array( 'open', 'closed' ),
				),
				'author'         => array( 'type' => 'number' ),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * Input schema for taxonomy term discovery.
	 *
	 * @return array<string, mixed>
	 */
	private function list_terms_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy'   => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy slug. Default: category.', 'gk-block-mcp' ),
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'LIKE match against term name.', 'gk-block-mcp' ),
				),
				'parent'     => array( 'type' => 'number' ),
				'hide_empty' => array(
					'type'        => 'boolean',
					'description' => __( 'Default: false.', 'gk-block-mcp' ),
				),
				'per_page'   => array(
					'type'        => 'number',
					'description' => __( 'Default 100, max 200.', 'gk-block-mcp' ),
				),
				'page'       => array(
					'type'        => 'number',
					'description' => __( '1-indexed.', 'gk-block-mcp' ),
				),
				'orderby'    => array(
					'type' => 'string',
					'enum' => array( 'name', 'count', 'term_id', 'slug' ),
				),
				'order'      => array(
					'type' => 'string',
					'enum' => array( 'asc', 'desc' ),
				),
				'include'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'number' ),
				),
				'slug'       => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Input schema for media uploads.
	 *
	 * @return array<string, mixed>
	 */
	private function upload_media_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'        => array(
					'type'        => 'string',
					'description' => __( 'Absolute path on the MCP host. This mode is available only through the npm MCP server.', 'gk-block-mcp' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'Public URL the WordPress site can fetch.', 'gk-block-mcp' ),
				),
				'data_base64' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded file contents (requires filename).', 'gk-block-mcp' ),
				),
				'filename'    => array(
					'type'        => 'string',
					'description' => __( 'Override filename (required when using data_base64).', 'gk-block-mcp' ),
				),
				'title'       => array( 'type' => 'string' ),
				'alt_text'    => array(
					'type'        => 'string',
					'description' => __( 'Saved as image alternative text. Critical for accessibility.', 'gk-block-mcp' ),
				),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'post_id'     => array(
					'type'        => 'number',
					'description' => __( 'Attach to a parent post.', 'gk-block-mcp' ),
				),
			),
		);
	}

	/**
	 * Yoast SEO field schemas shared by single and bulk updates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function yoast_field_properties() {
		return array(
			'title'               => array(
				'type'        => 'string',
				'description' => __( 'SEO title. Supports Yoast variables.', 'gk-block-mcp' ),
			),
			'description'         => array(
				'type'        => 'string',
				'description' => __( 'Meta description.', 'gk-block-mcp' ),
			),
			'canonical'           => array(
				'type'        => 'string',
				'description' => __( 'Canonical URL override.', 'gk-block-mcp' ),
			),
			'focus_keyword'       => array(
				'type'        => 'string',
				'description' => __( 'Focus keyphrase.', 'gk-block-mcp' ),
			),
			'noindex'             => array(
				'type'        => 'boolean',
				'description' => __( 'true=noindex, false=explicit index. The handler also accepts explicit null to reset to the post-type default.', 'gk-block-mcp' ),
			),
			'nofollow'            => array(
				'type'        => 'boolean',
				'description' => __( 'true=nofollow, false=follow.', 'gk-block-mcp' ),
			),
			'robots_advanced'     => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => array( 'noimageindex', 'noarchive', 'nosnippet' ),
				),
				'description' => __( 'Subset of: noimageindex, noarchive, nosnippet.', 'gk-block-mcp' ),
			),
			'og_title'            => array(
				'type'        => 'string',
				'description' => __( 'Open Graph title.', 'gk-block-mcp' ),
			),
			'og_description'      => array(
				'type'        => 'string',
				'description' => __( 'Open Graph description.', 'gk-block-mcp' ),
			),
			'og_image'            => array(
				'type'        => 'string',
				'description' => __( 'Open Graph image URL.', 'gk-block-mcp' ),
			),
			'og_image_id'         => array(
				'type'        => 'number',
				'description' => __( 'Attachment ID for the Open Graph image.', 'gk-block-mcp' ),
			),
			'twitter_title'       => array(
				'type'        => 'string',
				'description' => __( 'Twitter card title.', 'gk-block-mcp' ),
			),
			'twitter_description' => array(
				'type'        => 'string',
				'description' => __( 'Twitter card description.', 'gk-block-mcp' ),
			),
			'twitter_image'       => array(
				'type'        => 'string',
				'description' => __( 'Twitter card image URL.', 'gk-block-mcp' ),
			),
			'twitter_image_id'    => array(
				'type'        => 'number',
				'description' => __( 'Attachment ID for the Twitter image.', 'gk-block-mcp' ),
			),
			'schema_page_type'    => array(
				'type'        => 'string',
				'enum'        => array( 'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage', 'ContactPage', 'MedicalWebPage', 'CollectionPage', 'CheckoutPage', 'RealEstateListing', 'SearchResultsPage' ),
				'description' => __( 'Schema.org page type.', 'gk-block-mcp' ),
			),
			'schema_article_type' => array(
				'type'        => 'string',
				'enum'        => array( 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report', 'None' ),
				'description' => __( 'Schema.org article type.', 'gk-block-mcp' ),
			),
			'is_cornerstone'      => array(
				'type'        => 'boolean',
				'description' => __( 'Cornerstone content flag.', 'gk-block-mcp' ),
			),
			'breadcrumb_title'    => array(
				'type'        => 'string',
				'description' => __( 'Breadcrumb title override.', 'gk-block-mcp' ),
			),
			'redirect'            => array(
				'type'        => 'string',
				'description' => __( 'Redirect URL (Yoast Premium).', 'gk-block-mcp' ),
			),
			'primary_terms'       => array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'number' ),
				'description'          => __( 'Map of taxonomy names to primary term IDs.', 'gk-block-mcp' ),
			),
		);
	}

	/**
	 * Yoast abilities, registered only while Yoast SEO is active.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function yoast_definitions() {
		$update_properties = array_merge(
			array(
				'post_id' => array(
					'type'        => 'number',
					'description' => __( 'WordPress post or page ID.', 'gk-block-mcp' ),
				),
			),
			$this->yoast_field_properties()
		);

		return array(
			self::NAMESPACE_PREFIX . 'yoast-get-seo'    => array(
				'label'               => __( 'Get Yoast SEO metadata', 'gk-block-mcp' ),
				'description'         => __( 'Read all Yoast SEO metadata for a post or page.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'number',
							'description' => __( 'WordPress post or page ID.', 'gk-block-mcp' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_yoast_get_seo' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'yoast-update-seo' => array(
				'label'               => __( 'Update Yoast SEO metadata', 'gk-block-mcp' ),
				'description'         => __( 'Update one or more Yoast SEO fields on a single post or page. Only supplied fields are written.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $update_properties,
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_edit_post_input' ),
				'execute_callback'    => array( $this, 'execute_yoast_update_seo' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
			self::NAMESPACE_PREFIX . 'yoast-bulk-update-seo' => array(
				'label'               => __( 'Bulk-update Yoast SEO metadata', 'gk-block-mcp' ),
				'description'         => __( 'Update Yoast SEO fields on multiple posts in one call. Response order matches request order.', 'gk-block-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array(
							'type'        => 'array',
							'description' => __( 'Objects containing post_id and the fields to update.', 'gk-block-mcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => $update_properties,
								'required'   => array( 'post_id' ),
							),
						),
					),
					'required'   => array( 'posts' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'can_create' ),
				'execute_callback'    => array( $this, 'execute_yoast_bulk_update_seo' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
		);
	}

	// ── Permission callbacks ──────────────────────────────────────────

	/**
	 * Permit when the user can edit the post named in the input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public function can_edit_post_input( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permit creation for users who can edit posts. The precise create/publish
	 * capability is enforced inside Post_Manager::create_post().
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public function can_create( $input ) {
		unset( $input );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permit read/discovery for users who can edit posts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public function can_read( $input ) {
		unset( $input );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permit administrative inventory scans.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public function can_manage_options( $input ) {
		unset( $input );
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permit media writes for users with the upload capability.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public function can_upload_files( $input ) {
		unset( $input );
		return current_user_can( 'upload_files' );
	}

	// ── Execute callbacks (delegate to the service graph) ─────────────

	/**
	 * Read a post's block tree via Block_CRUD.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_page_blocks( $input ) {
		return $this->crud->get_blocks( (int) $input['post_id'] );
	}

	/**
	 * Update one block by flat index via Block_CRUD.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_update_block( $input ) {
		$attributes = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();
		$inner_html = isset( $input['inner_html'] ) ? (string) $input['inner_html'] : null;
		return $this->crud->update_block( (int) $input['post_id'], (int) $input['index'], $attributes, $inner_html );
	}

	/**
	 * Insert blocks at a position via Block_CRUD.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_insert_blocks( $input ) {
		$position = isset( $input['position'] ) ? (int) $input['position'] : null;
		$blocks   = isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array();
		return $this->crud->insert_blocks( (int) $input['post_id'], $position, $blocks );
	}

	/**
	 * Create a post via Post_Manager.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_create_post( $input ) {
		return $this->post_manager->create_post( is_array( $input ) ? $input : array() );
	}

	/**
	 * List registered block types via Block_Registry.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_block_types( $input ) {
		$args = array();
		if ( is_array( $input ) && ! empty( $input['namespace'] ) ) {
			$args['namespace'] = (string) $input['namespace'];
		}
		return $this->registry->get_block_types( $args );
	}

	/**
	 * List patterns with the MCP tool's offset pagination envelope.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_patterns( $input ) {
		$limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 20;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$params = array(
			'q'         => isset( $input['search'] ) ? (string) $input['search'] : null,
			'synced'    => array_key_exists( 'synced', $input ) ? (bool) $input['synced'] : null,
			'min_score' => isset( $input['min_score'] ) ? (int) $input['min_score'] : null,
			'limit'     => $offset + $limit,
			'refresh'   => ! empty( $input['refresh'] ),
		);

		$data = $this->call_rest_handler( array( $this->controller, 'get_patterns' ), 'GET', '/patterns', $params );
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
	 * Get a pattern and its formatted blocks.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_pattern( $input ) {
		$pattern_id = isset( $input['pattern_id'] ) ? $input['pattern_id'] : '';
		return $this->call_rest_handler(
			array( $this->controller, 'get_pattern' ),
			'GET',
			'/patterns/' . rawurlencode( (string) $pattern_id ),
			array( 'id' => $pattern_id )
		);
	}

	/**
	 * Get cached site-wide block and pattern usage.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_site_usage( $input ) {
		return $this->call_rest_handler(
			array( $this->controller, 'get_site_usage' ),
			'GET',
			'/site-usage',
			array( 'refresh' => ! empty( $input['refresh'] ) )
		);
	}

	/**
	 * Scan and persist live block storage-mode classifications.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_scan_storage_modes( $input ) {
		unset( $input );
		return $this->call_rest_handler( array( $this->controller, 'scan_storage_modes' ), 'POST', '/storage-modes/scan' );
	}

	/**
	 * Resolve a URL or site-relative path to a post.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_resolve_url( $input ) {
		return $this->call_rest_handler(
			array( $this->controller, 'resolve_url' ),
			'GET',
			'/resolve',
			array( 'url' => (string) $input['url'] )
		);
	}

	/**
	 * Search posts through the shared REST query implementation.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_posts( $input ) {
		return $this->call_rest_handler( array( $this->controller, 'find_posts' ), 'GET', '/find-posts', $input );
	}

	/**
	 * Resolve and return metadata for one post.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_post_info( $input ) {
		return $this->call_rest_handler( array( $this->controller, 'post_info' ), 'GET', '/post-info', $input );
	}

	/**
	 * Fetch one block by stable ref or flat index.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_block( $input ) {
		$params = array( 'id' => (int) $input['post_id'] );
		if ( isset( $input['ref'] ) ) {
			$params['ref'] = (string) $input['ref'];
		}
		if ( isset( $input['flat_index'] ) ) {
			$params['flat_index'] = (int) $input['flat_index'];
		}

		return $this->call_rest_handler( array( $this->controller, 'get_block' ), 'GET', '/posts/' . $params['id'] . '/block', $params );
	}

	/**
	 * Remove one or more blocks via Block_CRUD.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_delete_block( $input ) {
		$count = isset( $input['count'] ) ? max( 1, (int) $input['count'] ) : 1;
		return $this->crud->delete_blocks( (int) $input['post_id'], (int) $input['index'], $count );
	}

	/**
	 * Apply multiple block updates atomically.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_update_blocks( $input ) {
		$post_id = (int) $input['post_id'];
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['updates'] ) || ! is_array( $input['updates'] ) ) {
			return new \WP_Error(
				'missing_updates',
				__( 'updates must be a non-empty array.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'updates' => $input['updates'],
			'verbose' => ! empty( $input['verbose'] ),
		);

		return $this->call_rest_handler(
			array( $this->controller, 'update_blocks_batch' ),
			'POST',
			'/posts/' . $post_id . '/blocks/batch-update',
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Atomically replace a top-level block range.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_replace_block_range( $input ) {
		$post_id = (int) $input['post_id'];
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$body = array(
			'start'  => (int) $input['start'],
			'count'  => (int) $input['count'],
			'blocks' => isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array(),
		);

		return $this->call_rest_handler(
			array( $this->controller, 'replace_blocks_range' ),
			'POST',
			'/posts/' . $post_id . '/blocks/replace',
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Replace every block on a post.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_rewrite_post_blocks( $input ) {
		$post_id = (int) $input['post_id'];
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['blocks'] ) || ! is_array( $input['blocks'] ) ) {
			return new \WP_Error( 'missing_blocks', __( 'At least one block is required.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		return $this->call_rest_handler(
			array( $this->controller, 'replace_all_blocks' ),
			'PUT',
			'/posts/' . $post_id . '/blocks',
			array( 'id' => $post_id ),
			array( 'blocks' => $input['blocks'] )
		);
	}

	/**
	 * Restore a post revision.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_revert_to_revision( $input ) {
		$post_id     = (int) $input['post_id'];
		$revision_id = (int) $input['revision_id'];
		if ( $post_id <= 0 || $revision_id <= 0 ) {
			return new \WP_Error(
				'missing_ids',
				__( 'post_id and revision_id are required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		return $this->call_rest_handler(
			array( $this->controller, 'revert_to_revision' ),
			'POST',
			'/posts/' . $post_id . '/revert',
			array( 'id' => $post_id ),
			array( 'revision_id' => $revision_id )
		);
	}

	/**
	 * Insert a synced or inline pattern.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_insert_pattern( $input ) {
		$post_id = (int) $input['post_id'];
		$body    = array(
			'pattern_id' => $input['pattern_id'],
			'synced'     => array_key_exists( 'synced', $input ) ? (bool) $input['synced'] : true,
		);
		if ( isset( $input['after_top_level'] ) ) {
			$body['after'] = (int) $input['after_top_level'];
		}
		if ( isset( $input['before_top_level'] ) ) {
			$body['before'] = (int) $input['before_top_level'];
		}

		$data = $this->call_rest_handler(
			array( $this->controller, 'insert_pattern' ),
			'POST',
			'/posts/' . $post_id . '/insert-pattern',
			array( 'id' => $post_id ),
			$body
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['note'] = ! empty( $data['inserted']['synced'] )
			? __( 'Pattern inserted as synced reference. Changes to the source pattern will update this page.', 'gk-block-mcp' )
			: __( 'Pattern blocks inserted inline. This copy is independent and can be edited per-page.', 'gk-block-mcp' );
		return $data;
	}

	/**
	 * Execute one nested block-tree mutation.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_edit_block_tree( $input ) {
		$post_id = (int) $input['post_id'];
		$body    = $input;
		unset( $body['post_id'] );

		return $this->call_rest_handler(
			array( $this->controller, 'mutate_block_tree' ),
			'POST',
			'/posts/' . $post_id . '/mutate',
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * Update post metadata, status, or terms.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_update_post( $input ) {
		$post_id = (int) $input['post_id'];
		$body    = $input;
		unset( $body['post_id'] );

		if ( empty( $body ) ) {
			return new \WP_Error(
				'missing_fields',
				__( 'Provide at least one mutating field besides post_id.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		return $this->call_rest_handler(
			array( $this->controller, 'update_post' ),
			'PATCH',
			'/posts/' . $post_id,
			array( 'id' => $post_id ),
			$body
		);
	}

	/**
	 * List taxonomy terms.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_terms( $input ) {
		return $this->call_rest_handler( array( $this->controller, 'list_terms' ), 'GET', '/terms', $input );
	}

	/**
	 * Upload media from a URL or base64 data.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_upload_media( $input ) {
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

		return $this->call_rest_handler( array( $this->controller, 'upload_media' ), 'POST', '/media', array(), $input );
	}

	/**
	 * Read Yoast SEO metadata for one post.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_yoast_get_seo( $input ) {
		$post_id = (int) $input['post_id'];
		return $this->call_rest_handler(
			array( $this->yoast_bridge, 'get_seo' ),
			'GET',
			'/yoast/' . $post_id,
			array( 'post_id' => $post_id )
		);
	}

	/**
	 * Update Yoast SEO metadata for one post.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_yoast_update_seo( $input ) {
		$post_id = (int) $input['post_id'];
		$body    = $input;
		unset( $body['post_id'] );

		return $this->call_rest_handler(
			array( $this->yoast_bridge, 'update_seo' ),
			'POST',
			'/yoast/' . $post_id,
			array( 'post_id' => $post_id ),
			$body
		);
	}

	/**
	 * Bulk-update Yoast SEO metadata.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_yoast_bulk_update_seo( $input ) {
		// The permission_callback is only the coarse `edit_posts` gate; a
		// multi-post write has no single target to check up front. Real
		// enforcement is per-post `edit_post` inside the delegated
		// Yoast_Bridge::bulk_update_seo(), which skips any post the caller
		// cannot edit. So bulk is not weaker-gated than single yoast-update-seo.
		if ( empty( $input['posts'] ) || ! is_array( $input['posts'] ) ) {
			return new \WP_Error(
				'missing_posts',
				__( 'yoast_bulk_update_seo: non-empty posts array is required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		return $this->call_rest_handler(
			array( $this->yoast_bridge, 'bulk_update_seo' ),
			'POST',
			'/yoast/bulk',
			array(),
			array( 'posts' => $input['posts'] )
		);
	}

	/**
	 * Invoke a REST handler through the already-wired service graph and return
	 * its structured payload.
	 *
	 * @param callable             $callback REST handler callback.
	 * @param string               $method   HTTP method.
	 * @param string               $path     Namespace-relative path.
	 * @param array<string, mixed> $params   Route and query parameters.
	 * @param array<string, mixed> $body     JSON body parameters.
	 * @return array|\WP_Error
	 */
	private function call_rest_handler( callable $callback, $method, $path, array $params = array(), array $body = array() ) {
		$request = new \WP_REST_Request( $method, '/' . REST_Controller::NAMESPACE . $path );

		// Route params (notably the post `id`) go in the URL-params bucket, the
		// one real routing fills for `{id}` segments and controllers read via
		// `$request['id']`. set_param() would land them in the body bucket, which
		// set_body_params() below overwrites — the handler would then read id 0
		// and every write ability would falsely fail its edit_post check.
		$route_params = array();
		foreach ( $params as $key => $value ) {
			if ( null !== $value ) {
				$route_params[ $key ] = $value;
			}
		}
		if ( ! empty( $route_params ) ) {
			$request->set_url_params( $route_params );
		}

		if ( ! empty( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
			$request->set_body_params( $body );
		}

		$response = call_user_func( $callback, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof \WP_REST_Response ) {
			return new \WP_Error(
				'unexpected_response',
				__( 'Unexpected response from Block MCP handler.', 'gk-block-mcp' ),
				array( 'status' => 500 )
			);
		}
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : array( 'result' => $data );
	}

	/**
	 * Build the site design context (theme + flattened theme.json presets) so an
	 * agent composes theme-aligned, valid block markup.
	 *
	 * @param array<string, mixed> $input Ability input (unused).
	 * @return array<string, mixed>
	 */
	public function execute_site_editor_context( $input ) {
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
}
