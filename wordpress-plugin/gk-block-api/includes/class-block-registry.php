<?php
/**
 * Block type registry with preference enrichment.
 *
 * Wraps the WordPress block type registry to provide filtered, scored, and
 * usage-enriched block type listings for AI agents.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

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
		$this->preferences = $preferences;
		$this->block_inventory = $block_inventory;
	}

	/**
	 * Get registered block types with optional filtering and enrichment.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $namespace      Filter by namespace (e.g., "filter", "core").
	 *     @type string $category       Filter by block category.
	 *     @type bool   $preferred_only If true, only return blocks with score >= 50.
	 * }
	 *
	 * @return array Array of enriched block type data.
	 */
	public function get_block_types( $args = array() ) {
		$defaults = array(
			'namespace'      => '',
			'category'       => '',
			'preferred_only' => false,
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
		$results = array();

		foreach ( $block_types as $block_type ) {
			$name = $block_type->name;

			// Filter by namespace.
			if ( ! empty( $args['namespace'] ) ) {
				$ns = $this->preferences->extract_namespace( $name );
				if ( $ns !== $args['namespace'] ) {
					continue;
				}
			}

			// Filter by category.
			if ( ! empty( $args['category'] ) && $block_type->category !== $args['category'] ) {
				continue;
			}

			// Get preference data.
			$preference = $this->preferences->get_block_score( $name );

			// Filter by preferred_only (score >= 50).
			if ( $args['preferred_only'] && $preference['score'] < 50 ) {
				continue;
			}

			// Build enriched block data.
			$block_data = $this->format_block_type( $block_type, $preference );

			$results[] = $block_data;
		}

		// Sort by preference score descending, then alphabetically.
		usort( $results, function ( $a, $b ) {
			return $b['preference']['score'] <=> $a['preference']['score']
				?: strcmp( $a['name'], $b['name'] );
		} );

		return $results;
	}

	/**
	 * Format a single WP_Block_Type into an enriched array.
	 *
	 * @param \WP_Block_Type $block_type Block type object.
	 * @param array          $preference Preference data from Preferences::get_block_score().
	 *
	 * @return array Enriched block type data.
	 */
	private function format_block_type( $block_type, $preference ) {
		$name = $block_type->name;

		// Get usage data.
		$usage      = $this->block_inventory->get_block_usage( $name );
		$usage_data = array(
			'count' => isset( $usage['count'] ) ? $usage['count'] : 0,
		);

		// Build attributes summary.
		$attributes = array();
		if ( ! empty( $block_type->attributes ) && is_array( $block_type->attributes ) ) {
			foreach ( $block_type->attributes as $attr_name => $attr_config ) {
				$attributes[ $attr_name ] = array(
					'type' => isset( $attr_config['type'] ) ? $attr_config['type'] : 'string',
				);

				if ( isset( $attr_config['default'] ) ) {
					$attributes[ $attr_name ]['default'] = $attr_config['default'];
				}
			}
		}

		$data = array(
			'name'        => $name,
			'title'       => $block_type->title ? $block_type->title : $name,
			'category'    => $block_type->category ? $block_type->category : '',
			'description' => $block_type->description ? $block_type->description : '',
			'attributes'  => $attributes,
			'preference'  => $preference,
			'usage'       => $usage_data,
		);

		// Add replacement info if this is an avoid/legacy block.
		$replacement = $this->preferences->get_replacement( $name );
		if ( null !== $replacement ) {
			$data['preference']['replacement'] = $replacement;
		}

		// Add replaces info: list blocks that this block replaces.
		$replaces = $this->get_blocks_replaced_by( $name );
		if ( ! empty( $replaces ) ) {
			$data['replaces'] = $replaces;
		}

		return $data;
	}

	/**
	 * Find all blocks that the given block replaces (reverse lookup of replacement_map).
	 *
	 * @param string $block_name Block name to check.
	 *
	 * @return string[] Block names that are replaced by $block_name.
	 */
	private function get_blocks_replaced_by( $block_name ) {
		$map      = $this->preferences->get_replacement_map();
		$replaces = array();

		foreach ( $map as $legacy => $replacement ) {
			if ( $replacement === $block_name ) {
				$replaces[] = $legacy;
			}
		}

		return $replaces;
	}
}
