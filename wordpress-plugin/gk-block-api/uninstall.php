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

// Delete the post-type allow-list override (v1.2).
delete_option( 'gk_block_api_post_types_allowlist' );

// Delete the block inventory cache. Both keys are removed — the current
// `gk_block_inventory` and the legacy `gk_block_usage_stats` from before
// the Block_Inventory rename. Either may exist at uninstall time.
delete_transient( 'gk_block_inventory' );
delete_transient( 'gk_block_usage_stats' );
