<?php
/**
 * Block safety checks for static block mutations.
 *
 * Detects when attribute changes on static WordPress blocks may leave
 * the rendered markup inconsistent. Static blocks bake their output
 * into post_content HTML; dynamic blocks render via PHP at runtime.
 * Changing attrs on a static block without updating innerHTML can
 * cause editor/frontend mismatch.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Safety
 *
 * Provides safety checks for block attribute mutations, warning when
 * changes to render-affecting attributes on static blocks may produce
 * stale markup.
 */
class Block_Safety {

	/**
	 * Attributes that are handled by the editor or block-supports
	 * system and never affect innerHTML directly.
	 *
	 * `className`, `align`, `fontFamily`, and `fontSize` are applied
	 * via wrapper CSS classes at render time by WordPress's block
	 * supports system rather than baked into innerHTML.
	 *
	 * @var string[]
	 */
	private const EDITOR_ONLY_ATTRS = array(
		'lock',
		'templateLock',
		'allowedBlocks',
		'metadata',
		'className',
		'anchor',
		'align',
		'fontFamily',
		'fontSize',
	);

	/**
	 * Check whether a block attribute mutation may leave markup inconsistent.
	 *
	 * Returns an array of warning arrays (empty if safe). Static blocks that
	 * have render-affecting attribute changes without accompanying innerHTML
	 * updates will produce a warning.
	 *
	 * @param string $block_name   Full block name (e.g., "core/paragraph").
	 * @param array  $changed_attrs List of attribute names being changed.
	 * @param bool   $has_new_html  Whether new innerHTML is also being provided.
	 *
	 * @return array[] Array of warning arrays, each containing type, block_name,
	 *                 changed_attrs, and message keys. Empty if safe.
	 */
	public function check_mutation( string $block_name, array $changed_attrs, bool $has_new_html ): array {
		// Dynamic blocks render at runtime — attribute changes are always safe.
		if ( $this->is_dynamic_block( $block_name ) ) {
			return array();
		}

		// Agent is providing updated markup — no risk of stale HTML.
		if ( $has_new_html ) {
			return array();
		}

		// Filter to only render-affecting attributes.
		$render_affecting = array_diff( $changed_attrs, self::EDITOR_ONLY_ATTRS );

		if ( empty( $render_affecting ) ) {
			return array();
		}

		return array(
			array(
				'type'          => 'static_markup_stale_risk',
				'block_name'    => $block_name,
				'changed_attrs' => array_values( $render_affecting ),
				'message'       => sprintf(
					/* translators: 1: changed attribute names, 2: block name */                    __( 'Changing render-affecting attributes (%1$s) on static block "%2$s" without updating innerHTML may leave markup inconsistent. Consider also providing innerHTML or using replace-block.', 'gk-block-api' ),
					implode( ', ', $render_affecting ),
					$block_name
				),
			),
		);
	}

	/**
	 * Check whether a block is dynamic (renders via PHP at runtime).
	 *
	 * Returns true if the block has a render callback, a render file,
	 * or is not registered (unknown blocks are treated as dynamic since
	 * their behaviour cannot be validated).
	 *
	 * @param string $block_name Full block name (e.g., "core/paragraph").
	 *
	 * @return bool True if dynamic or unknown, false if static.
	 */
	public function is_dynamic_block( string $block_name ): bool {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $block_name );

		// Unknown block — treat as dynamic (can't validate).
		if ( null === $block_type ) {
			return true;
		}

		return $block_type->is_dynamic();
	}

	/**
	 * Get the list of editor-only attributes that never affect innerHTML.
	 *
	 * Useful for external callers that need to distinguish safe vs.
	 * render-affecting attribute changes.
	 *
	 * @return string[] List of editor-only attribute names.
	 */
	public static function get_editor_only_attrs(): array {
		return self::EDITOR_ONLY_ATTRS;
	}
}
