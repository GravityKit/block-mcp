<?php
/**
 * REST-layer regression tests for the post-metadata visibility leaks.
 *
 * Three endpoints used to hand back title / status / author / parent /
 * timestamps for posts the caller had no business reading:
 *
 *   GET  /post-info  — direct id / slug / url lookup, no cap check
 *   GET  /find-posts — WP_Query without `perm` argument
 *   GET  /resolve    — url_to_postid() lookup, no cap check
 *
 * Each test sets the caller to an Author-level user (has edit_posts, not
 * edit_others_posts, not read_private_posts) and asserts the endpoint
 * returns a 4xx / empty for another user's draft.
 *
 * @package GravityKit\BlockAPI\Tests
 */

class PostVisibilityTest extends WP_UnitTestCase {

	/** @var int */
	private $author_id;

	/** @var int */
	private $other_user_id;

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Spin up a draft post owned by $other_user_id with a known title + slug.
	 *
	 * @return int Post ID.
	 */
	private function make_other_user_draft( string $slug = 'secret-draft' ): int {
		return self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->other_user_id,
				'post_title'  => 'Confidential draft',
				'post_name'   => $slug,
				'post_content' => '<!-- wp:paragraph --><p>secret</p><!-- /wp:paragraph -->',
			)
		);
	}

	// ── /post-info ────────────────────────────────────────────────────────

	/**
	 * GET /post-info?post_id={X} must NOT return another user's draft to an Author.
	 *
	 * Pre-fix, direct id lookup did NO cap check; post_info handed back
	 * title / author / status / parent / timestamps. Now: routed through
	 * Block_CRUD::is_post_readable(); falls back to 404 to avoid signaling
	 * the post's existence.
	 */
	public function test_post_info_404s_for_other_users_draft() {
		$draft_id = $this->make_other_user_draft( 'pi-by-id' );
		wp_set_current_user( $this->author_id );

		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/post-info' );
		$request->set_param( 'post_id', $draft_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status(), 'Author must not see another user\'s draft via post-info.' );
		$data = $response->get_data();
		$this->assertSame( 'not_found', $data['code'] ?? null );
	}

	/**
	 * Same gate applies to slug-based lookup.
	 *
	 * Pre-fix, slug lookup queried draft / private / pending / future
	 * statuses unconditionally and returned the first match regardless
	 * of caller cap.
	 */
	public function test_post_info_404s_for_other_users_draft_by_slug() {
		$this->make_other_user_draft( 'pi-by-slug' );
		wp_set_current_user( $this->author_id );

		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/post-info' );
		$request->set_param( 'slug', 'pi-by-slug' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// ── /find-posts ───────────────────────────────────────────────────────

	/**
	 * GET /find-posts?post_status=draft must NOT return another user's drafts.
	 *
	 * Pre-fix, WP_Query ran without `perm` set — so SQL returned every
	 * matching post regardless of user cap. Now: `perm: 'readable'` pushes
	 * the cap check into posts_where_paged; a per-result is_post_readable()
	 * pass also catches publish-with-password posts (perm doesn't handle
	 * password).
	 */
	public function test_find_posts_excludes_other_users_drafts() {
		$draft_id = $this->make_other_user_draft( 'fp-secret' );
		wp_set_current_user( $this->author_id );

		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/find-posts' );
		$request->set_param( 'post_status', 'draft' );
		$request->set_param( 'per_page', 100 );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$ids = array_map(
			static function ( $p ) {
				return $p['post_id'];
			},
			$response->get_data()['posts'] ?? array()
		);
		$this->assertNotContains(
			$draft_id,
			$ids,
			'Author search for status=draft must not include another user\'s drafts.'
		);
	}

	// ── /resolve ──────────────────────────────────────────────────────────

	/**
	 * GET /resolve must NOT leak metadata for a URL resolving to another user's draft.
	 *
	 * url_to_postid() resolves drafts when the caller is logged in. Without
	 * the readability gate, /resolve handed back title / status / slug for
	 * any URL that resolved — including drafts of other users.
	 */
	public function test_resolve_url_404s_for_other_users_draft() {
		$draft_id = $this->make_other_user_draft( 'resolve-secret' );
		$url      = get_permalink( $draft_id );
		$this->assertNotEmpty( $url );

		wp_set_current_user( $this->author_id );

		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/resolve' );
		$request->set_param( 'url', $url );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame(
			404,
			$response->get_status(),
			'Author must not see another user\'s draft metadata via /resolve.'
		);
	}
}
