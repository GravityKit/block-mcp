<?php
/**
 * Settings page for the Block MCP plugin.
 *
 * Single Settings → Block MCP admin page exposing the policy that drives
 * tier classification, replacement suggestions, dual-storage detection,
 * and the post-type allow-list. All fields persist to existing options
 * already consumed by the runtime — Preferences (tier scores, replacement
 * map), Post_Manager (allow-list), Block_Inventory (dual-storage extras +
 * scan results).
 *
 * Settings API is used for the form fields. The "Re-scan storage modes"
 * and "Reset to defaults" buttons post to admin-post.php with an action
 * + nonce so they don't share state with the form's settings save.
 *
 * Capability: `manage_options`.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Page
 */
class Settings_Page {

	const PAGE_SLUG    = 'gk-block-mcp-settings';
	const OPTION_GROUP = 'gk_block_api_settings';

	/** Option backing the manual "force-treat as dual-storage" list (UI-editable). */
	const DUAL_MANUAL_OPTION = 'gk_block_api_dual_storage_blocks_manual';

	/**
	 * Block inventory instance.
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Block_Inventory $inventory Used by the "Re-scan storage modes" button.
	 *                                   Defaults are read directly via `Preferences::get_defaults()`
	 *                                   in the renderer — no Preferences instance needed here.
	 */
	public function __construct( Block_Inventory $inventory ) {
		$this->inventory = $inventory;
	}

	/**
	 * Wire up admin hooks. Safe to call from rest_api_init or admin_init —
	 * the inner hooks fire later in the request lifecycle.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gk_block_api_scan_storage_modes', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_gk_block_api_reset_defaults', array( $this, 'handle_reset' ) );
		add_action( 'in_admin_header', array( $this, 'suppress_foreign_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load WordPress core's component stylesheet on this settings page only.
	 *
	 * The page's action buttons use the core @wordpress/components button
	 * classes (.components-button) rather than bespoke CSS. Section containers
	 * use core .postbox cards and data tables use core .widefat styling, so the
	 * whole screen is styled by WordPress core with no custom stylesheet.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG === $hook_suffix ) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'dashicons' );
		}
	}

	/**
	 * Add the page under Settings.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Block MCP', 'gk-block-mcp' ),
			__( 'Block MCP', 'gk-block-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Hide other plugins' and core admin notices on this settings page only.
	 *
	 * Unrelated admin notices (update nags, third-party banners) clutter the
	 * onboarding screen. Everything that renders through the admin_notices /
	 * all_admin_notices hooks is removed just before the admin header prints
	 * them, and only on this screen. The plugin's own confirmations are echoed
	 * inline in render_page() (not through those hooks), so they are unaffected.
	 */
	public function suppress_foreign_admin_notices() {
		$screen           = get_current_screen();
		$on_settings_page = $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id;
		if ( $on_settings_page ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	/**
	 * Register settings + sections + fields.
	 */
	public function register_settings() {
		// 1. Preferences (tier scores + replacement map). Stored as a single
		// associative array; we sanitize sub-keys in the callback.
		register_setting(
			self::OPTION_GROUP,
			'gk_block_api_preferences',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_preferences' ),
				'default'           => array(),
			)
		);

		// 2. Post-type allow-list for create_post (BLOCK-12 / v1.2).
		register_setting(
			self::OPTION_GROUP,
			'gk_block_api_post_types_allowlist',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_type_allowlist' ),
				'default'           => array(),
			)
		);

		// 3. Manual dual-storage list — merged with scan results + filter defaults.
		register_setting(
			self::OPTION_GROUP,
			self::DUAL_MANUAL_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_block_name_list' ),
				'default'           => array(),
			)
		);

