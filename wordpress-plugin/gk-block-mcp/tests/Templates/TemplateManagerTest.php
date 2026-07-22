<?php
/**
 * Tests for the Template_Manager class.
 *
 * Exercises get_templates() / get_template() against WordPress's real
 * block-template APIs (get_block_templates(), get_block_template()) using
 * the wp-phpunit theme fixtures — a real block-theme fixture (templates +
 * a template part with a declared area) and the classic "default" fixture.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\Template_Manager;

class TemplateManagerTest extends WP_UnitTestCase {

	/** @var Template_Manager */
	private $tm;

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

		$preferences = new Preferences();
		$block_crud  = new Block_CRUD( $preferences, new Block_Safety(), new HTML_Transformer(), new Block_Inventory() );
		$this->tm    = new Template_Manager( $block_crud );
	}

	/**
	 * Work around a wp-phpunit quirk that breaks switch_theme() for any
	 * fixture theme other than the WP_DEFAULT_THEME.
	 *
	 * core's get_theme_roots() special-cases exactly one registered theme
	 * directory: it skips the real per-stylesheet root map and returns the
	 * bare placeholder '/themes' for every stylesheet, which then resolves
	 * to WP_CONTENT_DIR/themes — a no-content build has no such directory,
	 * so a switch to any theme outside WP_DEFAULT_THEME silently resolves
	 * has_theme_file / is_block_theme against a directory with nothing in
	 * it. wp-tests-config.php registers exactly one directory (the wp-phpunit
	 * fixture root), which trips this. Registering a second, unrelated,
	 * empty directory raises the count past the special case and restores
	 * correct per-stylesheet resolution; the directory needs no themes in it.
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
	 * Find a standalone (non-child) block theme in the test theme root.
	 *
	 * Prefers the wp-phpunit fixture named exactly "block-theme" (its
	 * template/part slugs are known and asserted on below); falls back to
	 * any other non-child block theme. A child theme is skipped because its
	 * own theme directory has no template files of its own — get_block_templates()
	 * resolves those from the parent stylesheet, not the child's.
	 *
	 * @return string|null Stylesheet slug, or null if none exists.
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
	 * Find a formatted template row by slug.
	 *
	 * @param array  $templates Formatted template rows.
	 * @param string $slug      Slug to find.
	 * @return array|null
	 */
	private function find_by_slug( array $templates, string $slug ) {
		foreach ( $templates as $template ) {
			if ( $slug === $template['slug'] ) {
				return $template;
			}
		}
		return null;
	}

	// ── get_templates(): theme-file listing ──────────────────────────

	/**
	 * The active block theme's index.html template file is listed with
	 * source "theme" and no DB override (wp_id null) — the baseline shape
	 * before any customization exists.
	 */
	public function test_get_templates_lists_theme_file_with_null_wp_id() {
		$result = $this->tm->get_templates( array() );
		$index  = $this->find_by_slug( $result['templates'], 'index' );

		$this->assertNotNull( $index, 'Expected the theme file "index" template to be listed.' );
		$this->assertSame( 'theme', $index['source'] );
		$this->assertNull( $index['wp_id'] );
		$this->assertSame( 'wp_template', $index['type'] );
		$this->assertTrue( $index['has_theme_file'] );
	}

	/**
	 * Inserting a `wp_template` post tagged with the active theme's wp_theme
	 * term shadows the theme file: the same slug now lists as source
	 * "custom" with `wp_id` pointing at the override post — the field a
	 * template-write tool would key off to update or delete it.
	 */
	public function test_get_templates_customized_slug_lists_as_custom_with_wp_id() {
		$override_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'index',
				'post_title'   => 'Index',
				'post_content' => '<!-- wp:paragraph --><p>Custom index</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_object_terms( $override_id, $this->theme, 'wp_theme' );

		$result = $this->tm->get_templates( array() );
		$index  = $this->find_by_slug( $result['templates'], 'index' );

		$this->assertNotNull( $index );
		$this->assertSame( 'custom', $index['source'] );
		$this->assertSame( $override_id, $index['wp_id'] );

		// Exactly one "index" row — the DB override, not both.
		$matches = array_filter( $result['templates'], static fn( $t ) => 'index' === $t['slug'] );
		$this->assertCount( 1, $matches );
	}

	/**
	 * `area` scopes wp_template_part results to parts declared under that
	 * area in theme.json — the fixture theme declares "small-header" under
	 * the "header" area.
	 */
	public function test_get_templates_area_filter_scopes_template_parts() {
		$header = $this->tm->get_templates( array( 'type' => 'wp_template_part', 'area' => 'header' ) );
		$this->assertNotNull( $this->find_by_slug( $header['templates'], 'small-header' ) );

		$footer = $this->tm->get_templates( array( 'type' => 'wp_template_part', 'area' => 'footer' ) );
		$this->assertNull( $this->find_by_slug( $footer['templates'], 'small-header' ) );
	}

	/**
	 * A classic (non-block) theme has no block templates at all — the
	 * response is an empty, explained list rather than an error, so a
	 * caller can branch on `count` without special-casing an exception.
	 */
	public function test_get_templates_classic_theme_returns_empty_with_note() {
		switch_theme( 'default' );

		$result = $this->tm->get_templates( array() );

		$this->assertSame( array(), $result['templates'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertArrayHasKey( 'note', $result );
	}

	/**
	 * An unrecognized `type` is rejected before any query runs.
	 */
	public function test_get_templates_invalid_type_returns_error() {
		$result = $this->tm->get_templates( array( 'type' => 'wp_navigation' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );
	}

	// ── get_template(): single-template detail ───────────────────────

	/**
	 * A theme-file template returns its raw content plus parsed blocks,
	 * formatted the same way get_page_blocks() formats a post.
	 */
	public function test_get_template_returns_raw_content_and_parsed_blocks() {
		$result = $this->tm->get_template( $this->theme . '//index' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Index Template', $result['content'] );
		$this->assertNotEmpty( $result['blocks'] );
		$this->assertSame( 'core/paragraph', $result['blocks'][0]['name'] );
		$this->assertNull( $result['wp_id'] );
	}

	/**
	 * Once a DB override exists, get_template resolves the override's
	 * content (not the theme file's) and reports its post ID.
	 */
	public function test_get_template_reflects_db_override_once_customized() {
		$override_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'index',
				'post_title'   => 'Index',
				'post_content' => '<!-- wp:paragraph --><p>Overridden content</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_object_terms( $override_id, $this->theme, 'wp_theme' );

		$result = $this->tm->get_template( $this->theme . '//index' );

		$this->assertSame( $override_id, $result['wp_id'] );
		$this->assertSame( 'custom', $result['source'] );
		$this->assertStringContainsString( 'Overridden content', $result['content'] );
	}

	/**
	 * `title` is documented as a plain string on WP_Block_Template, but a
	 * filter could hand back a REST-shaped `{ raw, rendered }` array
	 * instead — get_template() must not leak that shape into its response.
	 */
	public function test_get_template_title_as_array_uses_rendered_field() {
		$filter = static function ( $template ) {
			if ( $template ) {
				$template->title = array(
					'raw'      => 'Index',
					'rendered' => 'Index (rendered)',
				);
			}
			return $template;
		};
		add_filter( 'get_block_template', $filter );

		$result = $this->tm->get_template( $this->theme . '//index' );

		remove_filter( 'get_block_template', $filter );

		$this->assertSame( 'Index (rendered)', $result['title'] );
	}

	/**
	 * An id that doesn't resolve to any template (DB or theme file) is a
	 * 404, not a fatal or an empty success payload.
	 */
	public function test_get_template_unknown_id_returns_not_found() {
		$result = $this->tm->get_template( $this->theme . '//does-not-exist' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	/**
	 * An empty id is a 400, not a 404 — the caller passed nothing to look
	 * up, which is a different failure than "looked up, didn't find it".
	 */
	public function test_get_template_missing_id_returns_400() {
		$result = $this->tm->get_template( '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}

	/**
	 * An unrecognized `type` is rejected the same way for the single-item
	 * lookup as it is for the list.
	 */
	public function test_get_template_invalid_type_returns_error() {
		$result = $this->tm->get_template( $this->theme . '//index', 'wp_navigation' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );
	}
}
