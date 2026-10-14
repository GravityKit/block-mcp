<?php
/**
 * Post notices and the pre-write check: the extension points other plugins
 * use to tell an agent about a post and to stop a write before it happens.
 *
 * Every case dispatches a real WP_REST_Request through the registered routes.
 * The Abilities API path is pinned in AbilityExecutionTest, and Yoast writes
 * in YoastBridgeTest.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

class PostExtensionsTest extends RestControllerTestCase {

	/** @var int Editor — holds edit_post on the fixture posts. */
	private $editor_id;

	/** @var array<int, array{int, string}> Calls the pre-write filter saw. */
	private $write_calls = array();

	public function set_up(): void {
		parent::set_up();
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		$this->write_calls = array();
	}

	// ── fixture helpers ──────────────────────────────────────────────────

	/**
	 * A paragraph block with a stable ref.
	 *
	 * @param string $text Paragraph text.
	 * @param string $ref  gk_ref value.
	 *
	 * @return array
	 */
	private function paragraph( string $text, string $ref = 'blk_aaaa1111' ): array {
		$html = '<p>' . $text . '</p>';

		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'metadata' => array( 'gk_ref' => $ref ) ),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Dispatch a request through the REST server.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route under the plugin namespace.
	 * @param array  $params Parameters.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/gk-block-api/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Add a notice for one post.
	 *
	 * @param int    $post_id Post to annotate.
	 * @param string $text    Notice text.
	 *
	 * @return void
	 */
	private function notice_for( int $post_id, string $text ): void {
		add_filter(
			'gk/block-mcp/post/notices',
			static function ( $notices, $id ) use ( $post_id, $text ) {
				if ( $id === $post_id ) {
					$notices[] = $text;
				}
				return $notices;
			},
			10,
			2
		);
	}

	/**
	 * Refuse every write to one post, recording each check.
	 *
	 * @param int $post_id Post to protect.
	 *
	 * @return void
	 */
	private function refuse_writes_to( int $post_id ): void {
		add_filter(
			'gk/block-mcp/post/pre-write',
			function ( $result, $id, $operation ) use ( $post_id ) {
				$this->write_calls[] = array( $id, $operation );
				if ( $id === $post_id ) {
					return new \WP_Error( 'staged_copy_waiting', 'Edit the staged copy #42 instead.' );
				}
				return $result;
			},
			10,
			3
		);
	}

	// ── notices ──────────────────────────────────────────────────────────

	/**
	 * Every read an agent starts from carries the post's notices.
	 *
	 * Agents resolve a URL or read blocks before editing, so a notice must be
	 * on each of those responses, including the cheap summary-only read.
	 */
	public function test_notices_appear_on_every_read_route() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ) );
		$this->notice_for( $post_id, 'Edit the staged copy #42 instead.' );

		$responses = array(
			'blocks'       => $this->dispatch( 'GET', '/posts/' . $post_id . '/blocks', array( 'id' => $post_id ) ),
			'summary_only' => $this->dispatch( 'GET', '/posts/' . $post_id . '/blocks', array( 'id' => $post_id, 'summary_only' => true ) ),
			'post_info'    => $this->dispatch( 'GET', '/post-info', array( 'post_id' => $post_id ) ),
			'resolve_url'  => $this->dispatch( 'GET', '/resolve', array( 'url' => get_permalink( $post_id ) ) ),
		);

		foreach ( $responses as $route => $response ) {
			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertSame( array( 'Edit the staged copy #42 instead.' ), $response->get_data()['notices'], $route );
		}
	}

	/**
	 * A post nobody annotates reads as before, with an empty list.
	 */
	public function test_notices_are_empty_without_a_filter() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ) );

		$response = $this->dispatch( 'GET', '/post-info', array( 'post_id' => $post_id ) );

		$this->assertSame( array(), $response->get_data()['notices'] );
	}

	/**
	 * Notices reach the agent as plain text: markup is stripped, non-strings
	 * and blanks are dropped, and duplicates from two filters appear once.
	 */
	public function test_notices_are_sanitized_to_unique_plain_text() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ) );
		add_filter(
			'gk/block-mcp/post/notices',
			static function () {
				return array( '<b>Edit #42</b> instead.', 'Edit #42 instead.', array( 'nested' ), '', '  ' );
			}
		);

		$response = $this->dispatch( 'GET', '/post-info', array( 'post_id' => $post_id ) );

		$this->assertSame( array( 'Edit #42 instead.' ), $response->get_data()['notices'] );
	}

	/**
	 * A notices filter that throws must not break the read: the agent still
	 * gets the post, just without notices.
	 */
	public function test_notices_filter_that_throws_does_not_break_the_read() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ) );
		add_filter(
			'gk/block-mcp/post/notices',
			static function () {
				throw new \RuntimeException( 'broken extension' );
			}
		);

		$response = $this->dispatch( 'GET', '/posts/' . $post_id . '/blocks', array( 'id' => $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['notices'] );
	}

	// ── pre-write ────────────────────────────────────────────────────────

	/**
	 * A refused block edit returns the plugin's message with HTTP 409 and
	 * leaves the post as it was.
	 */
	public function test_pre_write_refusal_stops_a_block_edit() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Live text' ) ) );
		$this->refuse_writes_to( $post_id );

		$response = $this->dispatch(
			'PATCH',
			'/posts/' . $post_id . '/blocks/by-ref/blk_aaaa1111',
			array( 'id' => $post_id, 'ref' => 'blk_aaaa1111', 'innerHTML' => '<p>Agent text</p>' )
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'Edit the staged copy #42 instead.', $response->get_data()['message'] );
		$this->assertStringContainsString( 'Live text', get_post_field( 'post_content', $post_id ) );
		$this->assertSame( array( array( $post_id, 'blocks' ) ), $this->write_calls );
	}

	/**
	 * update_post is checked too, so an agent cannot change the title or
	 * status of a protected post.
	 */
	public function test_pre_write_refusal_stops_update_post() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ), array( 'post_title' => 'Original' ) );
		$this->refuse_writes_to( $post_id );

		$response = $this->dispatch( 'PATCH', '/posts/' . $post_id, array( 'id' => $post_id, 'title' => 'Changed' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'Original', get_post_field( 'post_title', $post_id ) );
		$this->assertSame( array( array( $post_id, 'post' ) ), $this->write_calls );
	}

	/**
	 * Writes to other posts are untouched by a refusal aimed at one post.
	 */
	public function test_pre_write_allows_posts_it_does_not_refuse() {
		$protected = $this->make_block_post( array( $this->paragraph( 'Protected' ) ) );
		$open      = $this->make_block_post( array( $this->paragraph( 'Open' ) ) );
		$this->refuse_writes_to( $protected );

		$response = $this->dispatch(
			'PATCH',
			'/posts/' . $open . '/blocks/by-ref/blk_aaaa1111',
			array( 'id' => $open, 'ref' => 'blk_aaaa1111', 'innerHTML' => '<p>Edited</p>' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Edited', get_post_field( 'post_content', $open ) );
	}

	/**
	 * A refusal that sets its own HTTP status keeps it.
	 */
	public function test_pre_write_refusal_keeps_its_own_status() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Body' ) ) );
		add_filter(
			'gk/block-mcp/post/pre-write',
			static function () {
				return new \WP_Error( 'locked', 'Locked.', array( 'status' => 423 ) );
			}
		);

		$response = $this->dispatch( 'PATCH', '/posts/' . $post_id, array( 'id' => $post_id, 'title' => 'X' ) );

		$this->assertSame( 423, $response->get_status() );
	}

	/**
	 * A pre-write filter that throws fails closed: the write is not made and
	 * the agent is told why, instead of the guard being silently skipped.
	 */
	public function test_pre_write_filter_that_throws_fails_closed() {
		$post_id = $this->make_block_post( array( $this->paragraph( 'Live text' ) ) );
		add_filter(
			'gk/block-mcp/post/pre-write',
			static function () {
				throw new \RuntimeException( 'broken guard' );
			}
		);

		$response = $this->dispatch(
			'PATCH',
			'/posts/' . $post_id . '/blocks/by-ref/blk_aaaa1111',
			array( 'id' => $post_id, 'ref' => 'blk_aaaa1111', 'innerHTML' => '<p>Agent text</p>' )
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'write_check_failed', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Live text', get_post_field( 'post_content', $post_id ) );
	}
}
