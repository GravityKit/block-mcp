<?php
/**
 * Tests for Core_Image_Enricher.
 *
 * The enricher attaches `width`, `height`, and `sizes` to a core/image block's
 * attributes by looking up the attachment ID's metadata. Mirrors
 * vip-block-data-api's block-additions/core-image.php pattern.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_Enrichers\Core_Image_Enricher;

class CoreImageEnricherTest extends BlockApiTestCase {

	/** @var int */
	private $attachment_id;

	public function set_up(): void {
		parent::set_up();
		$this->attachment_id = $this->make_image_attachment( 800, 600 );
	}

	public function tear_down(): void {
		if ( $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		parent::tear_down();
	}

	// ── happy path: attaches width + height from attachment metadata ──────

	public function test_enrich_attaches_width_and_height() {
		$data = array(
			'name'       => 'core/image',
			'attributes' => array( 'id' => $this->attachment_id ),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/image' );

		$this->assertSame( 800, $result['attributes']['width'] ?? null );
		$this->assertSame( 600, $result['attributes']['height'] ?? null );
	}

	// ── attaches `sizes` map for available image sizes ────────────────────

	public function test_enrich_attaches_sizes_map() {
		$data = array(
			'name'       => 'core/image',
			'attributes' => array( 'id' => $this->attachment_id ),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/image' );

		$this->assertArrayHasKey( 'sizes', $result['attributes'] );
		$this->assertIsArray( $result['attributes']['sizes'] );
	}

	// ── sizeSlug overrides the base width/height with the slug's size ─────

	public function test_enrich_uses_size_slug_when_present() {
		// Force a known thumbnail size on the attachment.
		$metadata = wp_get_attachment_metadata( $this->attachment_id );
		$metadata['sizes']['custom-test'] = array(
			'file'   => 'image-200x150.png',
			'width'  => 200,
			'height' => 150,
		);
		wp_update_attachment_metadata( $this->attachment_id, $metadata );

		$data = array(
			'name'       => 'core/image',
			'attributes' => array(
				'id'       => $this->attachment_id,
				'sizeSlug' => 'custom-test',
			),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/image' );

		$this->assertSame( 200, $result['attributes']['width'] );
		$this->assertSame( 150, $result['attributes']['height'] );
	}

	// ── no-ops for non-image blocks ───────────────────────────────────────

	public function test_enrich_skips_non_image_blocks() {
		$data = array(
			'name'       => 'core/paragraph',
			'attributes' => array( 'id' => $this->attachment_id ),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/paragraph' );

		$this->assertArrayNotHasKey( 'width', $result['attributes'] );
		$this->assertArrayNotHasKey( 'height', $result['attributes'] );
	}

	// ── no-ops when id is missing ─────────────────────────────────────────

	public function test_enrich_skips_when_id_missing() {
		$data = array(
			'name'       => 'core/image',
			'attributes' => array( 'url' => 'https://example.com/x.png' ),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/image' );

		$this->assertSame( $data, $result, 'No metadata attached when id is absent.' );
	}

	// ── no-ops when attachment is missing / metadata empty ────────────────

	public function test_enrich_skips_when_attachment_missing() {
		$data = array(
			'name'       => 'core/image',
			'attributes' => array( 'id' => 99999999 ),
		);

		$result = Core_Image_Enricher::enrich( $data, 'core/image' );

		$this->assertArrayNotHasKey( 'width', $result['attributes'] );
		$this->assertArrayNotHasKey( 'height', $result['attributes'] );
	}

	// ── integration: filter is registered and fires through get_blocks ────

	public function test_filter_registered_and_fires_via_get_blocks() {
		$post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'id'       => $this->attachment_id,
						'metadata' => array( 'gk_ref' => 'blk_imgent1' ),
					),
					'innerHTML'    => '<figure><img src="x.png"/></figure>',
					'innerContent' => array( '<figure><img src="x.png"/></figure>' ),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->crud->get_blocks( $post_id );
		$this->assertIsArray( $result );
		$this->assertSame( 'core/image', $result[0]['name'] );
		$this->assertSame( 800, $result[0]['attributes']['width'] ?? null, 'Filter must fire via plugin loader.' );
		$this->assertSame( 600, $result[0]['attributes']['height'] ?? null );
	}

	/**
	 * Create a fake image attachment with deterministic metadata.
	 *
	 * @param int $width  Width to record.
	 * @param int $height Height to record.
	 * @return int Attachment ID.
	 */
	private function make_image_attachment( int $width, int $height ): int {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/png',
				'post_title'     => 'enricher-test-image',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => $width,
				'height' => $height,
				'file'   => 'image.png',
				'sizes'  => array(
					'thumbnail' => array(
						'file'   => 'image-150x150.png',
						'width'  => 150,
						'height' => 150,
					),
				),
			)
		);
		return $attachment_id;
	}
}
