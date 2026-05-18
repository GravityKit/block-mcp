<?php
/**
 * Core Block enricher — surfaces synced-pattern metadata + tree on core/block.
 *
 * Pulls the synced-pattern (wp_block CPT) expansion logic out of
 * Block_Reader::format_blocks_recursive() into a pluggable filter callback.
 * Pattern modeled on Automattic vip-block-data-api's
 * block-additions/core-block.php.
 *
 * Behaviour:
 *   - Always attaches `pattern_ref.id` and `pattern_ref.name` when the
 *     referenced wp_block CPT post exists.
 *   - Under render mode (context.render === true) also attaches
 *     `pattern_ref.blocks` — the pattern's formatted block tree — by
 *     delegating to the Reader instance passed in context.reader.
 *
 * @package GravityKit\BlockAPI\Block_Enrichers
 */

namespace GravityKit\BlockAPI\Block_Enrichers;

defined( 'ABSPATH' ) || exit;

/**
 * Enricher for core/block (synced pattern reference) blocks.
 */
class Core_Block_Enricher {

	/**
	 * Block name this enricher targets.
	 */
	const BLOCK_NAME = 'core/block';

	/**
	 * Register the filter hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gk_block_api_format_block', array( __CLASS__, 'enrich' ), 10, 3 );
	}

	/**
	 * Attach pattern_ref metadata to a core/block.
	 *
	 * @param array  $data       Formatted block data.
	 * @param string $block_name Block name being formatted.
	 * @param array  $context    Filter context — provides parsed_block, render
	 *                           flag, and the Reader instance for recursive
	 *                           pattern-block formatting.
	 *
	 * @return array Possibly-augmented block data.
	 */
	public static function enrich( $data, $block_name, $context = array() ) {
		if ( self::BLOCK_NAME !== $block_name ) {
			return $data;
		}

		// Synced patterns store the wp_block CPT post id in attrs.ref, not in
		// the sourced attributes map — so look at the raw parsed block.
		$parsed_block = isset( $context['parsed_block'] ) ? $context['parsed_block'] : array();
		$ref          = isset( $parsed_block['attrs']['ref'] ) ? (int) $parsed_block['attrs']['ref'] : 0;

		if ( empty( $ref ) ) {
			return $data;
		}

		$ref_post = get_post( $ref );
		if ( ! $ref_post ) {
			return $data;
		}

		$pattern_ref = array(
			'id'   => $ref,
			'name' => $ref_post->post_title,
		);

		$render = ! empty( $context['render'] );
		$reader = isset( $context['reader'] ) ? $context['reader'] : null;

		if ( $render && $reader && ! empty( $ref_post->post_content ) ) {
			$pattern_blocks = parse_blocks( $ref_post->post_content );
			if ( is_array( $pattern_blocks ) ) {
				// Re-enter the formatter for the pattern's own block tree.
				// Reader::format_blocks runs the same filter graph against
				// each block, so enrichers fire transitively. Cycle protection
				// (a pattern referencing itself or a sibling that loops) is
				// not enforced here — defer to a future task if observed.
				$pattern_ref['blocks'] = $reader->format_blocks( $pattern_blocks, true );
			}
		}

		$data['pattern_ref'] = $pattern_ref;

		return $data;
	}
}

Core_Block_Enricher::init();
