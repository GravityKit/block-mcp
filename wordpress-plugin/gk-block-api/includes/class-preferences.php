<?php
/**
 * Preference configuration and scoring.
 *
 * Stores block/pattern preference settings in a WP option and provides
 * scoring methods used by the registry, pattern manager, and CRUD layer.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Preferences
 *
 * Manages block and pattern preference scoring based on namespace policies,
 * recency, reference counts, and legacy block detection.
 */
class Preferences {

	/**
	 * WordPress option name for preference configuration.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'gk_block_api_preferences';

	/**
	 * Cached preferences array.
	 *
	 * @var array|null
	 */
	private $preferences = null;

	/**
	 * Default preference configuration.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'namespace_scores' => array(
				'filter'             => 100, // Theme blocks — always preferred.
				'core'               => 90,  // WordPress native.
				'gravityforms'       => 80,  // GravityKit ecosystem.
				'gk-gravitycharts'   => 80,
				'gk-gravitycalendar' => 80,
				'gravityboard'       => 80,
				'outermost'          => 60,  // Third-party, acceptable.
				'kevinbatdorf'       => 50,  // Code block pro.
				'stackable'          => 10,  // Migrate away.
				'ugb'                => 0,   // Legacy — never use.
				'jetpack'            => 0,   // Never use.
			),
			'pattern_scoring'  => array(
				'recency_bonus'        => array(
					2026 => 50,
					2025 => 30,
					2024 => 10,
				),
				'reference_multiplier' => 5,
				'no_legacy_bonus'      => 20,
				'has_legacy_penalty'   => -100,
			),
			'replacement_map'  => array(
				'stackable/heading'      => 'core/heading',
				'stackable/text'         => 'core/paragraph',
				'stackable/button'       => 'core/button',
				'stackable/button-group' => 'core/buttons',
				'stackable/columns'      => 'core/columns',
				'stackable/column'       => 'core/column',
				'stackable/image'        => 'core/image',
				'stackable/spacer'       => 'core/spacer',
				'stackable/divider'      => 'core/separator',
				'stackable/testimonial'  => 'filter/testimonial-wall',
				'stackable/accordion'    => 'filter/accordion',
				'stackable/icon'         => 'outermost/icon-block',
				'stackable/icon-label'   => 'outermost/icon-block',
				'stackable/card'         => 'core/group',
				'stackable/subtitle'     => 'core/paragraph',
				'ugb/columns'            => 'core/columns',
				'ugb/column'             => 'core/column',
				'ugb/button'             => 'core/button',
				'ugb/text'               => 'core/paragraph',
				'ugb/pricing-box'        => 'core/group',
			),
		);
	}

	/**
	 * Get the current preference configuration.
	 *
	 * Merges stored preferences with defaults so new keys are always present.
	 *
	 * @return array
	 */
	public function get_preferences() {
		if ( null === $this->preferences ) {
			$stored            = get_option( self::OPTION_KEY, array() );
			$defaults          = self::get_defaults();
			$this->preferences = wp_parse_args( $stored, $defaults );

			// Deep-merge sub-arrays.
			foreach ( array( 'namespace_scores', 'pattern_scoring', 'replacement_map' ) as $key ) {
				if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
					$this->preferences[ $key ] = wp_parse_args( $stored[ $key ], $defaults[ $key ] );
				}
			}
		}

