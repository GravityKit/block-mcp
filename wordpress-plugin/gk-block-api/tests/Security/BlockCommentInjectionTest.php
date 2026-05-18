<?php
/**
 * Block-comment injection / breakout tests.
 *
 * WordPress block markup is a sequence of HTML comments containing JSON.
 * Attribute values that contain the literal substring `-->` or
 * `<!-- wp:something` can break out of the comment context and inject
 * phantom blocks into the document — silently corrupting other users'
 * content or planting hidden blocks the editor doesn't show.
 *
 * Verifies that:
 *   - attrs containing `-->` survive serialize → parse round-trip with
 *     no extra blocks materialized;
 *   - attrs containing `<!-- wp:paragraph -->` don't get parsed as a
 *     real block on re-read;
 *   - innerHTML containing those substrings gets sanitized/escaped or
 *     at minimum doesn't fracture the parse tree;
 *   - whole-document re-parse equals the in-memory shape modulo
 *     innerHTML reconstruction (the only documented divergence).
 *
 * @package GravityKit\BlockAPI\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;

class BlockCommentInjectionTest extends WP_UnitTestCase {

	/** @var Block_CRUD */
	private $crud;

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->crud = new Block_CRUD(
			new Preferences(),
			new Block_Safety(),
			new HTML_Transformer(),
			new Block_Inventory()
		);
		$this->post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => '',
		) );
	}

	// ── attrs containing comment-syntax literals ──────────────────

	/**
	 * `-->` inside a quoted JSON string. WP's `serialize_block_attributes`
	 * escapes `--` to `--` specifically so the substring can't break out
	 * of the comment.
	 */
	public function test_attrs_with_close_comment_delim_dont_inject_phantom_block() {
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'placeholder' => 'pre-->INJECT<!-- wp:image /-->-->-post' ),
				'innerHTML'  => '<p>visible</p>',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result, 'insert with hostile attrs must succeed' );

		$saved   = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $saved, static fn( $b ) => null !== $b['blockName'] ) );

		// Exactly one block of the type we inserted — no phantom blocks injected.
		$this->assertCount( 1, $visible, 'exactly one block; -->INJECT must not break out' );
		$this->assertSame( 'core/paragraph', $visible[0]['blockName'] );
		// The hostile string round-trips intact in attrs.
		$this->assertSame(
			'pre-->INJECT<!-- wp:image /-->-->-post',
			$visible[0]['attrs']['placeholder']
		);
	}

	public function test_attrs_with_full_block_comment_in_value_does_not_create_block() {
		$payload = '<!-- wp:image {"id":99999} /-->';
		$result  = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'title' => $payload ),
				'innerHTML'  => '<p>x</p>',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$saved   = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $saved, static fn( $b ) => null !== $b['blockName'] ) );
		$names   = array_column( $visible, 'blockName' );

		$this->assertNotContains( 'core/image', $names, 'attr-embedded core/image comment must not parse as real block' );
		$this->assertContains( 'core/paragraph', $names );
		// Round-trip the attr value.
		$paragraphs = array_values( array_filter( $visible, static fn( $b ) => 'core/paragraph' === $b['blockName'] ) );
		$this->assertSame( $payload, $paragraphs[0]['attrs']['title'] );
	}

	// ── innerHTML containing comment-syntax ───────────────────────

	/**
	 * `-->` inside innerHTML — `wp_kses_post` strips HTML comments
	 * entirely from post content, so the substring is removed before it
	 * can reach `serialize_blocks()`.
	 */
	public function test_innerhtml_with_close_comment_is_sanitized() {
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'      => 'core/paragraph',
				'innerHTML' => '<p>before</p>--><!-- wp:heading {"level":1} --><h1>INJECT</h1><!-- /wp:heading -->',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$saved   = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $saved, static fn( $b ) => null !== $b['blockName'] ) );
		$names   = array_column( $visible, 'blockName' );

		// We started with one paragraph. wp_kses_post strips the comment-
		// delimiter substrings before they reach serialize_block(), so the
		// hostile <!-- wp:heading --> cannot materialize as a real block
		// on the page.
		$this->assertCount( 1, $visible, 'phantom <!-- wp:heading --> must not appear in parsed output' );
		$this->assertSame( 'core/paragraph', $visible[0]['blockName'] );
		$this->assertNotContains( 'core/heading', $names );
	}

	// ── round-trip stability under hostile attrs ──────────────────

	/**
	 * Save a tree containing hostile attrs, then save it again with the
	 * just-read shape, and verify the document doesn't grow extra blocks
	 * (which would indicate the parser is misinterpreting embedded
	 * comment-syntax as block boundaries).
	 */
	public function test_replace_all_blocks_with_hostile_attrs_idempotent() {
		$blocks = array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array(
					'data' => '<!-- wp:list --><ul><li>poison</li></ul><!-- /wp:list -->',
					'meta' => '"}--><script>alert(1)</script><!-- {"',
				),
				'innerHTML'  => '<p>kept</p>',
			),
		);

		$result1 = $this->crud->replace_all_blocks( $this->post_id, $blocks );
		$this->assertNotInstanceOf( \WP_Error::class, $result1 );
		$saved1 = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$count1 = count( array_filter( $saved1, static fn( $b ) => null !== $b['blockName'] ) );

		// Round-trip: serialize again and check the count is stable.
		// (This is what happens when an editor opens, makes no change,
		// and re-saves — naive injection would compound on every save.)
		$re_saved = serialize_blocks( $saved1 );
		$re_parse = parse_blocks( $re_saved );
		$count2   = count( array_filter( $re_parse, static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertSame( $count1, $count2, 'serialize→parse must be idempotent — no growth from embedded comment syntax' );
		$this->assertSame( 1, $count1, 'exactly one block; hostile attrs must not spawn phantom blocks' );
	}

	// ── attrs JSON parser robustness ──────────────────────────────

	/**
	 * Hostile JSON-in-string: a literal `}` inside a quoted string must
	 * not terminate the attrs object early.
	 */
	public function test_attrs_with_nested_quoted_braces_round_trip() {
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'json' => '{"k":"}--><!-- wp:bad --><"}' ),
				'innerHTML'  => '<p>x</p>',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$saved   = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $saved, static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertCount( 1, $visible );
		$this->assertSame( '{"k":"}--><!-- wp:bad --><"}', $visible[0]['attrs']['json'] );
	}

	/**
	 * WP serializes attrs with `JSON_UNESCAPED_UNICODE` since WP 5.0 —
	 * confirm non-ASCII chars survive intact and don't introduce
	 * surrogate-pair smuggling vectors.
	 */
	public function test_unicode_attrs_round_trip_through_save() {
		$payload = "héllo — 日本語 🎉 RTL\xE2\x80\xAEevil";
		$result  = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'title' => $payload ),
				'innerHTML'  => '<p>x</p>',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$saved   = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $saved, static fn( $b ) => null !== $b['blockName'] ) );
		$this->assertSame( $payload, $visible[0]['attrs']['title'] );
	}
}
