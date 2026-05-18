<?php
/**
 * Tests for Block_CRUD::check_if_match — optimistic concurrency control.
 *
 * Pins the contract that the If-Match precondition:
 *   - is a no-op when no expected revision is supplied,
 *   - rejects malformed values with 400,
 *   - rejects stale revisions with 412 + current_revision in the data envelope,
 *   - accepts both bare integers and W/"<n>" / "<n>" wrapped forms.
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare(strict_types=1);

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;
use GravityKit\BlockAPI\Block_Inventory;

class IfMatchTest extends \PHPUnit\Framework\TestCase {

	/** @var Block_CRUD */
	private $crud;

	/** @var int */
	private $post_id = 8800;

	protected function setUp(): void {
		parent::setUp();
		$this->crud = new Block_CRUD(
			new Preferences(),
			new Block_Safety(),
			new HTML_Transformer(),
			new Block_Inventory()
		);

		// Reset state.
		$GLOBALS['_gk_test_posts']     = array();
		$GLOBALS['_gk_test_revisions'] = array();

		$post                                       = new \stdClass();
		$post->ID                                   = $this->post_id;
		$post->post_type                            = 'post';
		$post->post_status                          = 'publish';
		$post->post_title                           = 'If-Match Test';
		$post->post_content                         = '';
		$GLOBALS['_gk_test_posts'][ $this->post_id ] = $post;
	}

	private function set_revisions( array $rev_ids ): void {
		// Seed in newest-first order, since wp_get_post_revisions sorts DESC.
		$GLOBALS['_gk_test_revisions'][ $this->post_id ] = $rev_ids;
	}

	/**
	 * No expected revision = no-op. Preserves backwards compatibility for
	 * every caller that doesn't opt in.
	 */
	public function test_empty_value_is_a_noop() {
		$this->set_revisions( array( 200, 100 ) );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, '' ) );
	}

	/**
	 * Non-string input is treated as no-op (defensive — header reads from WP
	 * REST sometimes return false / null).
	 */
	public function test_non_string_input_is_noop() {
		$this->assertNull( $this->crud->check_if_match( $this->post_id, null ) );
	}

	/**
	 * Bare integer string matching the current revision passes silently.
	 */
	public function test_bare_integer_matching_current_revision_passes() {
		$this->set_revisions( array( 200, 100 ) );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, '200' ) );
	}

	/**
	 * RFC 7232 weak ETag form (`W/"123"`) is unwrapped before comparison.
	 *
	 * Most HTTP clients send the full ETag back verbatim in If-Match;
	 * the bridge must accept both shapes interchangeably.
	 */
	public function test_weak_etag_format_is_accepted() {
		$this->set_revisions( array( 200 ) );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, 'W/"200"' ) );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, '"200"' ) );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, '  W/"200"  ' ) );
	}

	/**
	 * Stale expected revision returns 412 with current_revision in the data
	 * envelope, so callers can refresh and retry without re-fetching the
	 * whole post just to learn the new ETag.
	 */
	public function test_stale_revision_returns_412_with_current_revision() {
		$this->set_revisions( array( 250 ) );
		$err = $this->crud->check_if_match( $this->post_id, '200' );

		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'stale_revision', $err->get_error_code() );
		$data = $err->get_error_data();
		$this->assertSame( 412, $data['status'] );
		$this->assertSame( 200, $data['expected_revision'] );
		$this->assertSame( 250, $data['current_revision'] );
	}

	/**
	 * Malformed values (text, decimals, garbage) return 400 — better than
	 * silently passing or failing the wrong way (don't disguise a bug as a
	 * concurrency conflict).
	 */
	public function test_malformed_value_returns_400() {
		$this->set_revisions( array( 200 ) );
		$err = $this->crud->check_if_match( $this->post_id, 'not-a-number' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'invalid_if_match', $err->get_error_code() );
		$this->assertSame( 400, $err->get_error_data()['status'] );

		$err = $this->crud->check_if_match( $this->post_id, '1.5' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'invalid_if_match', $err->get_error_code() );
	}

	/**
	 * Comparing against a post with no revisions yet (current_revision = 0)
	 * also detects mismatch. Pins the edge case where a caller holds a
	 * stale ETag from one server while a different server reset revisions.
	 */
	public function test_zero_current_revision_still_compares() {
		$this->set_revisions( array() );
		$err = $this->crud->check_if_match( $this->post_id, '50' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'stale_revision', $err->get_error_code() );
		$this->assertSame( 0, $err->get_error_data()['current_revision'] );
	}

	/**
	 * `If-Match: 0` against a post with no revisions yet passes — the caller
	 * is asserting "expect a fresh post". Useful for "create or no-op" flows
	 * that want to fail rather than overwrite an existing edit.
	 */
	public function test_zero_expected_matches_zero_current() {
		$this->set_revisions( array() );
		$this->assertNull( $this->crud->check_if_match( $this->post_id, '0' ) );
	}

	/**
	 * `get_latest_revision_id` returns the most-recent revision's ID, or 0
	 * when the post has no revisions. This is the value surfaced as the
	 * ETag on GETs, so the response/precondition handshake stays consistent.
	 */
	public function test_get_latest_revision_id_returns_newest() {
		$this->set_revisions( array( 250, 200, 100 ) );
		$this->assertSame( 250, $this->crud->get_latest_revision_id( $this->post_id ) );

		$this->set_revisions( array() );
		$this->assertSame( 0, $this->crud->get_latest_revision_id( $this->post_id ) );
	}
}