		// 4. MCP server instructions addendum (BLOCK-19).
		// Stored as a plain-text string. The Instructions class handles
		// sanitize + length-cap + timestamp; the REST endpoint serves it
		// unauthenticated to MCP clients at handshake.
		register_setting(
			self::OPTION_GROUP,
			Instructions::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Instructions::class, 'sanitize_callback' ),
				'default'           => '',
			)
		);

		// 5. Global media-uploads kill-switch. Stored as the string '0' or
		// '1' rather than a PHP bool because update_option() can't
		// reliably persist boolean false when the option is missing
		// (the equality check against the "doesn't exist → false" default
		// short-circuits the write).
		register_setting(
			self::OPTION_GROUP,
			\GravityKit\BlockMCP\Media_Manager::UPLOADS_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'normalize_checkbox_option' ),
				'default'           => '1',
			)
		);

		// 6. "Let the assistant move posts to trash" toggle. Same '0'/'1'
		// string storage rationale as the media kill-switch above. Default
		// '0' (off): trashing is closed until a site owner opts in.
		register_setting(
			self::OPTION_GROUP,
			\GravityKit\BlockMCP\Post_Manager::ALLOW_TRASH_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'normalize_checkbox_option' ),
				'default'           => '0',
			)
		);
	}

	// ──────────────────────────────────────────────────────────────────
	// Sanitization callbacks.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Normalize an HTML checkbox value to the '0' / '1' strings the boolean
	 * toggles persist.
	 *
	 * These settings round-trip as strings, not bools: PHP omits an unchecked
	 * checkbox from $_POST entirely, and update_option() won't reliably store a
	 * literal `false` against an option that doesn't exist yet (the equality
	 * check against the "missing → false" default short-circuits the write). A
	 * hidden '0' input paired with the '1' checkbox guarantees a value arrives
	 * here either way.
	 *
	 * @param mixed $value Raw POST value (bool, or one of '1'/'on'/'true'/'yes').
	 * @return string '1' when truthy, '0' otherwise.
	 */
	public static function normalize_checkbox_option( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$truthy = in_array( strtolower( (string) $value ), array( '1', 'on', 'true', 'yes' ), true );
		return $truthy ? '1' : '0';
	}

	/**
	 * Sanitize the indexed-row form input back into the canonical
	 * `namespace_scores` + `replacement_map` shape Preferences expects.
	 *
	 * Form input is row-indexed so we can rename namespaces/blocks safely
	 * and so a new row's values are correlated. Rows flagged with `delete:1`
	 * are dropped.
	 *
	 * @param mixed $input Raw POST value.
	 * @return array
	 */
	public function sanitize_preferences( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$out = array();

		// Namespace tier scores — indexed rows: [{name, score, delete?}, ...].
		if ( isset( $input['namespace_rows'] ) && is_array( $input['namespace_rows'] ) ) {
			$out['namespace_scores'] = array();
			foreach ( $input['namespace_rows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
					continue;
				}
				$ns = isset( $row['name'] ) ? sanitize_key( $row['name'] ) : '';
				if ( '' === $ns ) {
					continue;
				}
				$score                          = isset( $row['score'] ) ? (int) $row['score'] : 0;
				$out['namespace_scores'][ $ns ] = max( 0, min( 100, $score ) );
			}
		}

		// Replacement map — indexed rows: [{from, to, delete?}, ...].
		if ( isset( $input['replacement_rows'] ) && is_array( $input['replacement_rows'] ) ) {
			$out['replacement_map'] = array();
			foreach ( $input['replacement_rows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
					continue;
				}
				$from = isset( $row['from'] ) ? $this->sanitize_block_name( $row['from'] ) : '';
				$to   = isset( $row['to'] ) ? $this->sanitize_block_name( $row['to'] ) : '';
				if ( '' !== $from && '' !== $to ) {
					$out['replacement_map'][ $from ] = $to;
				}
			}
		}

		// Preserve any other top-level keys the runtime may add (forwards-compat).
		$existing = (array) get_option( 'gk_block_api_preferences', array() );
		return array_merge( $existing, $out );
	}

	/**
	 * Validate post types against the registered list. Filters out
	 * anything that isn't actually registered to prevent typos.
	 *
	 * @param mixed $input Raw POST value.
	 * @return string[]
	 */
	public function sanitize_post_type_allowlist( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$registered = get_post_types( array( 'public' => true ), 'names' );
		$out        = array();
		foreach ( $input as $type ) {
			$slug = sanitize_key( $type );
			if ( '' !== $slug && isset( $registered[ $slug ] ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize a list of fully-qualified block names (one per line in the textarea).
	 *
	 * @param mixed $input Raw POST value — string (textarea) or array.
	 * @return string[]
	 */
	public function sanitize_block_name_list( $input ) {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\r\n,]+/', $input );
		}
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $name ) {
			$clean = $this->sanitize_block_name( $name );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize a single block name. Allows lowercased letters, digits, dashes,
	 * underscores, and a single forward slash separator.
	 *
	 * @param string $name Raw block name to sanitize.
	 * @return string Empty string if invalid.
	 */
	private function sanitize_block_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $name ) ) {
			return '';
		}
		return $name;
	}

	// ──────────────────────────────────────────────────────────────────
	// Action handlers (admin-post.php).
	// ──────────────────────────────────────────────────────────────────

	/**
	 * "Re-scan storage modes" button handler.
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gk_block_api_scan_storage_modes' );

		$result = $this->inventory->scan_storage_modes();

		nocache_headers();
		$args = array(
			'page'    => self::PAGE_SLUG,
			// The scan button and its success notice live on the Block-policy
			// tab; preserve the tab so the notice is actually visible on return.
			'tab'     => 'policy',
			'scanned' => 1,
			'unique'  => (int) $result['unique_blocks'],
			'dual'    => (int) $result['dual_count'],
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * "Reset to defaults" button handler. Deletes all UI-managed options
	 * AND the inventory transients + per-post rate-limit transients so
	 * the next read starts from a true clean slate.
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gk_block_api_reset_defaults' );

		delete_option( 'gk_block_api_preferences' );
		delete_option( 'gk_block_api_post_types_allowlist' );
		delete_option( self::DUAL_MANUAL_OPTION );
		delete_option( Media_Manager::UPLOADS_OPTION );
		delete_option( Block_Inventory::STORAGE_MODES_OPTION );
		delete_option( Instructions::OPTION_KEY );
		delete_option( Instructions::UPDATED_AT_OPTION );
		delete_transient( Block_Inventory::CACHE_KEY );

		// Per-post rate-limit transients accumulate per write activity. Sweep
		// them too so reset is a true clean slate.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_gk_block_api_rate_%'
				   OR option_name LIKE '_transient_timeout_gk_block_api_rate_%'
				   OR option_name LIKE '_transient_gk_block_api_instr_rl_%'
				   OR option_name LIKE '_transient_timeout_gk_block_api_instr_rl_%'"
		);

		nocache_headers();
		$args = array(
			'page'  => self::PAGE_SLUG,
			// The reset button and its success notice live on the Block-policy
			// tab; preserve the tab so the notice is actually visible on return.
			'tab'   => 'policy',
			'reset' => 1,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	// ──────────────────────────────────────────────────────────────────
	// Render.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
		}

		// Focused consent screen: when the connector sends the admin here with
		// ?gk_authorize, drop the settings tabs and chrome and render only the
		// Approve card. The page then reads as a single allow/deny prompt rather
		// than a settings page the admin could wander away from mid-connect. The
		// Approve form itself is nonce-protected; gk_authorize is only a mode flag.
		if ( isset( $_GET['gk_authorize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mode flag, not user data.
			?>
			<div class="wrap">
				<?php ( new Connect_Page() )->render_section(); ?>
			</div>
			<?php
			return;
		}

		$defaults         = Preferences::get_defaults();
		$prefs            = (array) get_option( 'gk_block_api_preferences', array() );
		$namespace_scores = isset( $prefs['namespace_scores'] ) && is_array( $prefs['namespace_scores'] )
			? $prefs['namespace_scores']
			: $defaults['namespace_scores'];
		$replacement_map  = isset( $prefs['replacement_map'] ) && is_array( $prefs['replacement_map'] )
			? $prefs['replacement_map']
			: $defaults['replacement_map'];
		$post_type_allow  = (array) get_option( 'gk_block_api_post_types_allowlist', array() );
		$manual_dual      = (array) get_option( self::DUAL_MANUAL_OPTION, array() );
		$scan_results     = (array) get_option( Block_Inventory::STORAGE_MODES_OPTION, array() );
		$uploads_enabled  = \GravityKit\BlockMCP\Media_Manager::uploads_enabled();
		$uploads_option   = \GravityKit\BlockMCP\Media_Manager::UPLOADS_OPTION;
		$trash_enabled    = \GravityKit\BlockMCP\Post_Manager::trashing_enabled();
		$trash_option     = \GravityKit\BlockMCP\Post_Manager::ALLOW_TRASH_OPTION;
		$instructions_val = Instructions::get_addendum();
		$instructions_max = Instructions::MAX_LENGTH;

		$registered_post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Build a sorted list of every registered block name for the searchable
		// dropdown in the replacement-map columns. Uses SORT_NATURAL +
		// SORT_FLAG_CASE so the dropdown reads the way a human would expect:
		// case-insensitive (so `core/` and `Core/` mix correctly), and
		// "image2" sorts after "image1" rather than between "image1" and
		// "image10" the way a plain ASCII sort would.
		$block_names = array();
		if ( class_exists( '\WP_Block_Type_Registry' ) ) {
			$registered_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
			$block_names       = array_keys( $registered_blocks );
			sort( $block_names, SORT_NATURAL | SORT_FLAG_CASE );
		}

		// Unique block families (namespaces) for the "Block family" type-ahead.
		// The score table keys on namespace (e.g. "core"), not full block names
		// like the replacement table, so it gets its own suggestion list.
		$block_families = array();
		foreach ( $block_names as $registered_name ) {
			$slash  = strpos( $registered_name, '/' );
			$family = ( false !== $slash ) ? substr( $registered_name, 0, $slash ) : $registered_name;
			if ( '' !== $family ) {
				$block_families[ $family ] = true;
			}
		}
		$block_families = array_keys( $block_families );
		sort( $block_families, SORT_NATURAL | SORT_FLAG_CASE );

		// Notices from action handlers. All inputs unslashed and clamped via
		// absint before composition; the message itself never contains user data.
		$scanned      = isset( $_GET['scanned'] ) ? absint( wp_unslash( $_GET['scanned'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect.
		$unique_count = isset( $_GET['unique'] ) ? absint( wp_unslash( $_GET['unique'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dual_count   = isset( $_GET['dual'] ) ? absint( wp_unslash( $_GET['dual'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset_flag   = isset( $_GET['reset'] ) ? absint( wp_unslash( $_GET['reset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Read the active tab. Defaults to 'connect' so the onboarding section
		// loads first. This is a read-only UI flag from our own links.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<style>
				/* Modern WordPress look: a white content canvas that fills the
					window and scrolls normally, instead of the default gray admin
					background. Scoped to this settings page (body class, plus :has()
					for the html element it can't otherwise reach) so the rest of
					wp-admin is untouched. Whiten the full backing stack — html /
					body / #wpwrap / #wpbody / #wpcontent / #wpbody-content — so no
					gray shows through beside the menu or below short content. */
				html:has( body.settings_page_gk-block-mcp-settings ) {
					background: #fff;
				}
				body.settings_page_gk-block-mcp-settings,
				body.settings_page_gk-block-mcp-settings #wpwrap,
				body.settings_page_gk-block-mcp-settings #wpbody,
				body.settings_page_gk-block-mcp-settings #wpcontent,
				body.settings_page_gk-block-mcp-settings #wpbody-content {
					background: #fff;
				}
				/* Fill at least the viewport height so a short page still reads as
					a full white canvas. min-height (not height) keeps the normal
					document scroll for taller pages. 32px = admin bar height. */
				body.settings_page_gk-block-mcp-settings #wpbody-content {
					min-height: calc( 100vh - 32px );
					padding-bottom: 24px;
				}
				/* Extend the dark admin menu's backing to the full page height so
					the sidebar doesn't visually stop partway down a tall page (its
					natural item-list height) against the white canvas. */
				body.settings_page_gk-block-mcp-settings #adminmenuback,
				body.settings_page_gk-block-mcp-settings #adminmenuwrap {
					min-height: 100% !important;
				}

				/* Modern components-style tabs (underline indicator) in place of
					the classic gray boxed nav-tabs. Body-class scoped so core
					nav-tab styling elsewhere is untouched; the prefix raises
					specificity above core's single-class rules. */
				.settings_page_gk-block-mcp-settings .nav-tab-wrapper {
					display: flex;
					gap: 4px;
					margin: 0 0 24px;
					padding: 0;
					border-bottom: 1px solid #e0e0e0;
				}
				.settings_page_gk-block-mcp-settings .nav-tab {
					margin: 0;
					padding: 12px 16px;
					border: 0;
					border-radius: 0;
					background: transparent;
					box-shadow: none;
					color: #50575e;
					font-size: 14px;
					font-weight: 500;
					line-height: 1.4;
				}
				.settings_page_gk-block-mcp-settings .nav-tab:hover {
					background: transparent;
					color: var(--wp-admin-theme-color, #2271b1);
					box-shadow: none;
				}
				.settings_page_gk-block-mcp-settings .nav-tab:focus-visible {
					outline: 2px solid var(--wp-admin-theme-color, #2271b1);
					outline-offset: -2px;
					box-shadow: none;
				}
				.settings_page_gk-block-mcp-settings .nav-tab-active,
				.settings_page_gk-block-mcp-settings .nav-tab-active:hover,
				.settings_page_gk-block-mcp-settings .nav-tab-active:focus {
					background: transparent;
					color: var(--wp-admin-theme-color, #2271b1);
					box-shadow: inset 0 -2px 0 0 var(--wp-admin-theme-color, #2271b1);
				}
				.gk-tab-panel[hidden] {
					display: none;
				}
			</style>
			<h1><?php esc_html_e( 'Block MCP Settings', 'gk-block-mcp' ); ?></h1>
			<p class="description gk-block-mcp-subtitle" style="margin:4px 0 12px; max-width:800px;">
				<?php esc_html_e( 'Connect AI assistants like Claude to edit your site — no code required. (MCP stands for Model Context Protocol, the technology that lets AI apps connect to your site.)', 'gk-block-mcp' ); ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => 'connect',
						),
						admin_url( 'options-general.php' )
					)
				);
				?>
							" data-tab="connect" class="nav-tab<?php echo 'connect' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Connect', 'gk-block-mcp' ); ?></a>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => 'policy',
						),
						admin_url( 'options-general.php' )
					)
				);
				?>
							" data-tab="policy" class="nav-tab<?php echo 'policy' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'gk-block-mcp' ); ?></a>
			</h2>

			<div class="gk-tab-panel" data-tab-panel="connect"<?php echo 'connect' === $tab ? '' : ' hidden'; ?>>

				<?php ( new Connect_Page() )->render_section(); ?>

			</div>
			<div class="gk-tab-panel" data-tab-panel="policy"<?php echo 'policy' === $tab ? '' : ' hidden'; ?>>
				<style>
					/* Generous, WP-native rhythm so each settings section breathes:
						a wide gap above every section heading, with its description
						pulled in tight beneath it so the pair reads as one unit. */
					.gk-tab-panel[data-tab-panel="policy"] h2 {
						margin: 40px 0 4px;
					}
					.gk-tab-panel[data-tab-panel="policy"] h2 + p {
						margin-top: 4px;
					}
					.gk-tab-panel[data-tab-panel="policy"] form:first-of-type > h2:first-of-type {
						margin-top: 20px;
					}
					.gk-tab-panel[data-tab-panel="policy"] .gk-block-mcp-advanced > h2:first-of-type {
						margin-top: 24px;
					}
					.gk-tab-panel[data-tab-panel="policy"] .gk-block-mcp-section-rule {
						margin: 40px 0 0;
						border: 0;
						border-top: 1px solid #dcdcde;
					}
					.gk-tab-panel[data-tab-panel="policy"] .gk-block-mcp-section-rule + h2 {
						margin-top: 20px;
					}
					.gk-tab-panel[data-tab-panel="policy"] {
						padding-bottom: 40px;
					}
				</style>


				<?php if ( $scanned ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: total unique blocks, 2: dual-storage count */
							__( 'Storage-mode scan complete. %1$d unique blocks classified (%2$d dual-storage).', 'gk-block-mcp' ),
							$unique_count,
							$dual_count
						)
					);
					?>
				</p></div>
			<?php endif; ?>
				<?php if ( $reset_flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings reset to defaults.', 'gk-block-mcp' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'These settings control which blocks AI assistants prefer, what to use in place of older blocks, and which blocks need extra care when edited.', 'gk-block-mcp' ); ?></p>

				<?php
				/*
				* Live region for screen-reader announcements when the auto-grow
				* JS appends a new blank row. Visually hidden via WP's standard
				* .screen-reader-text class.
				*/
				?>
			<div id="gk-block-mcp-live" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>

			<datalist id="gk-block-names">
				<?php for ( $i = 0, $bn_count = count( $block_names ); $i < $bn_count; $i++ ) : ?>
					<option value="<?php echo esc_attr( $block_names[ $i ] ); ?>"></option>
				<?php endfor; ?>
			</datalist>

			<datalist id="gk-block-families">
				<?php foreach ( $block_families as $block_family ) : ?>
					<option value="<?php echo esc_attr( $block_family ); ?>"></option>
				<?php endforeach; ?>
			</datalist>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>


				<h2><?php esc_html_e( 'What AI assistants can create', 'gk-block-mcp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose which kinds of content AI assistants are allowed to create. Check the types you want to allow.', 'gk-block-mcp' ); ?></p>
				<?php
				$gk_allow_all_msg  = __( 'All public content types are currently allowed (the default).', 'gk-block-mcp' );
				$gk_restricted_msg = __( 'Only the checked content types are allowed.', 'gk-block-mcp' );
				?>
				<p class="description gk-block-mcp-allow-all-note" aria-live="polite">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<span class="gk-block-mcp-allow-all-text"><?php echo esc_html( empty( $post_type_allow ) ? $gk_allow_all_msg : $gk_restricted_msg ); ?></span>
				</p>
				<fieldset class="gk-block-mcp-allowlist">
					<legend class="screen-reader-text"><?php esc_html_e( 'Content types AI assistants are allowed to create', 'gk-block-mcp' ); ?></legend>
					<?php
					$pt_slugs = array_keys( $registered_post_types );
					$pt_count = count( $pt_slugs );
					for ( $pt_idx = 0; $pt_idx < $pt_count; $pt_idx++ ) :
						$slug     = $pt_slugs[ $pt_idx ];
						$type_obj = $registered_post_types[ $slug ];
						?>
						<label>
							<input type="checkbox" name="gk_block_api_post_types_allowlist[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $post_type_allow, true ) ); ?> />
							<?php echo esc_html( $type_obj->labels->singular_name ); ?> <code><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endfor; ?>
				</fieldset>
				<style>
					.gk-block-mcp-allowlist {
						display: grid;
						grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
						gap: 8px 16px;
						max-width: 1000px;
						margin-top: 8px;
					}
					.gk-block-mcp-allowlist label {
						display: flex;
						align-items: center;
						gap: 6px;
						white-space: nowrap;
						overflow: hidden;
						text-overflow: ellipsis;
					}
					.gk-tab-panel[data-tab-panel="policy"] .gk-block-mcp-allow-all-note {
						margin: 20px 0 24px;
					}
					.gk-block-mcp-allow-all-note {
						padding: 10px 14px;
						background: #f0f6fc;
						border-left: 3px solid #72aee6;
						display: flex;
						align-items: center;
						gap: 6px;
						max-width: 1000px;
					}
					.gk-block-mcp-allow-all-note .dashicons {
						color: #2271b1;
						font-size: 18px;
						width: 18px;
						height: 18px;
					}
				</style>
				<script>
					( function () {
						var fieldset = document.querySelector( '.gk-block-mcp-allowlist' );
						var note     = document.querySelector( '.gk-block-mcp-allow-all-note' );
						if ( ! fieldset || ! note ) {
							return;
						}
						var textEl        = note.querySelector( '.gk-block-mcp-allow-all-text' );
						var allowAllMsg   = <?php echo wp_json_encode( $gk_allow_all_msg ); ?>;
						var restrictedMsg = <?php echo wp_json_encode( $gk_restricted_msg ); ?>;
						var boxes = fieldset.querySelectorAll( 'input[type="checkbox"]' );
						function update() {
							var anyChecked = Array.prototype.some.call( boxes, function ( box ) {
								return box.checked;
							} );
							if ( textEl ) {
								textEl.textContent = anyChecked ? restrictedMsg : allowAllMsg;
							}
						}
						fieldset.addEventListener( 'change', update );
						update();
					} )();
				</script>


				<h2><?php esc_html_e( 'Media uploads', 'gk-block-mcp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Turn this off to stop AI assistants from adding files to your Media Library. They can still edit blocks — they just won\'t be able to upload images or other files.', 'gk-block-mcp' ); ?>
				</p>
				<?php
				// Belt-and-braces: emit '0' even when the box is unchecked so
				// update_option() reliably stores false. PHP omits unchecked
				// checkboxes entirely from $_POST, and the setting's
				// sanitize_callback would then receive nothing.
				?>
				<input type="hidden" name="<?php echo esc_attr( $uploads_option ); ?>" value="0" />
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( $uploads_option ); ?>"
						value="1"
						<?php checked( $uploads_enabled ); ?>
					/>
					<?php esc_html_e( 'Allow AI assistants to upload media', 'gk-block-mcp' ); ?>
				</label>
				<?php
				// Surface filter-driven overrides so admins aren't confused
				// by a checked box that the API still rejects.
				$option_raw = get_option( $uploads_option, '1' );
				// Applies the gk/block-mcp/media/uploads-enabled filter (documented in class-media-manager.php).
				$filtered = (bool) apply_filters(
					'gk/block-mcp/media/uploads-enabled',
					( '0' !== (string) $option_raw && false !== $option_raw )
				);
				if ( ( '0' !== (string) $option_raw && false !== $option_raw ) !== $filtered ) :
					?>
					<p class="description" style="color:#b32d2e;">
						<strong><?php esc_html_e( 'Heads up:', 'gk-block-mcp' ); ?></strong>
						<?php
						printf(
							/* translators: %s: filter name */
							esc_html__( 'A %s filter is overriding the value of this option.', 'gk-block-mcp' ),
							'<code>gk/block-mcp/media/uploads-enabled</code>'
						);
						?>
					</p>
					<?php
				endif;
				?>


				<h2><?php esc_html_e( 'Trash', 'gk-block-mcp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Turn this on to let AI assistants move posts and pages to the trash. It\'s off by default. Even when on, they can\'t permanently delete anything — trashed items stay in your Trash until you empty it, and you can restore them.', 'gk-block-mcp' ); ?>
				</p>
				<?php
				// Belt-and-braces: emit '0' even when the box is unchecked so
				// update_option() reliably stores false. PHP omits unchecked
				// checkboxes entirely from $_POST, and the setting's
				// sanitize_callback would then receive nothing.
				?>
				<input type="hidden" name="<?php echo esc_attr( $trash_option ); ?>" value="0" />
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( $trash_option ); ?>"
						value="1"
						<?php checked( $trash_enabled ); ?>
					/>
					<?php esc_html_e( 'Allow AI assistants to move posts to the trash', 'gk-block-mcp' ); ?>
				</label>
				<?php
				// Surface filter-driven overrides so admins aren't confused
				// by a box whose state the API doesn't actually honor.
				$trash_raw    = get_option( $trash_option, '0' );
				$trash_stored = ( '0' !== (string) $trash_raw && false !== $trash_raw );
				// Applies the gk/block-mcp/post/allow-trash filter (documented in class-post-manager.php).
				$trash_filtered = (bool) apply_filters( 'gk/block-mcp/post/allow-trash', $trash_stored );
				if ( $trash_stored !== $trash_filtered ) :
					?>
					<p class="description" style="color:#b32d2e;">
						<strong><?php esc_html_e( 'Heads up:', 'gk-block-mcp' ); ?></strong>
						<?php
						printf(
							/* translators: %s: filter name */
							esc_html__( 'A %s filter is overriding the value of this option.', 'gk-block-mcp' ),
							'<code>gk/block-mcp/post/allow-trash</code>'
						);
						?>
					</p>
					<?php
				endif;
				?>


				<style>
					/* Style the Advanced disclosure like a metabox header: postbox
						h2 size with a chevron that flips when the panel is open. Uses
						the core dashicons font (enqueued for this page). */
					details.gk-block-mcp-advanced { margin: 30px 0 0; }
					details.gk-block-mcp-advanced > summary {
						list-style: none;
						cursor: pointer;
						display: flex;
						align-items: center;
						justify-content: space-between;
						gap: 12px;
						padding: 12px 14px;
						background: #fff;
						border: 1px solid #c3c4c7;
						border-radius: 4px;
						color: #1d2327;
						transition: background 0.12s ease, border-color 0.12s ease;
					}
					details.gk-block-mcp-advanced > summary:hover {
						background: #f6f7f7;
						border-color: #8c8f94;
					}
					details.gk-block-mcp-advanced > summary:focus-visible {
						outline: 2px solid var(--wp-admin-theme-color, #2271b1);
						outline-offset: 1px;
					}
					.gk-block-mcp-advanced__label {
						display: block;
						font-size: 14px;
						font-weight: 600;
						line-height: 1.3;
					}
					.gk-block-mcp-advanced__desc {
						display: block;
						font-size: 12px;
						font-weight: 400;
						color: #646970;
						margin-top: 2px;
					}
					details.gk-block-mcp-advanced > summary::-webkit-details-marker { display: none; }
					details.gk-block-mcp-advanced > summary::after {
						content: "\f347";
						font-family: dashicons;
						font-size: 20px;
						line-height: 1;
						color: #50575e;
						flex: 0 0 auto;
						transition: transform 0.15s ease;
					}
					details.gk-block-mcp-advanced[open] > summary::after { transform: rotate( 180deg ); }
				</style>
				<details class="gk-block-mcp-advanced">
				<summary>
					<span class="gk-block-mcp-advanced__text">
						<span class="gk-block-mcp-advanced__label"><?php esc_html_e( 'Advanced', 'gk-block-mcp' ); ?></span>
						<span class="gk-block-mcp-advanced__desc"><?php esc_html_e( 'Custom instructions, block preferences, replacements, and blocks that store data in two places — most sites won\'t need these.', 'gk-block-mcp' ); ?></span>
					</span>
				</summary>
				<h2><?php esc_html_e( 'Custom instructions for AI assistants', 'gk-block-mcp' ); ?></h2>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: link to MCP spec, 2: max length */
							__( 'Notes that every connected AI assistant reads the moment it connects. Use them to set conventions for your site — which callout styles to use, your preferred code-block look, how documents should be structured — so you don\'t have to repeat yourself each time. Plain text, up to %2$d characters. <a href="%1$s" target="_blank" rel="noopener noreferrer">Learn more</a>.', 'gk-block-mcp' ),
							'https://modelcontextprotocol.io/specification',
							(int) $instructions_max
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</p>
				<p class="description" style="color:#b32d2e;">
					<strong><?php esc_html_e( 'Public data:', 'gk-block-mcp' ); ?></strong>
					<?php esc_html_e( 'Anyone who connects an AI assistant to your site can read this. Don\'t paste passwords, API keys, or private links here.', 'gk-block-mcp' ); ?>
				</p>
				<?php
				/*
				 * No HTML `maxlength` attribute. The browser counts UTF-16
				 * code units while the server counts UTF-8 code points
				 * (mb_strlen / Instructions::MAX_LENGTH), so a maxlength
				 * value of 2000 would block ~1000 emoji at the client
				 * even though the server would accept them. The inline
				 * JS below enforces a true code-point limit that matches
				 * the server's counter.
				 */
				?>
				<textarea
					id="gk-block-mcp-instructions"
					aria-label="<?php esc_attr_e( 'Custom instructions for AI assistants', 'gk-block-mcp' ); ?>"
					name="<?php echo esc_attr( Instructions::OPTION_KEY ); ?>"
					rows="8"
					data-max-codepoints="<?php echo esc_attr( (string) $instructions_max ); ?>"
					class="large-text code"
					placeholder="<?php esc_attr_e( "Callouts: use core/group with is-style-callout-info|warning|danger|success|note.\nCode blocks: use kevinbatdorf/code-block-pro with theme=gravitykit-dark, language=auto.\nFirst H2 of every doc should be 'Overview'.", 'gk-block-mcp' ); ?>"
				><?php echo esc_textarea( $instructions_val ); ?></textarea>
				<p class="description">
					<span id="gk-block-mcp-instructions-count"><?php echo esc_html( (string) mb_strlen( $instructions_val, 'UTF-8' ) ); ?></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: max length */
							__( '/ %d characters used. Keep it short — a few clear bullet points work better than long paragraphs.', 'gk-block-mcp' ),
							(int) $instructions_max
						)
					);
					?>
				</p>
				<script>
				(function () {
					var ta    = document.getElementById('gk-block-mcp-instructions');
					var count = document.getElementById('gk-block-mcp-instructions-count');
					if (!ta || !count) return;
					var max = parseInt(ta.getAttribute('data-max-codepoints'), 10) || 0;

					// Count Unicode code points, not UTF-16 code units, so
					// astral characters (emoji, rare CJK, math symbols)
					// match the server's mb_strlen(...) tally.
					function codePoints(s) { return Array.from(s); }

					ta.addEventListener('input', function () {
						var cps = codePoints(ta.value);
						if (max > 0 && cps.length > max) {
							ta.value = cps.slice(0, max).join('');
							cps = codePoints(ta.value);
						}
						count.textContent = String(cps.length);
					});
				})();
				</script>


				<h2><?php esc_html_e( 'Which blocks AI should prefer', 'gk-block-mcp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Give each block family a score from 0 to 100 to tell AI assistants which blocks to favor. 80 or higher = preferred, 50 or higher = fine to use, 10 or higher = discouraged (the assistant is warned), below 10 = blocked.', 'gk-block-mcp' ); ?></p>
				<table class="widefat striped gk-block-mcp-growable" data-row-prefix="gk_block_api_preferences[namespace_rows]" style="max-width: 820px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Block family', 'gk-block-mcp' ); ?></th>
							<th scope="col" style="width: 90px;"><?php esc_html_e( 'Score', 'gk-block-mcp' ); ?></th>
							<th scope="col" style="width: 100px;"><?php esc_html_e( 'Remove', 'gk-block-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$ns_keys  = array_keys( $namespace_scores );
						$ns_count = count( $ns_keys );
						for ( $ns_index = 0; $ns_index < $ns_count; $ns_index++ ) :
							$ns    = $ns_keys[ $ns_index ];
							$score = $namespace_scores[ $ns ];
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="gk-ns-name-<?php echo esc_attr( (string) $ns_index ); ?>"><?php esc_html_e( 'Block family', 'gk-block-mcp' ); ?></label>
									<input type="text" id="gk-ns-name-<?php echo esc_attr( (string) $ns_index ); ?>" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][name]" value="<?php echo esc_attr( (string) $ns ); ?>" class="large-text" data-row-trigger="1" list="gk-block-families" autocomplete="off" />
								</td>
								<td>
									<label class="screen-reader-text" for="gk-ns-score-<?php echo esc_attr( (string) $ns_index ); ?>"><?php esc_html_e( 'Score', 'gk-block-mcp' ); ?></label>
									<input type="number" id="gk-ns-score-<?php echo esc_attr( (string) $ns_index ); ?>" min="0" max="100" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][score]" value="<?php echo esc_attr( (string) (int) $score ); ?>" class="small-text" />
								</td>
								<td>
									<button type="button" class="components-button is-link is-destructive gk-block-mcp-remove-row" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: block family name */ __( 'Remove %s', 'gk-block-mcp' ), (string) $ns ) ); ?>"><?php esc_html_e( 'Remove', 'gk-block-mcp' ); ?></button>
								</td>
							</tr>
						<?php endfor; ?>
						<?php $ns_index = $ns_count; ?>
						<tr>
							<td>
								<label class="screen-reader-text" for="gk-ns-name-new"><?php esc_html_e( 'New block family', 'gk-block-mcp' ); ?></label>
								<input type="text" id="gk-ns-name-new" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][name]" placeholder="<?php esc_attr_e( 'new-namespace', 'gk-block-mcp' ); ?>" class="large-text" data-row-trigger="1" list="gk-block-families" autocomplete="off" />
							</td>
							<td>
								<label class="screen-reader-text" for="gk-ns-score-new"><?php esc_html_e( 'New score', 'gk-block-mcp' ); ?></label>
								<input type="number" id="gk-ns-score-new" min="0" max="100" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][score]" placeholder="0" class="small-text" />
							</td>
							<td></td>
						</tr>
					</tbody>
				</table>


				<h2><?php esc_html_e( 'Replacement map', 'gk-block-mcp' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When an AI assistant is blocked from using an older block, it suggests the replacement you set here. Start typing in the Replacement column to search the blocks available on your site, or type any block name.', 'gk-block-mcp' ); ?></p>
				<table class="widefat striped gk-block-mcp-growable" data-row-prefix="gk_block_api_preferences[replacement_rows]" style="max-width: 820px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Legacy block', 'gk-block-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Replacement', 'gk-block-mcp' ); ?></th>
							<th scope="col" style="width: 100px;"><?php esc_html_e( 'Remove', 'gk-block-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rm_keys  = array_keys( $replacement_map );
						$rm_count = count( $rm_keys );
						for ( $rm_index = 0; $rm_index < $rm_count; $rm_index++ ) :
							$from = $rm_keys[ $rm_index ];
							$to   = $replacement_map[ $from ];
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="gk-rm-from-<?php echo esc_attr( (string) $rm_index ); ?>"><?php esc_html_e( 'Legacy block', 'gk-block-mcp' ); ?></label>
									<input type="text" id="gk-rm-from-<?php echo esc_attr( (string) $rm_index ); ?>" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][from]" value="<?php echo esc_attr( (string) $from ); ?>" class="large-text" data-row-trigger="1" list="gk-block-names" autocomplete="off" />
								</td>
								<td>
									<label class="screen-reader-text" for="gk-rm-to-<?php echo esc_attr( (string) $rm_index ); ?>"><?php esc_html_e( 'Replacement block', 'gk-block-mcp' ); ?></label>
									<input type="text" id="gk-rm-to-<?php echo esc_attr( (string) $rm_index ); ?>" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][to]" value="<?php echo esc_attr( (string) $to ); ?>" class="large-text" list="gk-block-names" autocomplete="off" />
								</td>
								<td>
									<button type="button" class="components-button is-link is-destructive gk-block-mcp-remove-row" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: legacy block name */ __( 'Remove %s', 'gk-block-mcp' ), (string) $from ) ); ?>"><?php esc_html_e( 'Remove', 'gk-block-mcp' ); ?></button>
								</td>
							</tr>
						<?php endfor; ?>
						<?php $rm_index = $rm_count; ?>
						<tr>
							<td>
								<label class="screen-reader-text" for="gk-rm-from-new"><?php esc_html_e( 'New legacy block', 'gk-block-mcp' ); ?></label>
								<input type="text" id="gk-rm-from-new" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][from]" placeholder="<?php esc_attr_e( 'legacy/block-name', 'gk-block-mcp' ); ?>" class="large-text" data-row-trigger="1" list="gk-block-names" autocomplete="off" />
							</td>
							<td>
								<label class="screen-reader-text" for="gk-rm-to-new"><?php esc_html_e( 'New replacement', 'gk-block-mcp' ); ?></label>
								<input type="text" id="gk-rm-to-new" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][to]" placeholder="<?php esc_attr_e( 'core/block-name', 'gk-block-mcp' ); ?>" class="large-text" list="gk-block-names" autocomplete="off" />
							</td>
							<td></td>
						</tr>
					</tbody>
				</table>


				<h2><?php esc_html_e( 'Blocks that store data in two places', 'gk-block-mcp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Most blocks keep their content in one place. A few keep the same content in two places at once — and if an AI assistant updates only one of them, the block quietly breaks.', 'gk-block-mcp' ); ?>
				</p>
				<p class="description">
					<?php
					echo wp_kses(
						__( 'A common example is the Yoast FAQ block, which keeps its list of questions in two places at the same time.', 'gk-block-mcp' ),
						array( 'code' => array() )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Block MCP finds most of these automatically when it scans your site. Add any others here, one per line — assistants will then be required to update both copies together, so nothing breaks.', 'gk-block-mcp' ); ?>
				</p>
				<?php $dual_placeholder = "yoast/faq-block\nnamespace/block-name"; ?>
				<label class="screen-reader-text" for="gk-block-mcp-dual-manual"><?php esc_html_e( 'Blocks that keep the same content in two places — one block name per line', 'gk-block-mcp' ); ?></label>
				<textarea id="gk-block-mcp-dual-manual" name="<?php echo esc_attr( self::DUAL_MANUAL_OPTION ); ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr( $dual_placeholder ); ?>"><?php echo esc_textarea( implode( "\n", $manual_dual ) ); ?></textarea>
				</details>

				<p class="submit"><button type="submit" name="submit" id="gk-block-mcp-save" class="components-button is-primary"><?php esc_html_e( 'Save changes', 'gk-block-mcp' ); ?></button></p>
			</form>

			<hr class="gk-block-mcp-section-rule" />

			<h2><?php esc_html_e( 'Re-scan your blocks', 'gk-block-mcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Scans your published content to learn how each block stores its data, so AI assistants make accurate edits. This can take a while on large sites.', 'gk-block-mcp' ); ?></p>
				<?php if ( ! empty( $scan_results ) ) : ?>
				<p><strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of distinct block names persisted */
							__( 'Last scan checked %d different block type(s).', 'gk-block-mcp' ),
							count( $scan_results )
						)
					);
					?>
				</strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gk_block_api_scan_storage_modes" />
				<?php wp_nonce_field( 'gk_block_api_scan_storage_modes' ); ?>
				<button type="submit" class="components-button is-secondary"><?php esc_html_e( 'Run scan now', 'gk-block-mcp' ); ?></button>
			</form>


			<h2><?php esc_html_e( 'Reset to defaults', 'gk-block-mcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Clears everything on this page and returns Block MCP to its default settings. This can\'t be undone.', 'gk-block-mcp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all Block MCP settings? This cannot be undone.', 'gk-block-mcp' ) ); ?>');">
				<input type="hidden" name="action" value="gk_block_api_reset_defaults" />
				<?php wp_nonce_field( 'gk_block_api_reset_defaults' ); ?>
				<button type="submit" class="components-button is-secondary is-destructive"><?php esc_html_e( 'Reset to defaults', 'gk-block-mcp' ); ?></button>
			</form>

			<script>
			/* Auto-grow tables marked .gk-block-mcp-growable. When the user types
			 * into the last row's "trigger" input (data-row-trigger="1"), clone
			 * that row, blank out its values, and increment the [N] index in
			 * every input's name attribute so the form posts as a fresh entry.
			 * Announces the new row via the polite live region for screen readers. */
			(function () {
				var live = document.getElementById('gk-block-mcp-live');
				var announcement = <?php echo wp_json_encode( __( 'New row added. You can keep adding entries.', 'gk-block-mcp' ) ); ?>;
				var removedMsg   = <?php echo wp_json_encode( __( 'Row removed. Save changes to apply.', 'gk-block-mcp' ) ); ?>;

				function announce(msg) {
					if (!live) return;
					// Toggle text so the live region fires even if the message is identical.
					live.textContent = '';
					setTimeout(function () { live.textContent = msg; }, 50);
				}

				var tables = document.querySelectorAll('.gk-block-mcp-growable');
				for (var t = 0, tlen = tables.length; t < tlen; t++) {
					(function (table) {
						var tbody = table.querySelector('tbody');
						if (!tbody) return;

						var nextIdx = tbody.querySelectorAll('tr').length;

						tbody.addEventListener('input', function (e) {
							var trigger = e.target.closest('[data-row-trigger]');
							if (!trigger) return;
							var lastRow = tbody.lastElementChild;
							if (!lastRow || !lastRow.contains(trigger)) return;
							if (trigger.value === '') return;

							trigger.removeAttribute('data-row-trigger');

							var clone = lastRow.cloneNode(true);
							var idx = nextIdx++;
							var inputs = clone.querySelectorAll('input');
							for (var i = 0, ilen = inputs.length; i < ilen; i++) {
								var input = inputs[i];
								input.value = '';
								if (input.checked) input.checked = false;
								var oldId = input.getAttribute('id');
								input.removeAttribute('id');
								if (oldId) {
									var newId = 'gk-row-' + idx + '-' + i;
									input.id = newId;
									var lbl = clone.querySelector('label[for="' + oldId + '"]');
									if (lbl) { lbl.setAttribute('for', newId); }
								}
								if (input.name) {
									input.name = input.name.replace(/\[(\d+)\]/, '[' + idx + ']');
								}
							}

							var triggerCell = trigger.closest('td');
							if (triggerCell) {
								var cellIndex = Array.prototype.indexOf.call(triggerCell.parentNode.children, triggerCell);
								var newCell = clone.children[cellIndex];
								if (newCell) {
									var newTrigger = newCell.querySelector('input');
									if (newTrigger) newTrigger.setAttribute('data-row-trigger', '1');
								}
							}
							tbody.appendChild(clone);
							announce(announcement);
						});

						tbody.addEventListener('click', function (e) {
							var btn = e.target.closest('.gk-block-mcp-remove-row');
							if (!btn || !tbody.contains(btn)) return;
							e.preventDefault();
							var row = btn.closest('tr');
							if (!row) return;
							// Move focus to a neighbouring Remove button before deletion (a11y).
							var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
							var idx = rows.indexOf(row);
							var focusTarget = null, k;
							for (k = idx + 1; k < rows.length; k++) { var nb = rows[k].querySelector('.gk-block-mcp-remove-row'); if (nb) { focusTarget = nb; break; } }
							if (!focusTarget) { for (k = idx - 1; k >= 0; k--) { var pb = rows[k].querySelector('.gk-block-mcp-remove-row'); if (pb) { focusTarget = pb; break; } } }
							row.parentNode.removeChild(row);
							if (focusTarget) { focusTarget.focus(); }
							announce(removedMsg);
						});
					})(tables[t]);
				}
			})();
			</script>


			</div><!-- /.gk-tab-panel -->

			<script>
				( function () {
					var tabs   = document.querySelectorAll( '.settings_page_gk-block-mcp-settings .nav-tab-wrapper .nav-tab[data-tab]' );
					var panels = document.querySelectorAll( '.gk-tab-panel[data-tab-panel]' );
					if ( ! tabs.length || ! panels.length ) {
						return;
					}
					function activate( tab ) {
						panels.forEach( function ( panel ) {
							panel.hidden = ( panel.getAttribute( 'data-tab-panel' ) !== tab );
						} );
						tabs.forEach( function ( link ) {
							var on = link.getAttribute( 'data-tab' ) === tab;
							link.classList.toggle( 'nav-tab-active', on );
							if ( on ) {
								link.setAttribute( 'aria-current', 'page' );
							} else {
								link.removeAttribute( 'aria-current' );
							}
						} );
					}
					tabs.forEach( function ( link ) {
						link.addEventListener( 'click', function ( e ) {
							var tab = link.getAttribute( 'data-tab' );
							if ( ! tab ) {
								return;
							}
							e.preventDefault();
							activate( tab );
							if ( window.history && history.pushState ) {
								history.pushState( { gkTab: tab }, '', link.getAttribute( 'href' ) );
							}
						} );
					} );
					window.addEventListener( 'popstate', function () {
						var params = new URLSearchParams( window.location.search );
						activate( params.get( 'tab' ) || 'connect' );
					} );
				} )();
			</script>

		</div>
		<?php
	}
}
