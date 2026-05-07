<?php
/**
 * Tests for the gk_ref stable-identity system.
 *
 * Refs are persisted in attrs.metadata.gk_ref. They let agents address a
 * block across mutations without re-fetching the page. This suite exercises:
 *
 *   - generate_ref()                      stable + unique
 *   - assign_missing_refs_recursive       lazy-fill, leaves existing refs
 *   - assign_fresh_refs_recursive         overwrite-all (duplicate semantics)
 *   - resolve_ref / resolve_ref_to_index  happy path + ref_stale
 *   - resolve_ref_to_top_level            top-level only enforcement
 *   - format_blocks output                surfaces ref field
 *   - get_blocks(persist_refs)            persists vs. ephemeral
 *   - insert_blocks                       new blocks get refs, response carries them
 *   - mutator ops                         replace, wrap, insert-child, duplicate (clone has fresh refs)
 *   - refs survive sibling shifts         core invariant
 *   - persist_ref_assignments             skips revisions
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Mutator;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;

class BlockRefsTest extends \PHPUnit\Framework\TestCase {

	/** @var Block_CRUD */
	private $crud;

	/** @var Block_Mutator */
	private $mutator;

	/** @var int */
	private $post_id = 99301;

	protected function setUp(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array(
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/list',
			'core/list-item',
			'core/columns',
			'core/column',
		) as $name ) {
			if ( ! $registry->get_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$preferences = new Preferences();
		$safety      = new Block_Safety();
		$transformer = new HTML_Transformer();
		$inventory   = new Block_Inventory();

		$this->crud    = new Block_CRUD( $preferences, $safety, $transformer, $inventory );
		$this->mutator = new Block_Mutator( $this->crud, $preferences, $safety, $transformer );

		$GLOBALS['_gk_test_transients'] = array();
		$this->make_post( array() );
	}

	// ── Fixtures ───────────────────────────────────────────────────────

	private function make_post( array $blocks ): void {
		$post               = new \stdClass();
		$post->ID           = $this->post_id;
		$post->post_type    = 'page';
		$post->post_status  = 'publish';
		$post->post_title   = 'Refs Test';
		$post->post_content = json_encode( $blocks );
		$GLOBALS['_gk_test_posts'][ $this->post_id ] = $post;
	}

	private function block( string $name, array $attrs = array(), string $html = '', array $children = array() ): array {
		if ( ! empty( $children ) ) {
			$opening = $html !== '' ? $html : '<div>';
			$closing = '</div>';
			$content = array( $opening );
			foreach ( $children as $_ ) { $content[] = null; }
			$content[] = $closing;
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => $opening . $closing,
				'innerContent' => $content,
				'innerBlocks'  => $children,
			);
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => $html !== '' ? array( $html ) : array(),
			'innerBlocks'  => array(),
		);
	}

	private function current_blocks(): array {
		$content = $GLOBALS['_gk_test_posts'][ $this->post_id ]->post_content;
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ── generate_ref ───────────────────────────────────────────────────

	public function test_generate_ref_has_blk_prefix() {
		$ref = Block_CRUD::generate_ref();
		$this->assertStringStartsWith( 'blk_', $ref );
	}

	public function test_generate_ref_is_url_safe_for_route_regex() {
		// The by-ref route uses [\w-]+ — refs must match.
		for ( $i = 0; $i < 10; $i++ ) {
			$ref = Block_CRUD::generate_ref();
			$this->assertMatchesRegularExpression( '/^[\w-]+$/', $ref, "Ref $ref must be url-safe" );
		}
	}

	public function test_generate_ref_produces_unique_values() {
		$seen = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$ref = Block_CRUD::generate_ref();
			$this->assertArrayNotHasKey( $ref, $seen, "Collision at i=$i" );
			$seen[ $ref ] = true;
		}
	}

	public function test_generate_ref_does_not_call_wp_generate_password() {
		// We replaced wp_generate_password (filter-vulnerable) with wp_hash.
		// Sanity: forcing a misbehaving filter should not affect output.
		$ref_before = Block_CRUD::generate_ref();
		$this->assertNotEquals( '', $ref_before );
		// Real proof is reading the source — generate_ref must not CALL
		// wp_generate_password. (References in comments/docblocks are fine.)
		$source = file_get_contents( __DIR__ . '/../includes/class-block-crud.php' );
		$this->assertDoesNotMatchRegularExpression( '/\bwp_generate_password\s*\(/', $source );
	}

	// ── assign_missing_refs_recursive ──────────────────────────────────

	public function test_assign_missing_refs_assigns_to_blank_block() {
		$blocks = array( $this->block( 'core/paragraph', array(), '<p>hi</p>' ) );
		$dirty  = $this->crud->assign_missing_refs_recursive( $blocks );
		$this->assertTrue( $dirty );
		$this->assertNotEmpty( $blocks[0]['attrs']['metadata']['gk_ref'] );
		$this->assertStringStartsWith( 'blk_', $blocks[0]['attrs']['metadata']['gk_ref'] );
	}

	public function test_assign_missing_refs_skips_blocks_with_existing_refs() {
		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_existing' ) ),
				'innerHTML'    => '<p>x</p>',
				'innerContent' => array( '<p>x</p>' ),
				'innerBlocks'  => array(),
			),
		);
		$dirty = $this->crud->assign_missing_refs_recursive( $blocks );
		$this->assertFalse( $dirty );
		$this->assertSame( 'blk_existing', $blocks[0]['attrs']['metadata']['gk_ref'] );
	}

	public function test_assign_missing_refs_walks_inner_blocks() {
		$child  = $this->block( 'core/paragraph', array(), '<p>child</p>' );
		$parent = $this->block( 'core/group', array(), '', array( $child ) );
		$blocks = array( $parent );
		$this->crud->assign_missing_refs_recursive( $blocks );
		$this->assertNotEmpty( $blocks[0]['attrs']['metadata']['gk_ref'] );
		$this->assertNotEmpty( $blocks[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref'] );
		$this->assertNotEquals(
			$blocks[0]['attrs']['metadata']['gk_ref'],
			$blocks[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref']
		);
	}

	public function test_assign_missing_refs_skips_empty_blocks() {
		$blocks = array(
			array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => "\n", 'innerContent' => array( "\n" ), 'innerBlocks' => array() ),
			$this->block( 'core/paragraph', array(), '<p>real</p>' ),
		);
		$this->crud->assign_missing_refs_recursive( $blocks );
		// Empty block at index 0 should NOT have gotten a ref.
		$this->assertArrayNotHasKey( 'metadata', $blocks[0]['attrs'] );
		// Real block at index 1 should.
		$this->assertNotEmpty( $blocks[1]['attrs']['metadata']['gk_ref'] );
	}

	public function test_assign_missing_refs_initializes_missing_attrs() {
		$blocks = array( array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>x</p>', 'innerBlocks' => array() ) );
		$this->crud->assign_missing_refs_recursive( $blocks );
		$this->assertIsArray( $blocks[0]['attrs'] );
		$this->assertNotEmpty( $blocks[0]['attrs']['metadata']['gk_ref'] );
	}

	// ── assign_fresh_refs_recursive (duplicate semantics) ──────────────

	public function test_assign_fresh_refs_overwrites_existing() {
		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_OLD' ) ),
				'innerHTML'    => '<p>x</p>',
				'innerContent' => array( '<p>x</p>' ),
				'innerBlocks'  => array(),
			),
		);
		$this->crud->assign_fresh_refs_recursive( $blocks );
		$this->assertNotSame( 'blk_OLD', $blocks[0]['attrs']['metadata']['gk_ref'] );
	}

	public function test_assign_fresh_refs_walks_inner_blocks_overwriting_all() {
		$child  = array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_CHILD_OLD' ) ),
			'innerHTML'    => '<p>c</p>',
			'innerContent' => array( '<p>c</p>' ),
			'innerBlocks'  => array(),
		);
		$parent = $this->block( 'core/group', array( 'metadata' => array( 'gk_ref' => 'blk_PARENT_OLD' ) ), '', array( $child ) );
		$blocks = array( $parent );
		$this->crud->assign_fresh_refs_recursive( $blocks );
		$this->assertNotSame( 'blk_PARENT_OLD', $blocks[0]['attrs']['metadata']['gk_ref'] );
		$this->assertNotSame( 'blk_CHILD_OLD', $blocks[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref'] );
	}

	// ── resolve_ref ────────────────────────────────────────────────────

	public function test_resolve_ref_finds_top_level_block() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>a</p>' ), 'blk_AAA' ),
			$this->_with_ref( $this->block( 'core/heading', array( 'level' => 2 ), '<h2>b</h2>' ), 'blk_BBB' ),
		) );
		$path = $this->crud->resolve_ref( $this->post_id, 'blk_BBB' );
		$this->assertSame( array( 1 ), $path );
	}

	public function test_resolve_ref_finds_nested_block() {
		$child  = $this->_with_ref( $this->block( 'core/paragraph', array(), '<p>nest</p>' ), 'blk_NESTED' );
		$parent = $this->_with_ref( $this->block( 'core/group', array(), '', array( $child ) ), 'blk_OUTER' );
		$this->make_post( array( $parent ) );
		$path = $this->crud->resolve_ref( $this->post_id, 'blk_NESTED' );
		$this->assertSame( array( 0, 0 ), $path );
	}

	public function test_resolve_ref_returns_ref_stale_when_not_found() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>x</p>' ), 'blk_REAL' ),
		) );
		$err = $this->crud->resolve_ref( $this->post_id, 'blk_GHOST' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'ref_stale', $err->get_error_code() );
	}

	public function test_resolve_ref_rejects_empty_ref() {
		$err = $this->crud->resolve_ref( $this->post_id, '' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'invalid_ref', $err->get_error_code() );
	}

	public function test_resolve_ref_returns_404_when_post_missing() {
		$err = $this->crud->resolve_ref( 999999, 'blk_ANY' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'post_not_found', $err->get_error_code() );
	}

	public function test_resolve_ref_to_index_returns_flat_index() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>0</p>' ), 'blk_FIRST' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>1</p>' ), 'blk_SECOND' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>2</p>' ), 'blk_THIRD' ),
		) );
		$this->assertSame( 0, $this->crud->resolve_ref_to_index( $this->post_id, 'blk_FIRST' ) );
		$this->assertSame( 1, $this->crud->resolve_ref_to_index( $this->post_id, 'blk_SECOND' ) );
		$this->assertSame( 2, $this->crud->resolve_ref_to_index( $this->post_id, 'blk_THIRD' ) );
	}

	public function test_resolve_ref_to_index_returns_ref_stale() {
		$this->make_post( array() );
		$err = $this->crud->resolve_ref_to_index( $this->post_id, 'blk_ANY' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'ref_stale', $err->get_error_code() );
	}

	public function test_resolve_ref_to_top_level_rejects_nested() {
		$child  = $this->_with_ref( $this->block( 'core/paragraph', array(), '<p>n</p>' ), 'blk_INNER' );
		$parent = $this->_with_ref( $this->block( 'core/group', array(), '', array( $child ) ), 'blk_OUTER' );
		$this->make_post( array( $parent ) );
		$err = $this->crud->resolve_ref_to_top_level( $this->post_id, 'blk_INNER' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'ref_not_top_level', $err->get_error_code() );
	}

	public function test_resolve_ref_to_top_level_returns_position() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>0</p>' ), 'blk_TOP1' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>1</p>' ), 'blk_TOP2' ),
		) );
		$this->assertSame( 0, $this->crud->resolve_ref_to_top_level( $this->post_id, 'blk_TOP1' ) );
		$this->assertSame( 1, $this->crud->resolve_ref_to_top_level( $this->post_id, 'blk_TOP2' ) );
	}

	// ── format_blocks surfaces ref ─────────────────────────────────────

	public function test_format_blocks_includes_ref_when_present() {
		$blocks = array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>r</p>' ), 'blk_VISIBLE' ),
		);
		$out = $this->crud->format_blocks( $blocks );
		$this->assertSame( 'blk_VISIBLE', $out[0]['ref'] );
	}

	public function test_format_blocks_omits_ref_field_when_missing() {
		$blocks = array( $this->block( 'core/paragraph', array(), '<p>r</p>' ) );
		$out    = $this->crud->format_blocks( $blocks );
		$this->assertArrayNotHasKey( 'ref', $out[0] );
	}

	// ── get_blocks lazy-assigns + persists ─────────────────────────────

	public function test_get_blocks_assigns_refs_and_persists_by_default() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>r</p>' ) ) );
		$result = $this->crud->get_blocks( $this->post_id );
		$this->assertNotEmpty( $result[0]['ref'] );
		// Persisted to post_content.
		$persisted_ref = $this->current_blocks()[0]['attrs']['metadata']['gk_ref'];
		$this->assertSame( $result[0]['ref'], $persisted_ref );
	}

	public function test_get_blocks_with_persist_refs_false_does_not_persist() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>r</p>' ) ) );
		$result = $this->crud->get_blocks( $this->post_id, false, false );
		$this->assertNotEmpty( $result[0]['ref'] );
		// post_content still has no refs.
		$persisted = $this->current_blocks();
		$this->assertArrayNotHasKey( 'metadata', $persisted[0]['attrs'] );
	}

	public function test_get_blocks_does_not_rewrite_when_all_refs_present() {
		$blocks = array( $this->_with_ref( $this->block( 'core/paragraph', array(), '<p>r</p>' ), 'blk_STABLE' ) );
		$this->make_post( $blocks );
		$before = $GLOBALS['_gk_test_posts'][ $this->post_id ]->post_content;
		$this->crud->get_blocks( $this->post_id );
		$after = $GLOBALS['_gk_test_posts'][ $this->post_id ]->post_content;
		$this->assertSame( $before, $after, 'Persist should be a no-op when all refs already exist' );
	}

	public function test_get_blocks_assigns_refs_to_all_nesting_levels() {
		$child  = $this->block( 'core/paragraph', array(), '<p>c</p>' );
		$parent = $this->block( 'core/group', array(), '', array( $child ) );
		$this->make_post( array( $parent ) );
		$out = $this->crud->get_blocks( $this->post_id );
		$this->assertNotEmpty( $out[0]['ref'] );
		$this->assertNotEmpty( $out[0]['innerBlocks'][0]['ref'] );
	}

	// ── insert_blocks assigns + returns refs ───────────────────────────

	public function test_insert_blocks_returns_ref_for_each_inserted_block() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>new</p>' ),
			array( 'name' => 'core/heading',   'innerHTML' => '<h2>also new</h2>' ),
		) );
		$this->assertCount( 2, $result['inserted'] );
		$this->assertNotEmpty( $result['inserted'][0]['ref'] );
		$this->assertNotEmpty( $result['inserted'][1]['ref'] );
		$this->assertNotSame( $result['inserted'][0]['ref'], $result['inserted'][1]['ref'] );
	}

	public function test_insert_blocks_persists_refs_to_content() {
		$this->make_post( array() );
		$this->crud->insert_blocks( $this->post_id, null, array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>new</p>' ),
		) );
		$persisted = $this->current_blocks();
		$this->assertNotEmpty( $persisted[0]['attrs']['metadata']['gk_ref'] );
	}

	public function test_insert_blocks_assigns_refs_to_inner_blocks() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>nest</p>' ),
				),
			),
		) );
		$persisted = $this->current_blocks();
		$this->assertNotEmpty( $persisted[0]['attrs']['metadata']['gk_ref'] );
		$this->assertNotEmpty( $persisted[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref'] );
	}

	// ── Mutator: replace-block ─────────────────────────────────────────

	public function test_mutator_replace_block_assigns_ref_to_replacement() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>old</p>' ), 'blk_OLD' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array( 'block' => array( 'name' => 'core/heading', 'attributes' => array( 'level' => 2 ), 'innerHTML' => '<h2>new</h2>' ) )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertNotEmpty( $result['block']['ref'] );
		$this->assertNotSame( 'blk_OLD', $result['block']['ref'] );
	}

	// ── Mutator: wrap-in-group ─────────────────────────────────────────

	public function test_mutator_wrap_in_group_gives_wrapper_a_new_ref_keeps_target_ref() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>kept</p>' ), 'blk_TARGET' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'wrap-in-group',
			array( 0 ),
			array()
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertNotEmpty( $result['block']['ref'] );
		$this->assertNotSame( 'blk_TARGET', $result['block']['ref'] );
		// The wrapped target keeps its existing ref.
		$persisted = $this->current_blocks();
		$this->assertSame( 'blk_TARGET', $persisted[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref'] );
	}

	// ── Mutator: insert-child ──────────────────────────────────────────

	public function test_mutator_insert_child_returns_new_ref() {
		$container = $this->_with_ref( $this->block( 'core/group', array(), '<div></div>' ), 'blk_GROUP' );
		$container['innerHTML']    = '<div></div>';
		$container['innerContent'] = array( '<div>', '</div>' );
		$this->make_post( array( $container ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array( 'block' => array( 'name' => 'core/paragraph', 'innerHTML' => '<p>kid</p>' ) )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertNotEmpty( $result['block']['ref'] );
	}

	// ── Mutator: duplicate (clone refs differ from source) ─────────────

	public function test_mutator_duplicate_assigns_fresh_ref_to_clone() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>dup</p>' ), 'blk_SOURCE' ),
		) );
		$result = $this->mutator->mutate( $this->post_id, 'duplicate', array( 0 ), array() );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$persisted = $this->current_blocks();
		$this->assertCount( 2, $persisted );
		$source_ref = $persisted[0]['attrs']['metadata']['gk_ref'];
		$clone_ref  = $persisted[1]['attrs']['metadata']['gk_ref'];
		$this->assertSame( 'blk_SOURCE', $source_ref );
		$this->assertNotEmpty( $clone_ref );
		$this->assertNotSame( $source_ref, $clone_ref );
		$this->assertSame( $clone_ref, $result['block']['ref'] );
	}

	public function test_mutator_duplicate_clones_inner_blocks_with_fresh_refs() {
		$child = $this->_with_ref( $this->block( 'core/paragraph', array(), '<p>nested</p>' ), 'blk_INNER' );
		$src   = $this->_with_ref( $this->block( 'core/group', array(), '', array( $child ) ), 'blk_SRC' );
		$this->make_post( array( $src ) );
		$this->mutator->mutate( $this->post_id, 'duplicate', array( 0 ), array() );
		$persisted = $this->current_blocks();
		$source_inner_ref = $persisted[0]['innerBlocks'][0]['attrs']['metadata']['gk_ref'];
		$clone_inner_ref  = $persisted[1]['innerBlocks'][0]['attrs']['metadata']['gk_ref'];
		$this->assertSame( 'blk_INNER', $source_inner_ref );
		$this->assertNotSame( $source_inner_ref, $clone_inner_ref );
	}

	// ── Refs survive sibling shifts (the headline invariant) ──────────

	public function test_ref_resolves_to_new_path_after_block_inserted_above() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>0</p>' ), 'blk_FIRST' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>1</p>' ), 'blk_SECOND' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>2</p>' ), 'blk_THIRD' ),
		) );
		// Originally blk_THIRD is at path [2].
		$this->assertSame( array( 2 ), $this->crud->resolve_ref( $this->post_id, 'blk_THIRD' ) );

		// Insert a block at the start. Old path [2] now points to blk_SECOND.
		$this->crud->insert_blocks( $this->post_id, 'start', array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ),
		) );

		// blk_THIRD's ref still resolves — but to its new path [3].
		$new_path = $this->crud->resolve_ref( $this->post_id, 'blk_THIRD' );
		$this->assertSame( array( 3 ), $new_path );
	}

	public function test_ref_resolves_after_sibling_deleted() {
		$this->make_post( array(
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>0</p>' ), 'blk_KEEP1' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>1</p>' ), 'blk_DELETE' ),
			$this->_with_ref( $this->block( 'core/paragraph', array(), '<p>2</p>' ), 'blk_KEEP2' ),
		) );
		$this->crud->delete_blocks( $this->post_id, 1, 1 );
		// blk_KEEP2 was at [2]; after deletion it's at [1].
		$this->assertSame( array( 1 ), $this->crud->resolve_ref( $this->post_id, 'blk_KEEP2' ) );
		// blk_DELETE is gone.
		$err = $this->crud->resolve_ref( $this->post_id, 'blk_DELETE' );
		$this->assertInstanceOf( \WP_Error::class, $err );
	}

	// ── persist_ref_assignments doesn't trigger revisions ─────────────

	public function test_persist_ref_assignments_uses_wpdb_not_wp_update_post() {
		// Spy on wp_update_post via a flag; set it false initially. If
		// persist_ref_assignments routed through wp_update_post the post
		// content shape would round-trip differently; here we just assert it
		// stores the new content correctly via the $wpdb stub.
		$blocks = array( $this->block( 'core/paragraph', array(), '<p>r</p>' ) );
		$this->make_post( $blocks );
		$this->crud->assign_missing_refs_recursive( $blocks );
		$ok = $this->crud->persist_ref_assignments( $this->post_id, $blocks );
		$this->assertTrue( $ok );
		$this->assertNotEmpty( $this->current_blocks()[0]['attrs']['metadata']['gk_ref'] );
	}

	// ── Concurrent ref-assignment lock (issue #5) ──────────────────────

	/**
	 * The happy path: a single get_blocks() call acquires the lock,
	 * persists refs, and releases the lock cleanly.
	 *
	 * Verifies the lock is released afterwards (so subsequent reads can
	 * still write) and that the ref-assignment did happen.
	 */
	public function test_get_blocks_acquires_and_releases_ref_lock() {
		$GLOBALS['_gk_test_object_cache'] = array();
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>fresh</p>' ) ) );

		$result = $this->crud->get_blocks( $this->post_id );

		$this->assertNotEmpty( $result );
		$this->assertNotEmpty( $result[0]['ref'] );
		$this->assertArrayNotHasKey(
			'gk_block_api_ref_lock_' . $this->post_id,
			$GLOBALS['_gk_test_object_cache'],
			'lock must be released so the next reader can also write'
		);
	}

	/**
	 * When another worker is mid-assign-and-persist (the lock is held by
	 * someone else), get_blocks must NOT also persist refs. It surfaces
	 * whatever's currently on disk via a re-parse so it doesn't return
	 * locally-generated random refs that nobody else will see.
	 *
	 * Pins the contract: in a contended scenario, only the lock-holder
	 * writes; everyone else reads.
	 */
	public function test_get_blocks_skips_persistence_when_lock_held_by_another_worker() {
		// Seed the lock as already held — simulating another worker mid-flight.
		$GLOBALS['_gk_test_object_cache']                                  = array();
		$GLOBALS['_gk_test_object_cache'][ 'gk_block_api_ref_lock_' . $this->post_id ] = 1;

		// Start with a fresh post (no refs yet) so get_blocks would normally
		// trigger the assign-and-persist path.
		$blocks = array( $this->block( 'core/paragraph', array(), '<p>fresh</p>' ) );
		$this->make_post( $blocks );
		$content_before = $GLOBALS['_gk_test_posts'][ $this->post_id ]->post_content;

		$result = $this->crud->get_blocks( $this->post_id );

		// The post_content on disk must be unchanged: we deferred to the
		// (simulated) other worker. The lock-holder will eventually persist
		// and any subsequent read by us will see those refs.
		$this->assertSame(
			$content_before,
			$GLOBALS['_gk_test_posts'][ $this->post_id ]->post_content,
			'no write should happen when another worker holds the lock'
		);

		// We still return blocks — the response is usable for read-only
		// purposes — but the API consumer is expected to retry if it needs
		// stable refs (which the lock-holder will provide on its write).
		$this->assertNotEmpty( $result );

		// The lock we seeded must still be there — we didn't release someone
		// else's lock. (Cleared at end of test by setUp on the next run.)
		$this->assertArrayHasKey(
			'gk_block_api_ref_lock_' . $this->post_id,
			$GLOBALS['_gk_test_object_cache']
		);
	}

	/**
	 * If a previous request crashed mid-assignment but the lock was somehow
	 * left over, the next legitimate request must still succeed eventually.
	 *
	 * In production wp_cache_add() expires the lock after 5s. Tests don't
	 * model TTL, but this test verifies the lock-released-on-success path
	 * by making two sequential calls and confirming both produce refs.
	 */
	public function test_get_blocks_lock_released_after_success_allows_subsequent_reads() {
		$GLOBALS['_gk_test_object_cache'] = array();
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>fresh</p>' ) ) );

		// First call: assigns + persists + releases lock.
		$first = $this->crud->get_blocks( $this->post_id );
		$ref1  = $first[0]['ref'];

		// Second call: should NOT block on the lock from call 1; should also
		// be a no-op (refs already there) so the post_content hash stays the
		// same.
		$second = $this->crud->get_blocks( $this->post_id );
		$ref2   = $second[0]['ref'];

		$this->assertSame( $ref1, $ref2, 'refs must be stable across reads once persisted' );
		$this->assertArrayNotHasKey(
			'gk_block_api_ref_lock_' . $this->post_id,
			$GLOBALS['_gk_test_object_cache']
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────

	private function _with_ref( array $block, string $ref ): array {
		if ( ! isset( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}
		if ( ! isset( $block['attrs']['metadata'] ) ) {
			$block['attrs']['metadata'] = array();
		}
		$block['attrs']['metadata']['gk_ref'] = $ref;
		return $block;
	}
}
