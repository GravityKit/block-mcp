<?php
/**
 * Constants PHPStan needs at analysis time.
 *
 * These are defined at runtime by WordPress or the plugin bootstrap; declaring
 * them here keeps the analyzer from reporting them as undefined. Values are
 * placeholders — only their existence and rough type matter to static analysis.
 *
 * @package GravityKit\BlockMCP
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wp/' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', false );
defined( 'WP_CLI' ) || define( 'WP_CLI', false );
defined( 'EMPTY_TRASH_DAYS' ) || define( 'EMPTY_TRASH_DAYS', 30 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

defined( 'GK_BLOCK_MCP_VERSION' ) || define( 'GK_BLOCK_MCP_VERSION', '2.0.0' );
defined( 'GK_BLOCK_MCP_PLUGIN_DIR' ) || define( 'GK_BLOCK_MCP_PLUGIN_DIR', __DIR__ . '/' );
defined( 'GK_BLOCK_MCP_PLUGIN_URL' ) || define( 'GK_BLOCK_MCP_PLUGIN_URL', '' );
