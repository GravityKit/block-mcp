<?php
/**
 * Tests for Template_Manager::update_template() / reset_template().
 *
 * Exercises the gated write surface against the same wp-phpunit block-theme
 * fixture TemplateManagerTest uses: the toggle gate (both directions via the
 * filter), override creation (wp_theme + wp_template_part_area terms),
 * content vs. blocks input, idempotent reuse of an existing override,
 * reset, and the classic-theme / legacy-block / mixed-input guards.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\Template_Manager;

class TemplateManagerWriteTest extends WP_UnitTestCase {

	/** @var Template_Manager */
	private $tm;

	/** @var Block_CRUD */
	private $block_crud;

	/** @var string Stylesheet slug of the active block theme for this test run. */
	private $theme;

	public function set_up(): void {
		parent::set_up();

		$this->ensure_theme_root_resolvable();

		$block_theme = $this->find_block_theme();
		if ( null === $block_theme ) {
			$this->markTestSkipped( 'No block theme is available in this test environment.' );
		}
		switch_theme( $block_theme );
		$this->theme = $block_theme;

		// Legacy-tier namespace for the "legacy block in blocks rejected" test.
		update_option(
			Preferences::OPTION_KEY,
			array(
				'namespace_scores' => array( 'ugb' => 0 ),
				'replacement_map'  => array( 'ugb/text' => 'core/paragraph' ),
			)
		);
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/paragraph', 'core/heading', 'ugb/text' ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$preferences      = new Preferences();
		$this->block_crud = new Block_CRUD( $preferences, new Block_Safety(), new HTML_Transformer(), new Block_Inventory() );
		$this->tm         = new Template_Manager( $this->block_crud );

		// Baseline actor: edit_posts only, same as the dedicated agent role.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Work around a wp-phpunit quirk that breaks switch_theme() for any
	 * fixture theme other than the WP_DEFAULT_THEME. See
	 * TemplateManagerTest::ensure_theme_root_resolvable() for the rationale.
	 *
	 * @return void
	 */
	private function ensure_theme_root_resolvable() {
		$dummy_root = sys_get_temp_dir() . '/gk-block-mcp-empty-theme-root';
		if ( ! is_dir( $dummy_root ) ) {
			wp_mkdir_p( $dummy_root );
		}
		register_theme_directory( $dummy_root );
	}

	/**
	 * Find a standalone (non-child) block theme. See
	 * TemplateManagerTest::find_block_theme() for the rationale.
	 *
	 * @return string|null
	 */
	private function find_block_theme() {
		$themes = wp_get_themes();

		if ( isset( $themes['block-theme'] ) && $themes['block-theme']->is_block_theme() && ! $themes['block-theme']->get( 'Template' ) ) {
			return 'block-theme';
		}

		foreach ( $themes as $stylesheet => $theme_obj ) {
			if ( $theme_obj->is_block_theme() && ! $theme_obj->get( 'Template' ) ) {
				return $stylesheet;
			}
		}

		return null;
	}

	/**
	 * Register the fixture theme directory containing "hybrid-theme" and
	 * force a rescan: register_theme_directory() alone doesn't refresh
	 * search_theme_directories()'s memoized scan once anything else in the
	 * run has already triggered it, so without wp_clean_themes_cache() the
	 * fixture stays invisible to wp_get_themes().
	 *
	 * @return void
	 */
	private function register_hybrid_theme_root() {
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		wp_clean_themes_cache();
	}

	// ── Gate ───────────────────────────────────────────────────────────

	/**
	 * Off by default: update_template refuses to write until a site
	 * administrator opts in, regardless of how it's called.
	 */
	public function test_update_template_disabled_by_default() {
		$result = $this->tm->update_template( $this->theme . '//index', 'wp_template', array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'template_edits_disabled', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	/**
	 * reset_template is gated the same way as update_template.
	 */
	public function test_reset_template_disabled_by_default() {
		$result = $this->tm->reset_template( $this->theme . '//index' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'template_edits_disabled', $result->get_error_code() );
	}

	/**
	 * The gk/block-mcp/templates/allow-edits filter can force editing OFF
	 * even when the stored option is on.
	 */
	public function test_filter_can_force_edits_off_despite_stored_option() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		$filter = static fn() => false;
		add_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$result = $this->tm->update_template( $this->theme . '//index', 'wp_template', array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		remove_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'template_edits_disabled', $result->get_error_code() );
	}

	/**
	 * The gk/block-mcp/templates/allow-edits filter can force editing ON
	 * even when the stored option is off (the default).
	 */
	public function test_filter_can_force_edits_on_despite_stored_option() {
		$filter = static fn() => true;
		add_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$result = $this->tm->update_template( $this->theme . '//index', 'wp_template', array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		remove_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ── update_template(): override creation ─────────────────────────

	/**
	 * Writing a theme-file-only template creates a database override with
	 * the mandatory wp_theme term, reports override_created:true, and the
	 * new post's content matches what was sent.
	 */
	public function test_update_template_creates_override_with_wp_theme_term() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$result = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>New index content</p><!-- /wp:paragraph -->' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['override_created'] );
		$this->assertGreaterThan( 0, $result['wp_id'] );
		$this->assertNotEmpty( $result['revert_hint'] );

		$post = get_post( $result['wp_id'] );
		$this->assertSame( 'wp_template', $post->post_type );
		$this->assertSame( 'index', $post->post_name );
		$this->assertStringContainsString( 'New index content', $post->post_content );

		$terms = wp_get_object_terms( $result['wp_id'], 'wp_theme', array( 'fields' => 'names' ) );
		$this->assertSame( array( $this->theme ), $terms );

		$fetched = $this->tm->get_template( $this->theme . '//index' );
		$this->assertSame( 'custom', $fetched['source'] );
		$this->assertSame( $result['wp_id'], $fetched['wp_id'] );
	}

	/**
	 * A second write against an already-customized template reuses the
	 * same override post — no duplicate `wp_template` rows for one slug.
	 */
	public function test_update_template_second_write_reuses_same_wp_id() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$first = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' )
		);
		$this->assertTrue( $first['override_created'] );

		$second = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' )
		);

		$this->assertFalse( $second['override_created'] );
		$this->assertSame( $first['wp_id'], $second['wp_id'] );

		$matching = new \WP_Query(
			array(
				'post_type'      => 'wp_template',
				'post_name'      => 'index',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $matching->posts );
		$this->assertStringContainsString( 'Second', $matching->posts[0]->post_content );
	}

	/**
	 * Writing a template part sets the wp_template_part_area term from the
	 * theme file's declared area (the fixture's "small-header" is "header").
	 */
	public function test_update_template_part_sets_area_term() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$result = $this->tm->update_template(
			$this->theme . '//small-header',
			'wp_template_part',
			array( 'content' => '<!-- wp:paragraph --><p>New header</p><!-- /wp:paragraph -->' )
		);

		$this->assertTrue( $result['success'] );
		$terms = wp_get_object_terms( $result['wp_id'], 'wp_template_part_area', array( 'fields' => 'names' ) );
		$this->assertSame( array( 'header' ), $terms );
	}

	/**
	 * `blocks` input goes through the same registry/tier validation as any
	 * other structured-block write — a legacy-tier block is rejected and,
	 * because this was the override-creating call, the freshly-created
	 * override post is rolled back rather than left as an empty shell.
	 */
	public function test_update_template_rejects_legacy_block_and_rolls_back_new_override() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$result = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array(
				'blocks' => array(
					array( 'name' => 'ugb/text', 'attributes' => array(), 'innerHTML' => '<div>legacy</div>' ),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$matching = new \WP_Query(
			array(
				'post_type'      => 'wp_template',
				'post_name'      => 'index',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 0, $matching->posts, 'A rejected write must not leave an orphaned override post behind.' );
	}

	/**
	 * `content` and `blocks` are mutually exclusive — providing both (or
	 * neither) is a 400, not a silent pick-one.
	 */
	public function test_update_template_requires_exactly_one_of_content_or_blocks() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$neither = $this->tm->update_template( $this->theme . '//index', 'wp_template', array() );
		$this->assertInstanceOf( \WP_Error::class, $neither );
		$this->assertSame( 'invalid_input', $neither->get_error_code() );

		$both = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 'blocks' => array() )
		);
		$this->assertInstanceOf( \WP_Error::class, $both );
		$this->assertSame( 'invalid_input', $both->get_error_code() );
	}

	/**
	 * A classic (non-block) theme has nothing to edit — 400 with a clear
	 * message, not a confusing not_found.
	 */
	public function test_update_template_classic_theme_returns_400() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		switch_theme( 'default' );

		$result = $this->tm->update_template( 'default//index', 'wp_template', array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'classic_theme', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	// ── reset_template() ──────────────────────────────────────────────

	/**
	 * Resetting an existing override deletes its post and get_template
	 * reports the theme file's content again.
	 */
	public function test_reset_template_deletes_override_and_reverts_to_theme_file() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$updated = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>Overridden</p><!-- /wp:paragraph -->' )
		);
		$this->assertTrue( $updated['success'] );

		$reset = $this->tm->reset_template( $this->theme . '//index' );

		$this->assertIsArray( $reset );
		$this->assertTrue( $reset['success'] );
		$this->assertSame( $updated['wp_id'], $reset['wp_id'] );
		$this->assertNull( get_post( $updated['wp_id'] ) );

		$fetched = $this->tm->get_template( $this->theme . '//index' );
		$this->assertSame( 'theme', $fetched['source'] );
		$this->assertNull( $fetched['wp_id'] );
		$this->assertStringContainsString( 'Index Template', $fetched['content'] );
	}

	/**
	 * Resetting a template that has no override is a 404, not a silent
	 * no-op or a deletion of the theme file (which isn't a post at all).
	 */
	public function test_reset_template_no_override_returns_404() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$result = $this->tm->reset_template( $this->theme . '//index' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_override', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	// ── Probe: does the per-block CRUD/safety layer accept an override? ─

	/**
	 * `update_block` (the per-block write tool) targets any post by ID and
	 * has no post-type restriction, so it works against a template
	 * override's `wp_id` the same as it would against a page or post —
	 * confirmed here rather than assumed.
	 */
	public function test_update_block_works_against_template_override_wp_id() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$created = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array(
				'blocks' => array(
					array( 'name' => 'core/paragraph', 'attributes' => array(), 'innerHTML' => '<p>Original</p>' ),
				),
			)
		);
		$this->assertTrue( $created['success'] );

		$probe = $this->block_crud->update_block( $created['wp_id'], 0, array(), '<p>Changed via update_block</p>' );

		$this->assertIsArray( $probe );
		$this->assertTrue( $probe['success'] );
		$this->assertSame( '<p>Changed via update_block</p>', $probe['saved']['inner_html'] );

		$post = get_post( $created['wp_id'] );
		$this->assertStringContainsString( 'Changed via update_block', $post->post_content );
	}

	// ── Term-assignment failure rolls back the new override ─────────────

	/**
	 * A required taxonomy term (wp_theme here; wp_template_part_area for
	 * parts, below) failing to assign — e.g. a pre_insert_term filter
	 * rejecting it — returns a WP_Error and deletes the freshly-created
	 * override post, rather than leaving an orphan with no wp_theme term
	 * for get_block_templates() to ever find again.
	 */
	public function test_update_template_rolls_back_new_override_when_wp_theme_term_assignment_fails() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$filter = static function ( $term, $taxonomy ) {
			if ( 'wp_theme' === $taxonomy ) {
				return new \WP_Error( 'term_insert_failed', 'Simulated taxonomy failure.' );
			}
			return $term;
		};
		add_filter( 'pre_insert_term', $filter, 10, 2 );

		$result = $this->tm->update_template(
			$this->theme . '//index',
			'wp_template',
			array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
		);

		remove_filter( 'pre_insert_term', $filter, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$matching = new \WP_Query(
			array(
				'post_type'      => 'wp_template',
				'post_name'      => 'index',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 0, $matching->posts, 'A term-assignment failure must not leave an orphaned override post behind.' );
	}

	/**
	 * Same failure mode for a template part: the `wp_template_part_area`
	 * term-assignment call must also be checked and roll back the override.
	 */
	public function test_update_template_rolls_back_new_override_when_area_term_assignment_fails() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$filter = static function ( $term, $taxonomy ) {
			if ( 'wp_template_part_area' === $taxonomy ) {
				return new \WP_Error( 'term_insert_failed', 'Simulated taxonomy failure.' );
			}
			return $term;
		};
		add_filter( 'pre_insert_term', $filter, 10, 2 );

		$result = $this->tm->update_template(
			$this->theme . '//small-header',
			'wp_template_part',
			array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
		);

		remove_filter( 'pre_insert_term', $filter, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$matching = new \WP_Query(
			array(
				'post_type'      => 'wp_template_part',
				'post_name'      => 'small-header',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 0, $matching->posts, 'A term-assignment failure must not leave an orphaned override post behind.' );
	}

	/**
	 * When the term-assignment rollback's own wp_delete_post() also fails
	 * (not just the term assignment), the caller must be told the cleanup
	 * itself failed — a distinct error, not the original term error
	 * silently standing in for a rollback that never actually happened.
	 */
	public function test_update_template_reports_rollback_failure_when_delete_also_fails() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$term_filter = static function ( $term, $taxonomy ) {
			if ( 'wp_theme' === $taxonomy ) {
				return new \WP_Error( 'term_insert_failed', 'Simulated taxonomy failure.' );
			}
			return $term;
		};
		add_filter( 'pre_insert_term', $term_filter, 10, 2 );

		$delete_filter = static function () {
			return false; // Force wp_delete_post() to short-circuit and fail.
		};
		add_filter( 'pre_delete_post', $delete_filter );

		try {
			$result = $this->tm->update_template(
				$this->theme . '//index',
				'wp_template',
				array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
			);
		} finally {
			// Run on an unexpected exception too — a leftover filter here
			// would otherwise contaminate every later term/delete call in
			// the same PHP process, not just this test.
			remove_filter( 'pre_insert_term', $term_filter, 10 );
			remove_filter( 'pre_delete_post', $delete_filter );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		/** @var \WP_Error $result */
		$this->assertSame( 'rollback_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Simulated taxonomy failure', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );

		// The post genuinely could not be deleted — matches what the error warns about.
		$matching = new \WP_Query(
			array(
				'post_type'      => 'wp_template',
				'post_name'      => 'index',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $matching->posts );
	}

	// ── Hybrid theme (wp_is_block_theme() false, but a part resolves) ───

	/**
	 * A hybrid theme's template part resolves via get_block_template(), so
	 * a gated write against it must succeed — it genuinely renders on the
	 * site regardless of wp_is_block_theme()'s file-existence check.
	 */
	public function test_update_template_creates_override_for_hybrid_theme_template_part() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		$this->register_hybrid_theme_root();
		switch_theme( 'hybrid-theme' );
		$this->assertFalse( wp_is_block_theme(), 'Fixture must reproduce wp_is_block_theme() === false to exercise the hybrid case.' );

		$result = $this->tm->update_template(
			'hybrid-theme//footer',
			'wp_template_part',
			array( 'content' => '<!-- wp:paragraph --><p>Overridden footer</p><!-- /wp:paragraph -->' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$fetched = $this->tm->get_template( 'hybrid-theme//footer', 'wp_template_part' );
		$this->assertSame( 'custom', $fetched['source'] );
		$this->assertStringContainsString( 'Overridden footer', $fetched['content'] );
	}

	/**
	 * reset_template must be gated the same way — resolution, not
	 * wp_is_block_theme() — so it can revert the override this test just
	 * created on a hybrid theme.
	 */
	public function test_reset_template_reverts_hybrid_theme_override() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		$this->register_hybrid_theme_root();
		switch_theme( 'hybrid-theme' );

		$updated = $this->tm->update_template(
			'hybrid-theme//footer',
			'wp_template_part',
			array( 'content' => '<!-- wp:paragraph --><p>Overridden</p><!-- /wp:paragraph -->' )
		);
		$this->assertTrue( $updated['success'] );

		$reset = $this->tm->reset_template( 'hybrid-theme//footer', 'wp_template_part' );

		$this->assertIsArray( $reset );
		$this->assertTrue( $reset['success'] );
		$this->assertNull( get_post( $updated['wp_id'] ) );
	}

	/**
	 * A hybrid theme has real content (the "footer" part), so a wrong id
	 * on it must return not_found, not classic_theme — classic_theme
	 * would falsely tell the caller this theme has nothing to edit at
	 * all, when it demonstrably does (just not under this id).
	 */
	public function test_update_template_hybrid_theme_bad_id_returns_not_found_not_classic_theme() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		$this->register_hybrid_theme_root();
		switch_theme( 'hybrid-theme' );

		$result = $this->tm->update_template(
			'hybrid-theme//does-not-exist',
			'wp_template_part',
			array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		/** @var \WP_Error $result */
		$this->assertSame( 'not_found', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	/**
	 * Same distinction for reset_template: a hybrid theme's wrong id is
	 * not_found, not classic_theme.
	 */
	public function test_reset_template_hybrid_theme_bad_id_returns_not_found_not_classic_theme() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		$this->register_hybrid_theme_root();
		switch_theme( 'hybrid-theme' );

		$result = $this->tm->reset_template( 'hybrid-theme//does-not-exist', 'wp_template_part' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		/** @var \WP_Error $result */
		$this->assertSame( 'not_found', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	/**
	 * A genuinely classic theme (no templates/parts at all) gets the
	 * specific, actionable "classic_theme" 400 — contrast the hybrid-theme
	 * tests above, where the same "id doesn't resolve" case is not_found
	 * because the theme demonstrably has content elsewhere.
	 */
	public function test_update_template_classic_theme_still_returns_400_when_nothing_resolves() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		switch_theme( 'default' );

		$result = $this->tm->update_template( 'default//does-not-exist', 'wp_template', array( 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		/** @var \WP_Error $result */
		$this->assertSame( 'classic_theme', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Same classic-theme "id doesn't resolve" contract as update_template's,
	 * for reset_template.
	 */
	public function test_reset_template_classic_theme_still_returns_400_when_nothing_resolves() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		switch_theme( 'default' );

		$result = $this->tm->reset_template( 'default//does-not-exist', 'wp_template' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		/** @var \WP_Error $result */
		$this->assertSame( 'classic_theme', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}
}
