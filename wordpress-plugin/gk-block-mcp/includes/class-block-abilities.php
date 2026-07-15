<?php
/**
 * WordPress 6.9 Abilities API bridge.
 *
 * Exposes Block MCP's block-tree operations as native WordPress abilities so the
 * official MCP Adapter — and any other Abilities consumer (REST, JS, WP-CLI) —
 * can discover and invoke them. This is a thin adapter: every ability delegates
 * to the same service classes the REST layer uses, so behavior is identical
 * whichever front door a caller uses. Registration is feature-detected on the
 * Abilities API (WordPress 6.9+) and is a no-op on older cores.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Block MCP abilities with the WordPress Abilities API.
 *
 * @since 2.1.0
 */
class Block_Abilities {

	/**
	 * Ability namespace prefix (per-product, matching the text domain).
	 *
	 * @since 2.1.0
	 */
	const NAMESPACE_PREFIX = 'gk-block-mcp/';

	/**
	 * Ability category slug.
	 *
	 * @since 2.1.0
	 */
	const CATEGORY = 'gk-block-mcp';

	/**
	 * Option key for the "expose operations as WordPress Abilities" toggle.
	 *
	 * Stored as the string '0'/'1'; defaults to enabled (opt-out) when unset.
	 *
	 * @since 2.1.0
	 */
	const ENABLED_OPTION = 'gk_block_api_abilities_enabled';

	/**
	 * Whether the WordPress Abilities API is available on this site.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether registering Block MCP abilities is enabled for this site.
	 *
	 * Default off (opt-in): registration exposes the operations to the Abilities
	 * REST API and to AI agents through an MCP consumer like the MCP Adapter,
	 * a capability-gated but network-reachable surface. Expanding it is a
	 * deliberate act, so the site owner turns it on. Stored as the
	 * `gk_block_api_abilities_enabled` option (Settings → Block MCP) and
	 * filterable via `gk/block-mcp/abilities/enabled` for programmatic control.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = '0' !== (string) get_option( self::ENABLED_OPTION, '0' );

		/**
		 * Filters whether Block MCP registers its operations as WordPress
		 * Abilities (exposing them to the official MCP Adapter and the Abilities
		 * REST API). Defaults to the Settings → Block MCP toggle.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Whether registration is enabled.
		 */
		return (bool) apply_filters( 'gk/block-mcp/abilities/enabled', $enabled );
	}
}
