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
	delete_option( 'gk_block_api_uploads_enabled' );
	delete_option( 'gk_block_api_dual_storage_blocks_manual' );
	delete_option( 'gk_block_api_storage_modes' );
	delete_option( 'gk_block_api_storage_modes_last_run' );
	delete_option( 'gk_block_api_db_version' );
	delete_option( 'gk_block_api_instructions' );
	delete_option( 'gk_block_api_instructions_updated_at' );

	// Inventory caches — both the new key and the legacy `gk_block_usage_stats`
	// from before the Block_Inventory rename.
	delete_transient( 'gk_block_inventory' );
	delete_transient( 'gk_block_usage_stats' );

	// Pattern reference-count cache (Pattern_Manager::REF_COUNT_CACHE_KEY).
	delete_transient( 'gk_block_api_pattern_ref_counts' );

	// Per-post rate-limit transients accumulate per write activity. Sweep
	// the option table directly — there's no core helper for prefixed
	// transient deletion. Also sweeps the per-IP `instr_rl_` rate-limit
	// transients written by the public /instructions endpoint.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gk_block_api_rate_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_rate_%'
			   OR option_name LIKE '_transient_gk_block_api_instr_rl_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_instr_rl_%'"
	);
}

if ( is_multisite() ) {
	$blog_ids = get_sites( array( 'fields' => 'ids' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local to uninstall script, not a global.
	foreach ( $blog_ids as $blog_id ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $blog_id is the multisite loop variable passed to switch_to_blog(); intentional WP multisite pattern.
		switch_to_blog( $blog_id );
		gk_block_api_uninstall_blog();
		restore_current_blog();
	}
} else {
	gk_block_api_uninstall_blog();
}
