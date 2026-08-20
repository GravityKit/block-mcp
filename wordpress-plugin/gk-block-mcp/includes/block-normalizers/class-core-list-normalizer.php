<?php
/**
 * Core List normalizer — repairs invalid core/list markup on the write path.
 *
 * Registers on `gk/block-mcp/block/normalize` and repairs one provably-invalid
 * signature: the block carries its `<li>` items only in the deprecated `values`
 * attribute, its wrapper (`<ul>`/`<ol>`) is empty, and it has no core/list-item
 * innerBlocks. Modern core/list renders from core/list-item child blocks, so
 * this markup renders an EMPTY list on the front end.
 *
 * No valid or deprecated core/list serialization produces this: a real
 * values-based deprecation carries the `<li>` items INSIDE the wrapper in
 * innerHTML, and a modern one has core/list-item children and no `values`. The
 * repair bakes the `values` HTML into the wrapper, which both populates the
 * front end and matches core/list's values-based deprecation so Gutenberg
 * migrates it cleanly on the next edit. The `values` attribute is kept for that
 * reason, sanitized to the same markup that was baked in.
 *
 * @package GravityKit\BlockMCP\Block_Normalizers
 */

namespace GravityKit\BlockMCP\Block_Normalizers;

use GravityKit\BlockMCP\Block_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizer for core/list blocks.
 *
 * @since 2.2.1
 */
class Core_List_Normalizer {

	/**
	 * Block name this normalizer targets.
	 */
	const BLOCK_NAME = 'core/list';

	/**
	 * Register the filter hook.
	 *
	 * Called once at plugin init by the normalizer loader.
	 *
	 * @since 2.2.1
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gk/block-mcp/block/normalize', array( __CLASS__, 'normalize' ), 10, 2 );
	}

	/**
	 * Normalize a core/list block.
	 *
	 * Returns the block unchanged for any other block name, any block with
	 * core/list-item innerBlocks, any block without a non-empty `values`
	 * attribute, and any block whose wrapper already holds an `<li>`. Otherwise
	 * it bakes the `values` HTML into the wrapper, reconciling the wrapper tag
	 * with the `ordered` attribute, and keeps the block a leaf.
	 *
	 * @since 2.2.1
	 *
	 * @param array  $block      Block in WP-internal shape.
	 * @param string $block_name Block name being normalized.
	 *
	 * @return array
	 */
	public static function normalize( $block, $block_name ) {
		if ( self::BLOCK_NAME !== $block_name || ! is_array( $block ) ) {
			return $block;
		}

		// Guard: a block with child blocks is a modern, valid list.
		if ( ! empty( $block['innerBlocks'] ) ) {
			return $block;
		}

		$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$values = isset( $attrs['values'] ) && is_string( $attrs['values'] ) ? $attrs['values'] : '';

		// Guard: nothing stranded in `values`, nothing to bake.
		if ( '' === $values ) {
			return $block;
		}

		$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		// Guard: a wrapper that already holds an <li> is a real (deprecated or
		// current) serialization, not the empty-wrapper bug. This also makes a
		// second normalize pass a byte-for-byte no-op.
		$has_li = self::contains_li( $html );
		if ( $has_li ) {
			return $block;
		}

		// `values` is attribute data and normalization runs after the write
		// path's innerHTML sanitization, so baking it in raw would put whatever
		// the attribute holds into post_content as live markup. The sanitized
		// value is written back to the attribute as well: Gutenberg matches the
		// values-based deprecation by regenerating markup from `values`, so the
		// two must agree or the repaired block reads as invalid again.
		$values = Block_Writer::sanitize_inner_html( $values );

		// Guard: sanitization can reduce the fragment to markup carrying no
		// <li> at all. Splicing that into the wrapper would emit a list whose
		// children are not list items.
		if ( ! self::contains_li( $values ) ) {
			return $block;
		}

		$attrs['values'] = $values;
		$block['attrs']  = $attrs;

		$is_ordered = ! empty( $attrs['ordered'] );
		$html       = self::bake_values_into_wrapper( $html, $values, $is_ordered, $attrs );

		// The wrapper is assembled here from the incoming innerHTML plus
		// attribute data, so the composed result is sanitized as a whole before
		// it becomes post_content. Sanitization is idempotent, so the already
		// sanitized `values` fragment survives byte-for-byte and keeps agreeing
		// with the attribute written above.
		$html = Block_Writer::sanitize_inner_html( $html );

		// The block is a leaf here — a block with innerBlocks returned above —
		// so one innerContent chunk keeps the null-placeholder invariant.
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );

