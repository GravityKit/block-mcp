<?php
/**
 * Code Block Pro integration for the Block MCP.
 *
 * Hooks into gk/block-mcp/block/format to strip fields that are either
 * derived (codeHTML, highestLineNumber) or too large to be useful to an AI
 * agent (innerHTML — the full copy-button + Shiki <pre> widget HTML).
 *
 * Agents work with: code, language, theme, and display settings.
 * Everything else is computed server-side and never needs to flow through
 * the agent's context window.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'gk/block-mcp/block/format',
	function ( $data, $block_name ) {
		if ( 'kevinbatdorf/code-block-pro' !== $block_name ) {
			return $data;
		}

		// Strip derived fields — agents should never read or write these.
		unset( $data['attributes']['codeHTML'] );
		unset( $data['attributes']['highestLineNumber'] );

		// Strip innerHTML — it is the full rendered widget (copy-button SVG,
		// Shiki <pre>, and ~30 CSS variable declarations). text_preview already
		// surfaces the readable code content for block scanning.
		unset( $data['innerHTML'] );

		return $data;
	},
	10,
	2
);
