<?php
/**
 * GK Block API uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted through the WordPress admin.
 *
 * Multisite-aware: when the plugin is network-active, every blog's option
 * scope is swept. Per-post rate-limit transients are also removed via a
 * direct DELETE (there's no `delete_transients_with_prefix` in core).
 *
 * @package GravityKit\BlockAPI
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete every plugin option / transient on the current blog.
 */
function gk_block_api_uninstall_blog() {
	delete_option( 'gk_block_api_preferences' );
	delete_option( 'gk_block_api_post_types_allowlist' );
	delete_option( 'gk_block_api_dual_storage_blocks_manual' );
	delete_option( 'gk_block_api_storage_modes' );
	delete_option( 'gk_block_api_storage_modes_last_run' );
	delete_option( 'gk_block_api_db_version' );

	// Inventory caches — both the new key and the legacy `gk_block_usage_stats`
	// from before the Block_Inventory rename.
	delete_transient( 'gk_block_inventory' );
	delete_transient( 'gk_block_usage_stats' );

	// Per-post rate-limit transients accumulate per write activity. Sweep
	// the option table directly — there's no core helper for prefixed
	// transient deletion.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gk_block_api_rate_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_rate_%'"
	);
}

if ( is_multisite() ) {
	$blog_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );
		gk_block_api_uninstall_blog();
		restore_current_blog();
	}
} else {
	gk_block_api_uninstall_blog();
}
