<?php
/**
 * Abilities API coverage for the templates tool group
 * (list-templates, get-template, update-template, reset-template).
 *
 * Registration presence and annotation counts live in AbilitiesRegistryTest;
 * this file covers execution, including the write pair's gate parity with
 * their REST twins (POST /template, POST /template/reset).
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Abilities_Registry;
use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Template_Manager;
use GravityKit\BlockMCP\Tool_Executor;
use GravityKit\BlockMCP\Yoast_Bridge;

class TemplateAbilitiesTest extends RestControllerTestCase {

	/** @var string Stylesheet slug of the active block theme for this test run. */
	private $theme;

	/**
	 * Registration defaults to opt-in (off); enable it the same way
	 * AbilitiesRegistryTest does, before any test can trigger the Abilities
	 * API's first-touch bootstrap. See that file's set_up() docblock for why
	 * this must happen unconditionally here too, not only there.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );

		$this->ensure_theme_root_resolvable();
		$block_theme = $this->find_block_theme();
		if ( null === $block_theme ) {
			$this->markTestSkipped( 'No block theme is available in this test environment.' );
		}
		switch_theme( $block_theme );
		$this->theme = $block_theme;
	}

	/**
	 * Work around a wp-phpunit quirk that breaks switch_theme() for any
	 * fixture theme other than the WP_DEFAULT_THEME. See
	 * TemplateManagerTest::ensure_theme_root_resolvable() (tests/Templates/)
	 * for the rationale.
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
	 * Find a standalone (non-child) block theme in the test theme root. See
	 * TemplateManagerTest::find_block_theme() (tests/Templates/) for the
	 * rationale.
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

	// ── Registration ───────────────────────────────────────────────────

	/**
	 * All four templates tools register as abilities under the expected
	 * dashed ids.
	 */
	public function test_template_abilities_are_registered() {
		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$ids = $registry->get_ability_ids();
		$this->assertContains( 'gk-block-mcp/list-templates', $ids );
		$this->assertContains( 'gk-block-mcp/get-template', $ids );
		$this->assertContains( 'gk-block-mcp/update-template', $ids );
		$this->assertContains( 'gk-block-mcp/reset-template', $ids );
	}

	// ── Read abilities ─────────────────────────────────────────────────

	/**
	 * list-templates lists the active theme's templates, matching its REST
	 * twin's response shape (no gate — read permission only).
	 */
	public function test_list_templates_ability_returns_templates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/list-templates' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['templates'] );
		$slugs = wp_list_pluck( $result['templates'], 'slug' );
		$this->assertContains( 'index', $slugs );
	}

	/**
	 * get-template returns a template's raw content and parsed blocks.
	 */
	public function test_get_template_ability_returns_content_and_blocks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-template' )->execute( array( 'id' => $this->theme . '//index' ) );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNull( $result['wp_id'] );
	}

	/**
	 * list-templates denies a subscriber (lacks the global edit_posts
	 * capability the 'read' permission branch checks), matching every other
	 * read-permission ability.
	 */
	public function test_list_templates_ability_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/list-templates' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	// ── Write abilities: gate parity with the REST routes ─────────────

	/**
	 * With the toggle off (the default), update-template must deny an
	 * editor even though edit_posts alone would satisfy every other write
	 * ability's permission branch — proving the Abilities surface honors
	 * gk_block_api_template_edits and cannot be used to bypass the REST
	 * route's gate.
	 */
	public function test_update_template_ability_denies_when_gate_off() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>Ability write</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * reset-template is gated the same way as update-template.
	 */
	public function test_reset_template_ability_denies_when_gate_off() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/reset-template' )->execute( array( 'id' => $this->theme . '//index' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * The gk/block-mcp/templates/allow-edits filter is honored by the
	 * ability path exactly as it is by the REST route: it can force editing
	 * off even when the stored option is on.
	 */
	public function test_update_template_ability_denies_when_filter_forces_off() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$filter = static fn() => false;
		add_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		remove_filter( 'gk/block-mcp/templates/allow-edits', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * With the toggle on, an editor (edit_posts, no edit_theme_options) can
	 * write via the ability — matching the REST route's two-part permission
	 * callback (toggle ON and edit_posts OR edit_theme_options) — and the
	 * change round-trips through get-template.
	 */
	public function test_update_template_ability_persists_change_when_gate_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>ABILITY-MARKER</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['override_created'] );
		$this->assertGreaterThan( 0, $result['wp_id'] );

		$read = wp_get_ability( 'gk-block-mcp/get-template' )->execute( array( 'id' => $this->theme . '//index' ) );
		$this->assertNotWPError( $read );
		$this->assertSame( 'custom', $read['source'] );
		$this->assertStringContainsString( 'ABILITY-MARKER', $read['content'] );
	}

	/**
	 * With the toggle on, an actor holding neither edit_posts nor
	 * edit_theme_options (a subscriber) is still denied — the toggle widens
	 * what an already-capable actor may do, it does not replace the
	 * capability check. Mirrors TemplatesRestTest's REST-level coverage of
	 * the same branch.
	 */
	public function test_update_template_ability_denies_subscriber_even_with_gate_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * An actor with edit_theme_options but NOT edit_posts (the other half
	 * of the gate's capability check) can also write via the ability —
	 * the path a "self" (human admin) connection uses.
	 */
	public function test_update_template_ability_succeeds_via_edit_theme_options_alone() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$role_name = 'gk_test_ability_theme_options_only';
		add_role( $role_name, 'Theme Options Only', array( 'read' => true, 'edit_theme_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		remove_role( $role_name );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Resetting an override through the ability deletes it and get-template
	 * reports the theme file's content again — the ability path's full
	 * round trip, matching TemplatesRestTest's REST-level coverage.
	 */
	public function test_reset_template_ability_deletes_override_when_gate_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$created = wp_get_ability( 'gk-block-mcp/update-template' )->execute(
			array(
				'id'      => $this->theme . '//index',
				'content' => '<!-- wp:paragraph --><p>Overridden</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertNotWPError( $created );

		$reset = wp_get_ability( 'gk-block-mcp/reset-template' )->execute( array( 'id' => $this->theme . '//index' ) );

		$this->assertNotWPError( $reset );
		$this->assertTrue( $reset['success'] );
		$this->assertSame( $created['wp_id'], $reset['wp_id'] );
		$this->assertNull( get_post( $created['wp_id'] ) );

		$read = wp_get_ability( 'gk-block-mcp/get-template' )->execute( array( 'id' => $this->theme . '//index' ) );
		$this->assertNotWPError( $read );
		$this->assertSame( 'theme', $read['source'] );
		$this->assertNull( $read['wp_id'] );
	}
}
