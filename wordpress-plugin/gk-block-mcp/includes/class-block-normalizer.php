<?php
/**
 * Write-time block normalization engine.
 *
 * A generic, block-agnostic pass that runs every block in a tree through the
 * `gk/block-mcp/block/normalize` filter before it is serialized into
 * post_content. The engine itself knows nothing about any specific block —
 * each repair is a pluggable normalizer that registers on the filter (see
 * `includes/block-normalizers/`, loaded at plugin init). This mirrors the
 * read-side `gk/block-mcp/block/format` enricher framework.
 *
 * Normalizers exist because a static block's save() lives only in JavaScript:
 * PHP cannot regenerate canonical markup, and Gutenberg accepts a deprecation
 * chain of older serializations, so a normalizer must target a signature that
 * no valid serialization can produce and leave everything else byte-identical.
 * The engine guarantees the framework; each normalizer guarantees its own
 * narrow, provable repair.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Runs registered normalizers over a block tree.
 *
 * @since 2.1.0
 */
class Block_Normalizer {

	/**
	 * Normalize a block tree, depth-first.
	 *
	 * Applies the `gk/block-mcp/block/normalize` filter to every block, then
	 * recurses into inner blocks. Block count is never changed, so the
	 * `innerContent` null-placeholder invariant is preserved.
	 *
	 * @since 2.1.0
	 *
	 * @param array $blocks Block tree in WP-internal shape.
	 *
	 * @return array The normalized tree.
	 */
	public static function normalize_tree( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$blocks[ $index ] = self::normalize_block( $block );
		}
		return $blocks;
	}

	/**
	 * Normalize a single block and its descendants.
	 *
	 * @param array $block Block in WP-internal shape.
	 *
	 * @return array
	 */
	private static function normalize_block( array $block ): array {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( '' === $block_name ) {
			return $block;
		}

		/**
		 * Filters a single block on the write path so invalid markup can be
		 * repaired before it is serialized into post_content.
		 *
		 * Write-side mirror of the read-side `gk/block-mcp/block/format` hook.
		 * A normalizer receives the block in WP-internal shape (`blockName`,
		 * `attrs`, `innerHTML`, `innerContent`, `innerBlocks`) plus its name,
		 * acts only on the block(s) it understands, and returns the same shape.
		 * It must not change the block's child count.
		 *
		 * @since 2.1.0
		 *
		 * @param array  $block      The block being normalized.
		 * @param string $block_name The block's name.
		 */
		$filtered = apply_filters( 'gk/block-mcp/block/normalize', $block, $block_name );
		if ( is_array( $filtered ) ) {
			$block = $filtered;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = self::normalize_tree( $block['innerBlocks'] );
		}

		return $block;
	}
}
