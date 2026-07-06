<?php
/**
 * Core Image normalizer — repairs invalid core/image markup on the write path.
 *
 * Write-path mirror of the read-side Core_Image_Enricher. Registers on
 * `gk/block-mcp/block/normalize` and repairs one provably-invalid signature:
 * the `<img>` carries an inline `width`/`height` style but the corresponding
 * block attribute is absent. core/image's save() emits that inline style FROM
 * the block attribute, so without the attribute the editor regenerates markup
 * without the style and rejects the block as "unexpected or invalid content".
 *
 * No core/image deprecation stores a dimension as an inline style (deprecations
 * used HTML width/height attributes), so this signature is unique to
 * agent-authored markup and safe to repair. When the dimension is already a
 * block attribute (current or deprecated content) nothing is touched.
 *
 * @package GravityKit\BlockMCP\Block_Normalizers
 */

namespace GravityKit\BlockMCP\Block_Normalizers;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizer for core/image blocks.
 *
 * @since 2.1.0
 */
class Core_Image_Normalizer {

	/**
	 * Block name this normalizer targets.
	 */
	const BLOCK_NAME = 'core/image';

	/**
	 * Register the filter hook.
	 *
	 * Called once at plugin init by the normalizer loader.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gk/block-mcp/block/normalize', array( __CLASS__, 'normalize' ), 10, 2 );
	}

	/**
	 * Normalize a core/image block.
	 *
	 * Returns the block unchanged for any other block name, any block whose
	 * dimensions are already expressed as attributes, and any markup without an
	 * inline dimension style. The repair lifts the inline dimension into the
	 * block attribute (the value save() reads) and adds `is-resized` to the
	 * figure, preserving the existing inline style.
	 *
	 * @since 2.1.0
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

		$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		if ( '' === $html ) {
			return $block;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		$img_style    = self::first_tag_attribute( $html, 'IMG', 'style' );
		$style_width  = self::css_dimension( $img_style, 'width' );
		$style_height = self::css_dimension( $img_style, 'height' );

		$lifted = false;
		if ( null !== $style_width && ! isset( $attrs['width'] ) ) {
			$attrs['width'] = $style_width;
			$lifted         = true;
		}
		if ( null !== $style_height && ! isset( $attrs['height'] ) ) {
			$attrs['height'] = $style_height;
			$lifted          = true;
		}

		if ( ! $lifted ) {
			return $block;
		}

		$html = self::ensure_figure_is_resized( $html );

		$block['attrs']     = $attrs;
		$block['innerHTML'] = $html;
		if ( empty( $block['innerBlocks'] ) ) {
			$block['innerContent'] = array( $html );
		}

		return $block;
	}

	/**
	 * Add the `is-resized` class to the leading <figure>, if present and absent.
	 *
	 * Only the figure's class attribute is rewritten; the rest of the markup
	 * (notably the <img> inline style) is preserved byte-for-byte.
	 *
	 * @param string $html Image block innerHTML.
	 *
	 * @return string
	 */
	private static function ensure_figure_is_resized( $html ) {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() || 'FIGURE' !== $processor->get_tag() ) {
			return $html;
		}
		$processor->add_class( 'is-resized' );
		return $processor->get_updated_html();
	}

	/**
	 * Read an attribute off the first tag of a given name in an HTML fragment.
	 *
	 * @param string $html      HTML fragment.
	 * @param string $tag_name  Uppercase tag name (e.g. "IMG").
	 * @param string $attribute Attribute name.
	 *
	 * @return string|null The attribute value, or null if the tag/attribute is absent.
	 */
	private static function first_tag_attribute( $html, $tag_name, $attribute ) {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( $tag_name === $processor->get_tag() ) {
				$value = $processor->get_attribute( $attribute );
				return is_string( $value ) ? $value : null;
			}
		}
		return null;
	}

	/**
	 * Extract a single CSS length from an inline style string.
	 *
	 * Tolerant of whitespace around the colon and of additional declarations.
	 * Matches the property only as a whole declaration (won't match `min-width`
	 * or `max-width`).
	 *
	 * @param string|null $style Inline style attribute value.
	 * @param string      $prop  CSS property name (e.g. "width").
	 *
	 * @return string|null The trimmed value, or null when absent.
	 */
	private static function css_dimension( $style, $prop ) {
		if ( ! is_string( $style ) || '' === $style ) {
			return null;
		}
		$pattern = '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+?)\s*(?:;|$)/i';
		if ( preg_match( $pattern, $style, $matches ) ) {
			$value = trim( $matches[1] );
			return '' === $value ? null : $value;
		}
		return null;
	}
}

Core_Image_Normalizer::init();
