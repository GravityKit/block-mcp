<?php
/**
 * Tests for Core_Block_Enricher (synced patterns / reusable blocks).
 *
 * The enricher attaches a `pattern_ref` field to core/block instances pointing
 * at the wp_block CPT entry the ref targets. Under render mode it also expands
 * `pattern_ref.blocks` with the pattern's formatted block tree. Pulls the
 * inline logic out of Block_Reader::format_blocks_recursive and into a
 * pluggable seam matching vip-block-data-api's block-additions/core-block.php.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_Enrichers\Core_Block_Enricher;
use GravityKit\BlockAPI\Block_CRUD;

class CoreBlockEnricherTest extends BlockApiTestCase {

	/** @var int */
	private $synced_pattern_id;

	public function set_up(): void {
		parent::set_up();
		$this->synced_pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Test Synced Pattern',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Hello from pattern</p><!-- /wp:paragraph -->',
			)
		);
	}

	public function tear_down(): void {
		if ( $this->synced_pattern_id ) {
			wp_delete_post( $this->synced_pattern_id, true );
		}
		parent::tear_down();
	}

	// ── happy path: attaches pattern_ref.id and pattern_ref.name ──────────

	public function test_enrich_attaches_pattern_ref_id_and_name() {
		$data    = array(
			'name'       => 'core/block',
			'attributes' => array( 'ref' => $this->synced_pattern_id ),
		);
		$context = array(
			'parsed_block' => array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => $this->synced_pattern_id ) ),
			'render'       => false,
		);

		$result = Core_Block_Enricher::enrich( $data, 'core/block', $context );

		$this->assertArrayHasKey( 'pattern_ref', $result );
		$this->assertSame( $this->synced_pattern_id, $result['pattern_ref']['id'] );
		$this->assertSame( 'Test Synced Pattern', $result['pattern_ref']['name'] );
	}

	// ── render mode expands pattern_ref.blocks with the pattern's tree ────

	public function test_enrich_render_mode_expands_blocks() {
		$reader_reflection = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$reader_reflection->setAccessible( true );
		$reader = $reader_reflection->getValue( $this->crud );

		$data    = array(
			'name'       => 'core/block',
			'attributes' => array( 'ref' => $this->synced_pattern_id ),
		);
		$context = array(
			'parsed_block' => array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => $this->synced_pattern_id ) ),
			'render'       => true,
			'reader'       => $reader,
		);

		$result = Core_Block_Enricher::enrich( $data, 'core/block', $context );

		$this->assertArrayHasKey( 'blocks', $result['pattern_ref'] );
		$this->assertIsArray( $result['pattern_ref']['blocks'] );
		$this->assertCount( 1, $result['pattern_ref']['blocks'] );
		$this->assertSame( 'core/paragraph', $result['pattern_ref']['blocks'][0]['name'] );
	}

	// ── non-render mode omits pattern_ref.blocks ──────────────────────────

	public function test_enrich_non_render_mode_omits_blocks() {
		$data    = array(
			'name'       => 'core/block',
			'attributes' => array( 'ref' => $this->synced_pattern_id ),
		);
		$context = array(
			'parsed_block' => array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => $this->synced_pattern_id ) ),
			'render'       => false,
		);

		$result = Core_Block_Enricher::enrich( $data, 'core/block', $context );

		$this->assertArrayNotHasKey( 'blocks', $result['pattern_ref'] );
	}

	// ── non-core/block: untouched ─────────────────────────────────────────

	public function test_enrich_skips_non_core_block() {
		$data = array(
			'name'       => 'core/paragraph',
			'attributes' => array( 'ref' => $this->synced_pattern_id ),
		);

		$result = Core_Block_Enricher::enrich( $data, 'core/paragraph', array() );

		$this->assertArrayNotHasKey( 'pattern_ref', $result );
	}

	// ── missing ref: untouched ────────────────────────────────────────────

	public function test_enrich_skips_when_ref_missing() {
		$data    = array( 'name' => 'core/block', 'attributes' => array() );
		$context = array( 'parsed_block' => array( 'blockName' => 'core/block', 'attrs' => array() ), 'render' => false );

		$result = Core_Block_Enricher::enrich( $data, 'core/block', $context );

		$this->assertArrayNotHasKey( 'pattern_ref', $result );
	}

	// ── ref targets a missing post: untouched ─────────────────────────────

	public function test_enrich_skips_when_ref_post_missing() {
		$data    = array(
			'name'       => 'core/block',
			'attributes' => array( 'ref' => 99999999 ),
		);
		$context = array(
			'parsed_block' => array( 'blockName' => 'core/block', 'attrs' => array( 'ref' => 99999999 ) ),
			'render'       => false,
		);

		$result = Core_Block_Enricher::enrich( $data, 'core/block', $context );

		$this->assertArrayNotHasKey( 'pattern_ref', $result );
	}

	// ── integration: end-to-end through get_blocks with render mode ───────

	public function test_filter_fires_via_get_blocks_render_mode() {
		$post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/block',
					'attrs'        => array(
						'ref'      => $this->synced_pattern_id,
						'metadata' => array( 'gk_ref' => 'blk_synced1' ),
					),
					'innerHTML'    => '',
					'innerContent' => array(),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->crud->get_blocks( $post_id, true );
		$this->assertIsArray( $result );
		$this->assertSame( 'core/block', $result[0]['name'] );
		$this->assertSame( $this->synced_pattern_id, $result[0]['pattern_ref']['id'] ?? null );
		$this->assertArrayHasKey( 'blocks', $result[0]['pattern_ref'] );
	}
}
