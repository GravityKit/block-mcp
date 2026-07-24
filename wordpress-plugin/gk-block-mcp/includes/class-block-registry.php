<?php
/**
 * Block type registry with preference enrichment.
 *
 * Wraps the WordPress block type registry to provide filtered, scored, and
 * usage-enriched block type listings for AI agents.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Registry
 *
 * Provides block type discovery with preference scores and usage data.
 */
class Block_Registry {

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Site-wide block inventory.
	 *
	 * @var Block_Inventory
	 */
	private $block_inventory;

	/**
	 * Constructor.
	 *
	 * @param Preferences     $preferences     Preferences instance.
	 * @param Block_Inventory $block_inventory Site-wide block inventory.
	 */
	public function __construct( Preferences $preferences, Block_Inventory $block_inventory ) {
		$this->preferences     = $preferences;
		$this->block_inventory = $block_inventory;
	}

	/**
	 * Get registered block types with optional filtering and enrichment.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $namespace        Filter by namespace (e.g., "filter", "core").
	 *     @type string $category         Filter by block category.
	 *     @type bool   $preferred_only   If true, only return blocks with score >= 50.
	 *     @type bool   $include_supports If true, include each block's full `supports` object.
	 * }
	 *
	 * @return array Array of enriched block type data.
	 */
	public function get_block_types( $args = array() ) {
		$defaults = array(
			'namespace'        => '',
			'category'         => '',
			'preferred_only'   => false,
			'tier'             => '',           // One of: preferred, acceptable, avoid, legacy.
			'storage_mode'     => '',           // One of: static, dynamic, dual.
			'search'           => '',           // Substring match against name + title.
			'usage_only'       => false,        // Only return blocks actually present on the site.
			'include_supports' => false,        // Include the full `supports` object per block.
		);

		$args = wp_parse_args( $args, $defaults );

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry ) {
			return array();
		}
		$block_types = $registry->get_all_registered();
		if ( ! is_array( $block_types ) ) {
			return array();
		}

		// Build the inverse replacement map ONCE per call (was O(N²) when
		// `get_blocks_replaced_by` ran a fresh scan per block).
		$inverse_replacement_map = array();
		foreach ( $this->preferences->get_replacement_map() as $legacy => $replacement ) {
			if ( ! isset( $inverse_replacement_map[ $replacement ] ) ) {
				$inverse_replacement_map[ $replacement ] = array();
			}
			$inverse_replacement_map[ $replacement ][] = $legacy;
		}

		// Cheap lowercase needle for search; '' falsy short-circuits below.
		$needle = '' !== $args['search'] ? strtolower( (string) $args['search'] ) : '';

		$results = array();

		foreach ( $block_types as $block_type ) {
			$name = $block_type->name;

			// Filter by namespace.
			if ( ! empty( $args['namespace'] ) ) {
				$ns = $this->preferences->extract_namespace( $name );
				if ( $args['namespace'] !== $ns ) {
					continue;
				}
			}

			// Filter by category.
			if ( ! empty( $args['category'] ) && $args['category'] !== $block_type->category ) {
				continue;
			}

			// Substring search on name + title (case-insensitive).
			if ( '' !== $needle ) {
				$title = (string) $block_type->title;
				if ( false === strpos( strtolower( $name ), $needle )
					&& false === strpos( strtolower( $title ), $needle ) ) {
					continue;
				}
			}

			// Get preference data.
			$preference = $this->preferences->get_block_score( $name );

			// Filter by preferred_only (score >= 50).
			if ( $args['preferred_only'] && $preference['score'] < 50 ) {
				continue;
			}

			// Filter by exact tier.
			if ( ! empty( $args['tier'] ) && $preference['tier'] !== $args['tier'] ) {
				continue;
			}

			// storage_mode + usage_only require the inventory; resolve once.
			$is_dynamic = null;
			if ( ! empty( $args['storage_mode'] ) ) {
				$is_dynamic = $this->block_inventory->is_block_dynamic( $name );
				$mode       = $this->block_inventory->resolve_storage_mode( $name, $is_dynamic );
				if ( $mode !== $args['storage_mode'] ) {
					continue;
				}
			}

			$usage_count = null;
			if ( $args['usage_only'] ) {
				$usage_lookup = $this->block_inventory->get_block_usage( $name );
				$usage_count  = isset( $usage_lookup['count'] ) ? (int) $usage_lookup['count'] : 0;
				if ( $usage_count <= 0 ) {
					continue;
				}
			}

			$results[] = $this->format_block_type(
				$block_type,
				$preference,
				$inverse_replacement_map,
				$is_dynamic,
				(bool) $args['include_supports']
			);
		}

