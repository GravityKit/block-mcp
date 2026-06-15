<?php
/**
 * Plugin Name: Block MCP by GravityKit
 * Plugin URI: https://www.gravitykit.com/wordpress-block-mcp/
 * Description: Lets an AI assistant (Claude, Cursor) safely create and edit your WordPress content over the Model Context Protocol (MCP).
 * Version: 2.0.2
 * Author: GravityKit
 * Author URI: https://www.gravitykit.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gk-block-mcp
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// GravityKit Foundation (bundled, Strauss-prefixed) — auto-updates, licensing,
// i18n, TrustedLogin, loaded UI-less. Tests set GK_BLOCK_MCP_DISABLE_FOUNDATION.
if ( ! defined( 'GK_BLOCK_MCP_DISABLE_FOUNDATION' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor_prefixed/gravitykit/foundation/src/preflight_check.php';

	// Bail when the host PHP is too old or an admin disabled loading via
	// ?gk_disable_loading — Foundation surfaces the appropriate notice.
	if ( ! Foundation\should_load( __FILE__ ) ) {
		return;
	}
}

define( 'GK_BLOCK_MCP_VERSION', '2.0.2' );
define( 'GK_BLOCK_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GK_BLOCK_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-style autoloader for the GravityKit\BlockMCP namespace.
 *
 * Named function (rather than a closure) so WP.org Plugin Check and
 * static-analysis tools can trace registrations. Maps
 * `GravityKit\BlockMCP\Some_Class`               → `includes/class-some-class.php`
 * `GravityKit\BlockMCP\Block_Enrichers\Core_Foo` → `includes/block-enrichers/class-core-foo.php`
 *
 * Each sub-namespace segment becomes a directory under `includes/`, with
 * underscores converted to hyphens (mirrors the file-naming convention WP
 * coding standards expect). The final segment is the class file itself,
 * prefixed `class-`.
 *
 * @param string $class_name Fully-qualified class name being requested.
 */
