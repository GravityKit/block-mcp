<?php
/**
 * GK Block API uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted through the WordPress admin.
 *
 * @package GravityKit\BlockAPI
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the preferences option.
delete_option( 'gk_block_api_preferences' );

// Delete the usage stats transient.
delete_transient( 'gk_block_usage_stats' );
