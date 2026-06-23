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
	 * Constructor.
	 *
	 * @param Block_CRUD     $crud         Block CRUD service.
	 * @param Post_Manager   $post_manager Post manager service.
	 * @param Block_Registry $registry     Block registry service.
	 */
	public function __construct( Block_CRUD $crud, Post_Manager $post_manager, Block_Registry $registry ) {
		$this->crud         = $crud;
		$this->post_manager = $post_manager;
		$this->registry     = $registry;
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
		return array(
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
					'annotations'  => array( 'readonly' => false ),
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
					'annotations'  => array( 'readonly' => false ),
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