function autoload( $class_name ) {
	$prefix   = 'GravityKit\\BlockMCP\\';
	$base_dir = GK_BLOCK_MCP_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, $len );
	$parts          = explode( '\\', $relative_class );
	$class_basename = array_pop( $parts );

	$sub_path = '';
	if ( ! empty( $parts ) ) {
		$sub_path = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) ) . '/';
	}

	$file = $base_dir . $sub_path . 'class-' . strtolower( str_replace( '_', '-', $class_basename ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );

/**
 * Schema-version option key. Bumped on schema changes so the activation
 * handler knows to clear stale caches / migrate options.
 */
const DB_VERSION_OPTION  = 'gk_block_api_db_version';
const CURRENT_DB_VERSION = '1.4.2';

/**
 * Always-on filter wiring.
 *
 * Runs on `plugins_loaded` so REST, admin, WP-CLI, and cron requests all
 * see the same filter graph. The Settings_Page registers its UI on
 * admin_init; the manual dual-storage list it persists must be merged
 * into the canonical filter regardless of which request type lands —
 * otherwise the setting silently does nothing for the API consumers it
 * was added for (WP P1-3).
 */
function register_global_filters() {
	add_filter( 'gk/block-mcp/block/dual-storage', __NAMESPACE__ . '\\merge_manual_dual_storage_blocks' );

	// Block-type integrations — each file registers its own gk_block_api_* filters.
	// (array) cast guards against glob() returning false on permission errors or
	// a missing directory — PHP 8+ fatals on `foreach (false)` (TypeError),
	// taking down `plugins_loaded` for every request on the site.
	foreach ( (array) glob( GK_BLOCK_MCP_PLUGIN_DIR . 'includes/integrations/*.php' ) as $integration ) {
		require_once $integration;
	}

	// Block-type enrichers — one class per block-name namespace, each calling
	// add_filter on gk/block-mcp/block/format. Pattern modeled on Automattic's
	// vip-block-data-api block-additions/ directory. Each file ends with
	// `Foo_Enricher::init();` to self-register the filter.
	foreach ( (array) glob( GK_BLOCK_MCP_PLUGIN_DIR . 'includes/block-enrichers/*.php' ) as $enricher ) {
		require_once $enricher;
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_global_filters' );

/**
 * Merge the UI-editable manual dual-storage list (set via Settings → Block
 * MCP) into the canonical list. Lives at the top level so REST, admin,
 * WP-CLI, and cron requests all see the user's UI choices.
 *
 * @param string[] $defaults Array of block names from filter pipeline.
 * @return string[]
 */
function merge_manual_dual_storage_blocks( $defaults ) {
	$manual = get_option( Settings_Page::DUAL_MANUAL_OPTION, array() );
	if ( empty( $manual ) || ! is_array( $manual ) ) {
		return $defaults;
	}
	return array_values( array_unique( array_merge( (array) $defaults, $manual ) ) );
}

/**
 * Initialize REST routes.
 */
function init_rest_api() {
	try {
		$preferences      = new Preferences();
		$block_inventory  = new Block_Inventory();
		$block_registry   = new Block_Registry( $preferences, $block_inventory );
		$pattern_manager  = new Pattern_Manager( $preferences );
		$block_safety     = new Block_Safety();
		$html_transformer = new HTML_Transformer();
		$block_crud       = new Block_CRUD( $preferences, $block_safety, $html_transformer, $block_inventory );
		$block_mutator    = new Block_Mutator( $block_crud, $preferences, $block_safety, $html_transformer );
		$post_manager     = new Post_Manager( $block_crud );
		$term_manager     = new Term_Manager();
		$media_manager    = new Media_Manager();

		$controller = new REST_Controller(
			$block_registry,
			$pattern_manager,
			$block_crud,
			$block_inventory,
			$block_mutator,
			$post_manager,
			$term_manager,
			$media_manager,
			$preferences
		);

		$controller->register_routes();

		// Yoast SEO bridge: optional add-on. Routes only register when Yoast SEO
		// is active; absent Yoast, this is a no-op. Lives in its own class so
		// gk-block-mcp stays self-contained — no mu-plugin or theme dependency.
		( new Yoast_Bridge() )->register_routes();

		// Connector credential-exchange route. Registered here (rest_api_init, NOT
		// the admin-only settings bootstrap) so it answers the connector's
		// logged-out POST. REST is used instead of admin-post.php because
		// admin-post.php is routinely 30x'd by canonical/SSL/Redirection/security
		// rules before the handler runs; /wp-json/ escapes those.
		( new Connect_Page() )->register_rest_routes();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
			error_log( 'Block MCP init error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\init_rest_api' );

/**
 * Settings page bootstrap. Admin-only via is_admin() guard.
 *
 * Hooks `plugins_loaded` (not `admin_init`) because Settings_Page::register()
 * needs to add an `admin_menu` callback, and `admin_menu` fires BEFORE
 * `admin_init` in WP's admin request lifecycle. Hooking on `admin_init`
 * registers the menu callback too late and the submenu silently never appears.
 */
function init_settings_page() {
	if ( ! is_admin() ) {
		return;
	}
	try {
		$settings = new Settings_Page( new Block_Inventory() );
		$settings->register();

		$connect = new Connect_Page();
		$connect->register();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
			error_log( 'Block MCP settings init error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_settings_page' );

/**
 * Agent identity bootstrap.
 *
 * Registers the minimal block_mcp_agent role on every request so it
 * survives multi-site role-table resets or environments where the
 * activation hook did not run (e.g. direct file drops, must-use setups).
 * Also installs the authenticate filter that blocks interactive login for
 * the service account.
 *
 * @since 2.0.0
 */
function init_agent() {
	// Priority 99 so it runs after themes/plugins register their custom post
	// types on `init` — the agent's caps are derived from every show_in_rest
	// type, so the role must be (re)asserted once those types exist.
	add_action( 'init', array( __NAMESPACE__ . '\\Agent_Provisioner', 'register_role' ), 99 );
	// Priority 30 ensures the block fires after wp_authenticate_username_password
	// (priority 20) so it intercepts both wrong-password WP_Error results and
	// correctly-authenticated WP_User objects for the service account.
	add_filter( 'authenticate', array( __NAMESPACE__ . '\\Agent_Provisioner', 'block_agent_login' ), 30, 3 );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_agent' );

/**
 * WP-CLI bootstrap. Required for any CLI command — `rest_api_init` does
 * not fire under `wp` invocations, and `admin_init` only fires for the
 * web admin context. Lazy-loads class names so plain web requests don't
 * pay for it.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_cli', 20 );
}

/**
 * Placeholder for future CLI commands. Today this is a no-op; the
 * autoloader + filter wiring above are enough to make CLI plugins (e.g.
 * a future `wp block-api scan-storage-modes` command) Just Work.
 */
function init_cli() {
	// Intentionally empty — present so adding a command later doesn't
	// require touching the bootstrap. Drop a `WP_CLI::add_command(...)`
	// call here when one ships.
}

/**
 * Activation handler. Sets the schema version, clears stale caches.
 *
 * Idempotent: safe to call repeatedly (re-activation, manual trigger).
 * Self-healing: if the schema version is missing or older than the
 * current code, the inventory transient is cleared so the new code
 * doesn't read a payload generated by an older schema.
 */
function on_activation() {
	$installed = get_option( DB_VERSION_OPTION, '' );
	if ( CURRENT_DB_VERSION !== $installed ) {
		// Schema changed (or first install) — drop caches that may have
		// been written by an older version.
		delete_transient( Block_Inventory::CACHE_KEY );
		update_option( DB_VERSION_OPTION, CURRENT_DB_VERSION, false );
	}

	Agent_Provisioner::register_role();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activation' );

if ( ! defined( 'GK_BLOCK_MCP_DISABLE_FOUNDATION' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor_prefixed/autoload.php';

	Foundation\Core::register( __FILE__, array( 'no_admin_menu' => true ) );
}
