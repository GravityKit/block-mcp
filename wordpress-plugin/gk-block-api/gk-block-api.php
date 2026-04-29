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
 * Initialize the plugin on rest_api_init.
 */
add_action( 'rest_api_init', function () {
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
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GK Block API init error: ' . $e->getMessage() );
		}
	}
} );

/**
 * Settings page (BLOCK-12). Loaded only when an admin request lands —
 * no point spinning these up on the front-end. Uses the same Preferences
 * + Block_Inventory instances the REST init creates, just instantiated
 * lazily here since admin requests don't go through rest_api_init.
 */
add_action( 'admin_init', function () {
	try {
		$settings = new Settings_Page( new Block_Inventory() );
		$settings->register();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GK Block API settings init error: ' . $e->getMessage() );
		}
	}
}, 0 );
