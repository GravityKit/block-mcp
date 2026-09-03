<?php
/**
 * Shared base class for the gk-block-mcp test suite.
 *
 * Centralizes the boilerplate that every block-aware test repeats:
 *
 *  - Block_CRUD + Block_Mutator instances wired with the same
 *    Preferences / Block_Safety / HTML_Transformer / Block_Inventory
 *    collaborators (a fresh set per test).
 *  - Core block-type registration so tests don't each have to repeat
 *    `if ( ! $registry->is_registered( 'core/group' ) ) ...` rituals
 *    against the global WP_Block_Type_Registry.
 *  - Common helpers — `make_block_post()` for seeding a post with a
 *    block tree, `block_tree()` for reading it back through
 *    parse_blocks.
 *
 * Subclasses that don't need crud/mutator can still extend safely; the
 * properties are constructed lazily-via-set_up so they cost nothing
 * beyond a few object allocations per test.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Mutator;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;
use GravityKit\BlockMCP\Preferences;

abstract class BlockApiTestCase extends WP_UnitTestCase {

	/**
	 * Block_CRUD instance under test. Always set in set_up().
	 *
	 * @var Block_CRUD
	 */
	protected $crud;

	/**
	 * Block_Mutator instance under test. Always set in set_up().
	 *
	 * @var Block_Mutator
	 */
	protected $mutator;

	/**
	 * Core block names this test suite expects to be registered. Subclasses
	 * may override to extend or replace. Idempotent — the base set_up()
	 * skips names already in the registry.
	 *
	 * @return string[]
	 */
	protected function block_types_to_register(): array {
		return array(
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/list',
			'core/list-item',
			'core/columns',
			'core/column',
			'core/image',
			'core/block',
			'core/html',
			// Legacy / non-core blocks used in preference-tier coverage.
			'stackable/heading',
			'ugb/text',
		);
	}

	public function set_up(): void {
		parent::set_up();

		// Legacy/avoid tiers are admin-configured now (the shipped defaults are
		// opinion-free), so establish the namespaces the preference-tier tests
		// exercise: ugb/jetpack as legacy, stackable as avoid. Seeded before the
		// Preferences instance below reads them.
		update_option(
			Preferences::OPTION_KEY,
			array(
				'namespace_scores' => array(
					'ugb'       => 0,
					'jetpack'   => 0,
					'stackable' => 10,
				),
				'replacement_map'  => array(
					'ugb/text'          => 'core/paragraph',
					'stackable/heading' => 'core/heading',
				),
			)
		);

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( $this->block_types_to_register() as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$preferences   = new Preferences();
		$safety        = new Block_Safety();
		$transformer   = new HTML_Transformer();
		$this->crud    = new Block_CRUD( $preferences, $safety, $transformer, new Block_Inventory() );
		$this->mutator = new Block_Mutator( $this->crud, $preferences );
	}

	/**
	 * Create a real post seeded with the given block tree. Returns the
	 * factory-assigned post ID.
	 *
	 * @param array<int, array> $blocks Block tree in WP-internal shape.
	 * @param array<string, mixed> $extra Extra wp_insert_post args (merged
	 *                                    over the defaults).
	 *
	 * @return int
	 */
	protected function make_block_post( array $blocks = array(), array $extra = array() ): int {
		$defaults = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Test Post',
			'post_content' => serialize_blocks( $blocks ),
		);
		return self::factory()->post->create( array_merge( $defaults, $extra ) );
	}

	/**
	 * Read the current block tree for the named post, as parse_blocks
	 * would return it — i.e., the canonical post-round-trip shape.
	 *
	 * @param int $post_id
	 *
	 * @return array<int, array>
	 */
	protected function block_tree( int $post_id ): array {
		return parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Convenience: same as block_tree() but filters out empty / freeform
	 * blocks (whitespace-only siblings that parse_blocks materializes).
	 *
	 * @param int $post_id
	 *
	 * @return array<int, array>
	 */
	protected function block_tree_visible( int $post_id ): array {
		return array_values( array_filter(
			$this->block_tree( $post_id ),
			static fn( $b ) => null !== ( $b['blockName'] ?? null )
		) );
	}
}
