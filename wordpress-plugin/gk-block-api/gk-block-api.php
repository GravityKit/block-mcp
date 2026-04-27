<?php
/**
 * Plugin Name: GK Block API
 * Plugin URI: https://www.gravitykit.com
 * Description: REST API for block-level CRUD operations with smart preferences for AI agents.
 * Version: 1.2.0
 * Author: GravityKit
 * Author URI: https://www.gravitykit.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gk-block-api
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

define( 'GK_BLOCK_API_VERSION', '1.2.0' );
define( 'GK_BLOCK_API_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GK_BLOCK_API_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload all classes from the includes/ directory.
 */
spl_autoload_register( function ( $class ) {
	$prefix    = 'GravityKit\\BlockAPI\\';
	$base_dir  = GK_BLOCK_API_PLUGIN_DIR . 'includes/';

	// Only handle classes in our namespace.
	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	// Convert class name to file path.
	$relative_class = substr( $class, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize the plugin on rest_api_init.
 */
add_action( 'rest_api_init', function () {
	try {
		$preferences      = new Preferences();
		$usage_stats      = new Usage_Stats();
		$block_registry   = new Block_Registry( $preferences, $usage_stats );
		$pattern_manager  = new Pattern_Manager( $preferences );
		$block_safety     = new Block_Safety();
		$html_transformer = new HTML_Transformer();
		$block_crud       = new Block_CRUD( $preferences, $block_safety, $html_transformer );
		$block_mutator    = new Block_Mutator( $block_crud, $preferences, $block_safety, $html_transformer );
		$post_manager     = new Post_Manager( $block_crud );
		$term_manager     = new Term_Manager();
		$media_manager    = new Media_Manager();

		$controller = new REST_Controller(
			$block_registry,
			$pattern_manager,
			$block_crud,
			$usage_stats,
			$block_mutator,
			$post_manager,
			$term_manager,
			$media_manager
		);

		$controller->register_routes();
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'GK Block API init error: ' . $e->getMessage() );
		}
	}
} );
