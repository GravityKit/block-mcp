<?php
/**
 * Core Image enricher — attaches attachment metadata to core/image blocks.
 *
 * Mirrors Automattic vip-block-data-api's block-additions/core-image.php
 * pattern. Hooks into gk/block-mcp/block/format and, for core/image blocks
 * carrying an `id`, attaches `width`, `height`, and a `sizes` map sourced
 * from wp_get_attachment_metadata(). When the block specifies a sizeSlug
 * that matches a registered intermediate size, the slug's width/height
 * override the base dimensions so agents see the size the editor renders.
 *
 * @package GravityKit\BlockMCP\Block_Enrichers
 */

namespace GravityKit\BlockMCP\Block_Enrichers;

defined( 'ABSPATH' ) || exit;

/**
 * Enricher for core/image blocks.
 */
class Core_Image_Enricher {

	/**
	 * Block name this enricher targets.
	 */
	const BLOCK_NAME = 'core/image';

	/**
	 * Register the filter hook.
	 *
	 * Called once at plugin init by the enricher loader.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gk/block-mcp/block/format', array( __CLASS__, 'enrich' ), 10, 2 );
	}

	/**
	 * Attach attachment metadata to a core/image block's attributes.
	 *
	 * Returns $data unchanged for any non-core/image block, blocks without an
	 * id, or ids that don't resolve to a populated attachment metadata record.
	 *
	 * @param array  $data       Formatted block data — the structure returned by
	 *                           Block_Reader::format_blocks_recursive().
	 * @param string $block_name Block name of the block being formatted.
	 *
	 * @return array Possibly-augmented block data.
	 */
	public static function enrich( $data, $block_name ) {
		if ( self::BLOCK_NAME !== $block_name ) {
			return $data;
		}

		$attachment_id = isset( $data['attributes']['id'] ) ? (int) $data['attributes']['id'] : 0;
		if ( empty( $attachment_id ) ) {
			return $data;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return $data;
		}

		if ( isset( $metadata['width'] ) ) {
			$data['attributes']['width'] = (int) $metadata['width'];
		}
		if ( isset( $metadata['height'] ) ) {
			$data['attributes']['height'] = (int) $metadata['height'];
		}
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$data['attributes']['sizes'] = $metadata['sizes'];
		}

		// sizeSlug override: when the editor renders an intermediate size,
		// agents care about the rendered dimensions, not the source dimensions.
		$size_slug = isset( $data['attributes']['sizeSlug'] ) ? (string) $data['attributes']['sizeSlug'] : '';
		if ( '' !== $size_slug && isset( $metadata['sizes'][ $size_slug ] ) ) {
			$size = $metadata['sizes'][ $size_slug ];
			if ( isset( $size['width'] ) ) {
				$data['attributes']['width'] = (int) $size['width'];
			}
			if ( isset( $size['height'] ) ) {
				$data['attributes']['height'] = (int) $size['height'];
			}
		}

		return $data;
	}
}

Core_Image_Enricher::init();