		// Sort by preference score descending, then alphabetically.
		usort(
			$results,
			function ( $a, $b ) {
				$cmp = $b['preference']['score'] <=> $a['preference']['score'];
				return $cmp ? $cmp : strcmp( $a['name'], $b['name'] );
			}
		);

		return $results;
	}

	/**
	 * Format a single WP_Block_Type into an enriched array.
	 *
	 * @param \WP_Block_Type $block_type              Block type object.
	 * @param array          $preference              Preference data from Preferences::get_block_score().
	 * @param array          $inverse_replacement_map Replacement → [legacy_blocks] map (precomputed by caller).
	 * @param bool|null      $is_dynamic              Cached is-dynamic from get_block_types(); resolved here when null.
	 * @param bool           $include_supports        Whether to include the full `supports` object.
	 *
	 * @return array Enriched block type data.
	 */
	private function format_block_type( $block_type, $preference, array $inverse_replacement_map = array(), $is_dynamic = null, $include_supports = false ) {
		$name = $block_type->name;

		// Get usage data.
		$usage      = $this->block_inventory->get_block_usage( $name );
		$usage_data = array(
			'count' => isset( $usage['count'] ) ? (int) $usage['count'] : 0,
		);
		if ( isset( $usage['post_count'] ) ) {
			$usage_data['post_count'] = (int) $usage['post_count'];
		}

		// Build attributes summary. Forward `source` when the block declares
		// one — it's the structural signal that the attribute reads from
		// innerHTML at edit time (used by storage-mode classification and
		// useful for AI agents reasoning about static vs dual blocks).
		$attributes = array();
		if ( ! empty( $block_type->attributes ) && is_array( $block_type->attributes ) ) {
			foreach ( $block_type->attributes as $attr_name => $attr_config ) {
				$attr_summary = array(
					'type' => isset( $attr_config['type'] ) ? $attr_config['type'] : 'string',
				);
				if ( isset( $attr_config['default'] ) ) {
					$attr_summary['default'] = $attr_config['default'];
				}
				if ( isset( $attr_config['source'] ) && is_string( $attr_config['source'] ) ) {
					$attr_summary['source'] = $attr_config['source'];
				}
				$attributes[ $attr_name ] = $attr_summary;
			}
		}

		// Storage mode — re-uses the cached is_dynamic from the caller when
		// available so we don't double-resolve.
		if ( null === $is_dynamic ) {
			$is_dynamic = $this->block_inventory->is_block_dynamic( $name );
		}
		$storage_mode = $this->block_inventory->resolve_storage_mode( $name, $is_dynamic );

		$data = array(
			'name'         => $name,
			'title'        => $block_type->title ? $block_type->title : $name,
			'category'     => $block_type->category ? $block_type->category : '',
			'description'  => $block_type->description ? $block_type->description : '',
			'attributes'   => $attributes,
			'preference'   => $preference,
			'usage'        => $usage_data,
			'storage_mode' => $storage_mode,
		);

		// Style variations (block.json `styles` merged with anything registered
		// via `register_block_style()`), deduped by name. Omitted when the
		// block has none — keeps responses lean for the common case.
		$styles = $this->get_block_styles( $block_type );
		if ( ! empty( $styles ) ) {
			$data['styles'] = $styles;
		}

		// Nesting constraints — isset-guarded because `allowed_blocks` was
		// only added to WP_Block_Type in WordPress 6.5; older cores never
		// declare the property at all.
		if ( ! empty( $block_type->parent ) ) {
			$data['parent'] = $block_type->parent;
		}
		if ( ! empty( $block_type->ancestor ) ) {
			$data['ancestor'] = $block_type->ancestor;
		}
		if ( isset( $block_type->allowed_blocks ) && ! empty( $block_type->allowed_blocks ) ) {
			$data['allowed_blocks'] = $block_type->allowed_blocks;
		}

		// Full supports object — opt-in only (`include_supports`), since it
		// can be large and most callers only need the summarized fields above.
		if ( $include_supports && ! empty( $block_type->supports ) ) {
			$data['supports'] = $block_type->supports;
		}

		// Replacement info (if this block has a configured replacement).
		$replacement = $this->preferences->get_replacement( $name );
		if ( null !== $replacement ) {
			$data['preference']['replacement'] = $replacement;
		}

		// `replaces`: blocks that THIS block is the suggested replacement
		// for. Resolved via the precomputed inverse map (O(1) instead of
		// O(N) per block).
		if ( isset( $inverse_replacement_map[ $name ] ) ) {
			$data['replaces'] = $inverse_replacement_map[ $name ];
		}

		return $data;
	}

	/**
	 * Merge a block's declared style variations from block.json
	 * (`$block_type->styles`) with any registered via
	 * `register_block_style()` (`WP_Block_Styles_Registry`), deduped by
	 * style name.
	 *
	 * The two sources use different default-style keys — block.json uses
	 * `isDefault`, the registry uses `is_default` — normalized here to a
	 * single `is_default` boolean on the merged entry.
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Block_Type $block_type Block type object.
	 *
	 * @return array[] List of `{name, label, is_default}` entries, empty when the block has no styles.
	 */
	private function get_block_styles( \WP_Block_Type $block_type ) {
		$merged = array();

		if ( class_exists( '\WP_Block_Styles_Registry' ) ) {
			$registered = \WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( $block_type->name );
			foreach ( $registered as $style_name => $style ) {
				$merged[ $style_name ] = array(
					'name'       => $style_name,
					'label'      => isset( $style['label'] ) ? $style['label'] : $style_name,
					'is_default' => ! empty( $style['is_default'] ),
				);
			}
		}

		if ( ! empty( $block_type->styles ) && is_array( $block_type->styles ) ) {
			foreach ( $block_type->styles as $style ) {
				if ( empty( $style['name'] ) || isset( $merged[ $style['name'] ] ) ) {
					continue;
				}
				$merged[ $style['name'] ] = array(
					'name'       => $style['name'],
					'label'      => isset( $style['label'] ) ? $style['label'] : $style['name'],
					'is_default' => ! empty( $style['isDefault'] ),
				);
			}
		}

		return array_values( $merged );
	}

	/**
	 * Registered block bindings sources (block metadata.bindings can
	 * reference these to pull an attribute value from post meta, a
	 * pattern override, etc).
	 *
	 * @since 2.2.0
	 *
	 * @return array `{sources: array[]}` — each source is `{name, label, uses_context}`
	 *               (`uses_context` omitted when the source declares none). On
	 *               WordPress below 6.5, where the Block Bindings API doesn't
	 *               exist, `sources` is empty and a `note` key explains why.
	 */
	public function get_binding_sources() {
		if ( ! function_exists( 'get_all_registered_block_bindings_sources' ) ) {
			return array(
				'sources' => array(),
				'note'    => __( 'Block bindings require WordPress 6.5+.', 'gk-block-mcp' ),
			);
		}

		$sources = array();
		foreach ( get_all_registered_block_bindings_sources() as $source ) {
			$entry = array(
				'name'  => $source->name,
				'label' => $source->label,
			);
			if ( ! empty( $source->uses_context ) ) {
				$entry['uses_context'] = $source->uses_context;
			}
			$sources[] = $entry;
		}

		return array( 'sources' => $sources );
	}
}
