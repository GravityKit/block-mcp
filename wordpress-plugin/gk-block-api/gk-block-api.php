<?php
/**
 * Plugin Name: GK Block API
 * Plugin URI: https://www.gravitykit.com
 * Description: REST API for block-level CRUD operations with smart preferences for AI agents.
 * Version: 1.4.0
 * Author: GravityKit
 * Author URI: https://www.gravitykit.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gk-block-api
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GK_BLOCK_API_VERSION', '1.4.0' );
define( 'GK_BLOCK_API_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GK_BLOCK_API_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-style autoloader for the GravityKit\BlockAPI namespace.
 *
 * Named function (rather than a closure) so WP.org Plugin Check and
 * static-analysis tools can trace registrations. Maps
 * `GravityKit\BlockAPI\Some_Class` → `includes/class-some-class.php`.
 *
 * @param string $class Fully-qualified class name being requested.
 */
function autoload( $class ) {
	$prefix   = 'GravityKit\\BlockAPI\\';
	$base_dir = GK_BLOCK_API_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

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
const CURRENT_DB_VERSION = '1.4.0';

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
	add_filter( 'gk_block_api_dual_storage_blocks', __NAMESPACE__ . '\\merge_manual_dual_storage_blocks' );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_global_filters' );

/**
 * Merge the UI-editable manual dual-storage list (set via Settings → Block
 * MCP) into the canonical list. Lives at the top level so REST, admin,
 * WP-CLI, and cron requests all see the user's UI choices.
 *
 * @param string[] $defaults
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
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
			error_log( 'GK Block API init error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\init_rest_api' );

/**
 * Settings page bootstrap. Lazy: only fires on admin requests.
 */
function init_settings_page() {
	try {
		$settings = new Settings_Page( new Block_Inventory() );
		$settings->register();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
			error_log( 'GK Block API settings init error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\init_settings_page', 0 );

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
	if ( $installed !== CURRENT_DB_VERSION ) {
		// Schema changed (or first install) — drop caches that may have
		// been written by an older version.
		delete_transient( Block_Inventory::CACHE_KEY );
		update_option( DB_VERSION_OPTION, CURRENT_DB_VERSION, false );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activation' );