		return $block;
	}

	/**
	 * Locate the last closing tag for a tag name.
	 *
	 * An end tag may carry whitespace before its `>` (`</ul >` is valid), so a
	 * literal `</ul>` search misses it — the caller then splices content outside
	 * the wrapper, or leaves a mismatched closing tag behind after a rename.
	 *
	 * @param string $html HTML fragment to search.
	 * @param string $tag  Lowercase tag name.
	 *
	 * @return array|null `offset` and `length` of the last match, null when absent.
	 */
	private static function find_last_closing_tag( $html, $tag ) {
		$pattern = '#</' . preg_quote( $tag, '#' ) . '\s*>#i';
		$found   = preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE );
		if ( ! $found ) {
			return null;
		}
		$last = end( $matches[0] );
		return array(
			'offset' => $last[1],
			'length' => strlen( $last[0] ),
		);
	}

	/**
	 * Whether an HTML fragment contains an `<li>` tag.
	 *
	 * @param string $html HTML fragment.
	 *
	 * @return bool
	 */
	private static function contains_li( $html ) {
		if ( '' === $html ) {
			return false;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( 'LI' === $processor->get_tag() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bake the `values` HTML into the list wrapper.
	 *
	 * Reconciles the wrapper tag with the `ordered` attribute (emitting an
	 * `<ol>` carrying type/start/reversed when ordered, else a `<ul>`),
	 * preserves the wp-block-list class and any existing wrapper attributes, and
	 * splices the `values` fragment between the wrapper's open and close tags. A
	 * missing wrapper is built from scratch.
	 *
	 * @param string $html       The wrapper innerHTML (empty wrapper, or none).
	 * @param string $values     The `values` HTML holding the <li> items.
	 * @param bool   $is_ordered Whether the block's `ordered` attribute is truthy.
	 * @param array  $attrs      The block attributes.
	 *
	 * @return string
	 */
	private static function bake_values_into_wrapper( $html, $values, $is_ordered, $attrs ) {
		$desired_tag = $is_ordered ? 'ol' : 'ul';
		$wrapper     = self::prepare_wrapper( $html, $desired_tag, $is_ordered, $attrs );

		// Splice the values in just before the wrapper's closing tag. The
		// wrapper is empty at this point (guarded above), so there is exactly
		// one closing tag; any nested sublist inside `values` therefore lands
		// inside the wrapper rather than confusing the match.
		$close = self::find_last_closing_tag( $wrapper, $desired_tag );
		if ( null === $close ) {
			// An unclosed wrapper still carries the class and the ordered
			// attributes prepare_wrapper() applied, so close it rather than
			// rebuilding a bare opening tag and dropping them.
			return $wrapper . $values . '</' . $desired_tag . '>';
		}

		return substr( $wrapper, 0, $close['offset'] ) . $values . substr( $wrapper, $close['offset'] );
	}

	/**
	 * Produce the empty, correctly-tagged wrapper for the list.
	 *
	 * Locates the existing `<ul>`/`<ol>` (or builds one when absent), ensures
	 * the wp-block-list class, applies the ordered HTML attributes, and renames
	 * the tag to match `$desired_tag`. WP_HTML_Tag_Processor cannot rename a
	 * tag, so the swap is a bounded regex on the open tag plus a splice on the
	 * matching close tag.
	 *
	 * @param string $html        The wrapper innerHTML (empty wrapper, or none).
	 * @param string $desired_tag Lowercase target tag (`ol` or `ul`).
	 * @param bool   $is_ordered  Whether the block's `ordered` attribute is truthy.
	 * @param array  $attrs       The block attributes.
	 *
	 * @return string
	 */
	private static function prepare_wrapper( $html, $desired_tag, $is_ordered, $attrs ) {
		$processor   = new \WP_HTML_Tag_Processor( $html );
		$wrapper_tag = null;
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'UL' === $tag || 'OL' === $tag ) {
				$wrapper_tag = strtolower( $tag );
				break;
			}
		}

		if ( null === $wrapper_tag ) {
			$html        = '<' . $desired_tag . '></' . $desired_tag . '>';
			$wrapper_tag = $desired_tag;
			$processor   = new \WP_HTML_Tag_Processor( $html );
			$processor->next_tag();
		}

		$processor->add_class( 'wp-block-list' );
		if ( $is_ordered ) {
			$has_type = isset( $attrs['type'] ) && is_string( $attrs['type'] ) && '' !== $attrs['type'];
			if ( $has_type ) {
				$processor->set_attribute( 'type', $attrs['type'] );
			}
			$has_start = isset( $attrs['start'] ) && is_scalar( $attrs['start'] );
			if ( $has_start ) {
				$processor->set_attribute( 'start', (string) $attrs['start'] );
			}
			if ( ! empty( $attrs['reversed'] ) ) {
				$processor->set_attribute( 'reversed', true );
			}
		}
		$html = $processor->get_updated_html();

		if ( $wrapper_tag !== $desired_tag ) {
			$html  = preg_replace( '/<' . $wrapper_tag . '\b/i', '<' . $desired_tag, $html, 1 );
			$close = self::find_last_closing_tag( $html, $wrapper_tag );
			if ( null !== $close ) {
				$html = substr( $html, 0, $close['offset'] ) . '</' . $desired_tag . '>' . substr( $html, $close['offset'] + $close['length'] );
			}
		}

		return $html;
	}
}

Core_List_Normalizer::init();
