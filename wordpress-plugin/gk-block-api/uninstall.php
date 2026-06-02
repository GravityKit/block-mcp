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

// Agent_Provisioner is needed for purge(). The plugin's spl_autoload_register
// is not active during uninstall, so load the class file directly.
require_once __DIR__ . '/includes/class-agent-provisioner.php';

/**
 * Delete every plugin option / transient on the current blog.
 *
 * Called once per blog inside switch_to_blog() on multisite, and once on
 * single-site installations. Agent_Provisioner::purge() is NOT called here;
 * it requires blog context to read gk_block_api_agent_user_id and must be
 * invoked separately after this function, while still inside switch_to_blog().
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

	// Deactivation-notice transient. Normally auto-cleared on the next admin
	// page load after deactivation, but survives when the plugin is deleted
	// immediately after deactivation without a page load in between.
	delete_transient( 'gk_block_api_deactivation_notice' );

	// Per-post rate-limit transients accumulate per write activity. Sweep
	// the option table directly — there's no core helper for prefixed
	// transient deletion. Also sweeps the per-IP `instr_rl_` rate-limit
	// transients written by the public /instructions endpoint, and the
	// one-time paste-mode passwords stashed by Connect_Page.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gk_block_api_rate_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_rate_%'
			   OR option_name LIKE '_transient_gk_block_api_instr_rl_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_instr_rl_%'
			   OR option_name LIKE '_transient_gk_block_api_paste_pw_%'
			   OR option_name LIKE '_transient_timeout_gk_block_api_paste_pw_%'"
	);
}

if ( is_multisite() ) {
	$blog_ids = get_sites( array( 'fields' => 'ids' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local to uninstall script, not a global.
	foreach ( $blog_ids as $blog_id ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- $blog_id is the multisite loop variable passed to switch_to_blog(); intentional WP multisite pattern.
		switch_to_blog( $blog_id );
		gk_block_api_uninstall_blog();

		// Tear down the agent for this blog while its option scope is active.
		// gk_block_api_agent_user_id is stored blog-scoped (autoload=false), so
		// purge() must run inside switch_to_blog() to read the correct user ID.
		// purge() gates on the _gk_block_api_agent meta flag and is idempotent,
		// so subsequent blogs that share a network user will not re-delete an
		// already-removed account. wpmu_delete_user() removes the network user
		// globally on the first blog that provisioned it; the meta-guard on every
		// subsequent call makes those calls safe no-ops.
		GravityKit\BlockAPI\Agent_Provisioner::purge();

		restore_current_blog();
	}
} else {
	gk_block_api_uninstall_blog();

	// Tear down the agent service account (user + app passwords + role + option).
	// purge() is idempotent and respects the gk_block_api_remove_agent_on_uninstall
	// filter, so operators can opt out of agent deletion during reinstalls.
	GravityKit\BlockAPI\Agent_Provisioner::purge();
}