		return $this->preferences;
	}

	/**
	 * Update the preference configuration.
	 *
	 * @param array $new_preferences Partial or full preferences to merge.
	 *
	 * @return bool True on success.
	 */
	public function update_preferences( $new_preferences ) {
		$current = $this->get_preferences();
		$merged  = wp_parse_args( $new_preferences, $current );

		// Deep-merge sub-arrays.
		foreach ( array( 'namespace_scores', 'pattern_scoring', 'replacement_map' ) as $key ) {
			if ( isset( $new_preferences[ $key ] ) && is_array( $new_preferences[ $key ] ) ) {
				$merged[ $key ] = wp_parse_args( $new_preferences[ $key ], $current[ $key ] );
			}
		}

		$result = update_option( self::OPTION_KEY, $merged );
		if ( $result ) {
			$this->preferences = $merged;
		}
		return $result;
	}

	/**
	 * Get the preference score and tier for a block type.
	 *
	 * @param string $block_name Full block name (e.g., "core/paragraph").
	 *
	 * @return array {
	 *     @type int    $score            Numeric score.
	 *     @type string $tier             One of: preferred, acceptable, avoid, legacy.
	 *     @type string $namespace_policy  Human-readable policy.
	 * }
	 */
	public function get_block_score( $block_name ) {
		if ( empty( $block_name ) || ! is_string( $block_name ) ) {
			return array(
				'score'            => 0,
				'tier'             => 'acceptable',
				'namespace_policy' => 'unknown',
			);
		}

		$prefs     = $this->get_preferences();
		$namespace = $this->extract_namespace( $block_name );
		$scores    = $prefs['namespace_scores'];

		// Check for exact namespace match, then gk-* wildcard.
		if ( isset( $scores[ $namespace ] ) ) {
			$score = $scores[ $namespace ];
		} elseif ( 0 === strpos( $namespace, 'gk-' ) ) {
			$score = 80; // GravityKit ecosystem default.
		} else {
			$score = 30; // Unknown namespace default.
		}

		return array(
			'score'            => $score,
			'tier'             => self::score_to_tier( $score ),
			'namespace_policy' => $this->get_namespace_policy( $score ),
		);
	}

	/**
	 * Get the preference score for a pattern.
	 *
	 * @param array $pattern {
	 *     Pattern data array.
	 *
	 *     @type int    $reference_count Number of pages referencing this pattern.
	 *     @type string $created         Creation date (Y-m-d or similar parseable format).
	 *     @type bool   $has_legacy      Whether the pattern contains legacy blocks.
	 * }
	 *
	 * @return array {
	 *     @type int      $score   Numeric score.
	 *     @type string   $tier    One of: preferred, acceptable, avoid, legacy.
	 *     @type string[] $reasons Array of human-readable scoring reasons.
	 * }
	 */
	public function get_pattern_score( $pattern ) {
		$prefs   = $this->get_preferences();
		$scoring = $prefs['pattern_scoring'];
		$reasons = array();

		// Reference count score.
		$ref_count = isset( $pattern['reference_count'] ) ? (int) $pattern['reference_count'] : 0;
		$ref_score = $ref_count * $scoring['reference_multiplier'];

		if ( $ref_count > 0 ) {
			$reasons[] = sprintf( '%d references (score +%d)', $ref_count, $ref_score );
		} else {
			$reasons[] = 'zero_references';
		}

		// Recency bonus.
		$year          = 0;
		$recency_bonus = 0;

		if ( ! empty( $pattern['created'] ) ) {
			$year = (int) gmdate( 'Y', strtotime( $pattern['created'] ) );
		}

		if ( $year > 0 && isset( $scoring['recency_bonus'][ $year ] ) ) {
			$recency_bonus = $scoring['recency_bonus'][ $year ];
			$reasons[]     = sprintf( 'recent (%d, +%d)', $year, $recency_bonus );
		} elseif ( $year > 0 ) {
			$reasons[] = 'old';
		}

		// Legacy bonus/penalty.
		$has_legacy    = ! empty( $pattern['has_legacy'] );
		$legacy_adjust = $has_legacy ? $scoring['has_legacy_penalty'] : $scoring['no_legacy_bonus'];

		if ( $has_legacy ) {
			$reasons[] = 'contains_legacy_blocks';
		} else {
			$reasons[] = 'no_legacy_blocks';
		}

		$score = $ref_score + $recency_bonus + $legacy_adjust;

		return array(
			'score'   => $score,
			'tier'    => self::score_to_tier( $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Get the replacement block for a legacy/avoid block.
	 *
	 * @param string $block_name Full block name.
	 *
	 * @return string|null Replacement block name, or null if no replacement defined.
	 */
	public function get_replacement( $block_name ) {
		$prefs = $this->get_preferences();

		return isset( $prefs['replacement_map'][ $block_name ] )
			? $prefs['replacement_map'][ $block_name ]
			: null;
	}

	/**
	 * Get the full replacement map.
	 *
	 * @return array Associative array of legacy => replacement block names.
	 */
	public function get_replacement_map() {
		$prefs = $this->get_preferences();

		return $prefs['replacement_map'];
	}

	/**
	 * Check whether a block name belongs to a legacy namespace.
	 *
	 * @param string $block_name Full block name.
	 *
	 * @return bool
	 */
	public function is_legacy_block( $block_name ) {
		$score_data = $this->get_block_score( $block_name );

		return in_array( $score_data['tier'], array( 'legacy', 'avoid' ), true );
	}

	/**
	 * Extract the namespace from a block name.
	 *
	 * @param string $block_name Full block name (e.g., "core/paragraph").
	 *
	 * @return string Namespace portion (e.g., "core").
	 */
	public function extract_namespace( $block_name ) {
		$parts = explode( '/', $block_name, 2 );

		return ! empty( $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Convert a numeric score to a tier label.
	 *
	 * @param int $score Numeric preference score.
	 *
	 * @return string One of: preferred, acceptable, avoid, legacy.
	 */
	public static function score_to_tier( $score ) {
		if ( $score >= 80 ) {
			return 'preferred';
		}

		if ( $score >= 50 ) {
			return 'acceptable';
		}

		if ( $score >= 10 ) {
			return 'avoid';
		}

		return 'legacy';
	}

	/**
	 * Get a human-readable namespace policy string from a score.
	 *
	 * @param int $score Numeric preference score.
	 *
	 * @return string Policy string.
	 */
	private function get_namespace_policy( $score ) {
		if ( $score >= 80 ) {
			return 'always_prefer';
		}

		if ( $score >= 50 ) {
			return 'use_if_needed';
		}

		if ( $score >= 10 ) {
			return 'migrate_away';
		}

		return 'never_use';
	}
}
