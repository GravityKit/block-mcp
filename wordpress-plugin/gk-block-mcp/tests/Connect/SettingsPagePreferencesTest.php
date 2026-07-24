<?php
/**
 * Settings_Page namespace-score / replacement-map preference contracts.
 *
 * Pins the site-aware, opinion-free preference model:
 *
 *  1. sanitize_preferences() stores `namespace_scores` overrides-only. A posted
 *     score equal to the family's resolved default (core => 90, everything else
 *     => 50) is dropped, so storage never accumulates shipped defaults and a
 *     reset genuinely reverts to default.
 *  2. sanitize_preferences() stores `replacement_map` as the admin's
 *     authoritative list, verbatim. No shipped defaults are merged in, so a
 *     removed mapping stays removed.
 *  3. render_page() builds the score table from a site-aware row universe:
 *     every block family registered on this site, plus any the admin has scored,
 *     plus any present in published content. Overridden rows expose a Reset
 *     control; unoverridden rows show a "default" marker; an in-content family
 *     whose plugin is not registered is flagged orphaned. There is no free-form
 *     "Add row" for namespaces.
 *  4. A posted row that carries a score but a blank name surfaces a settings
 *     error rather than vanishing silently.
 *  5. The sanitizer is idempotent, surviving core's first-write double-sanitize.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\Settings_Page;

/**
 * @covers \GravityKit\BlockMCP\Settings_Page
 */
class SettingsPagePreferencesTest extends WP_UnitTestCase {

	/**
	 * Reset request + settings-error + inventory-cache state around every test.
	 *
	 * sanitize_preferences() pushes onto the $wp_settings_errors global; the
	 * render tests read $_GET['tab'] and the inventory transient. A core block is
	 * registered so the `core` family is deterministically part of the row
	 * universe regardless of what the bootstrap registered.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_settings_errors;
		$wp_settings_errors = array();
		delete_transient( Block_Inventory::CACHE_KEY );

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'core/paragraph' ) ) {
			$registry->register( 'core/paragraph' );
		}
	}

	/**
	 * @return void
	 */
	public function tear_down() {
		global $wp_settings_errors;
		$wp_settings_errors = array();
		unset( $_GET['tab'] );
		delete_transient( Block_Inventory::CACHE_KEY );
		parent::tear_down();
	}

	/**
	 * Render the policy tab and return the emitted HTML.
	 *
	 * @return string
	 */
	private function render_policy_html(): string {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'policy';

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		return (string) ob_get_clean();
	}

	// -- Contract 1: sanitize stores namespace scores overrides-only --

	/**
	 * A score below a family's default is stored as an override.
	 *
	 * Scoring a namespace down (jetpack => 0, well under the neutral 50) is the
	 * explicit way a site marks it legacy, so it must persist verbatim.
	 */
	public function test_sanitize_preferences_stores_below_default_score_as_override() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => 'jetpack', 'score' => 0 ) ) )
		);

		$this->assertSame( 0, $out['namespace_scores']['jetpack'], 'a score below default must persist as an override' );
	}

	/**
	 * A score above the neutral default is stored as an override.
	 *
	 * Promoting a namespace (spectra => 85, above the neutral 50) is equally an
	 * override and must persist.
	 */
	public function test_sanitize_preferences_stores_above_neutral_score_as_override() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => 'spectra', 'score' => 85 ) ) )
		);

		$this->assertSame( 85, $out['namespace_scores']['spectra'], 'a score above the neutral default must persist as an override' );
	}

	/**
	 * A posted score equal to `core`'s shipped default (90) is not stored.
	 *
	 * core resolves to 90 with no override; echoing that value back is not an
	 * override, so storage must stay empty rather than pinning the default.
	 */
	public function test_sanitize_preferences_drops_core_score_equal_to_default() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => 'core', 'score' => 90 ) ) )
		);

		$this->assertArrayNotHasKey( 'core', $out['namespace_scores'], 'a score equal to core\'s default must not be stored' );
	}

	/**
	 * A posted score equal to the neutral default (50) is not stored.
	 *
	 * Every non-core namespace resolves to 50; a row that merely echoes 50 leaves
	 * no override, so it must be dropped.
	 */
	public function test_sanitize_preferences_drops_score_equal_to_neutral_default() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => 'acme', 'score' => 50 ) ) )
		);

		$this->assertArrayNotHasKey( 'acme', $out['namespace_scores'], 'a score equal to the neutral default must not be stored' );
	}

	/**
	 * A partial submission stores only what was posted, layering no shipped
	 * defaults underneath.
	 *
	 * The opinion-free model ships only `core` as a default and resolves it at
	 * read time, so a submission scoring one namespace must produce exactly that
	 * one override, never a `core` (or any other) row pinned into storage.
	 */
	public function test_sanitize_preferences_does_not_layer_shipped_defaults() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => 'jetpack', 'score' => 0 ) ) )
		);

		$this->assertSame( array( 'jetpack' => 0 ), $out['namespace_scores'], 'storage must hold only the posted override, with no shipped defaults layered in' );
	}

	/**
	 * Out-of-range scores are clamped to 0-100 before the overrides-only check.
	 *
	 * A clamped value that differs from the default is still an override; pins the
	 * clamp boundary alongside the overrides-only drop.
	 */
	public function test_sanitize_preferences_clamps_out_of_range_scores() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array(
				'namespace_rows' => array(
					array( 'name' => 'tooboig', 'score' => 250 ),
					array( 'name' => 'toolow', 'score' => -5 ),
				),
			)
		);

		$this->assertSame( 100, $out['namespace_scores']['tooboig'], 'a score above 100 must clamp to 100' );
		$this->assertSame( 0, $out['namespace_scores']['toolow'], 'a score below 0 must clamp to 0' );
	}

	// -- Contract 2: sanitize stores the replacement map authoritatively --

	/**
	 * Posted replacement rows are stored verbatim with no defaults merged.
	 *
	 * The replacement map is the admin's authoritative list, not a shipped
	 * opinion, so the stored map must equal exactly what was posted.
	 */
	public function test_sanitize_preferences_stores_replacement_map_verbatim() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array( 'replacement_rows' => array( array( 'from' => 'foo/bar', 'to' => 'core/paragraph' ) ) )
		);

		$this->assertSame( array( 'foo/bar' => 'core/paragraph' ), $out['replacement_map'], 'the replacement map must be stored verbatim, with no shipped defaults merged' );
	}

	/**
	 * An empty replacement-rows submission yields an empty map, not shipped
	 * defaults.
	 *
	 * Clearing every replacement row must clear the stored map; a "default" map
	 * reappearing here would contradict the authoritative-list contract.
	 */
	public function test_sanitize_preferences_empty_replacement_rows_yield_empty_map() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences( array( 'replacement_rows' => array() ) );

		$this->assertSame( array(), $out['replacement_map'], 'clearing every replacement row must leave an empty map' );
	}

	// -- Contract 3: render builds a site-aware row universe --

	/**
	 * A block family registered on this site appears in the score table.
	 *
	 * The row universe always includes registered families; `core` is registered,
	 * so its row must render.
	 */
	public function test_render_page_includes_registered_core_family() {
		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="core"', $html, 'a registered block family must render a score row' );
	}

	/**
	 * An overridden namespace renders with a Reset control.
	 *
	 * A stored override (jetpack => 0) joins the row universe even though jetpack
	 * is not registered, and its row offers Reset so the admin can revert it.
	 */
	public function test_render_page_overridden_namespace_shows_reset_control() {
		update_option( Preferences::OPTION_KEY, array( 'namespace_scores' => array( 'jetpack' => 0 ) ) );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="jetpack"', $html, 'a stored override must render a row' );
		$this->assertStringContainsString( 'gk-block-mcp-reset-row', $html, 'an overridden row must expose a Reset control' );
	}

	/**
	 * An unoverridden, registered family renders a "default" marker, not Reset.
	 *
	 * With no override stored, `core` resolves to its default and the action cell
	 * shows the muted default marker, since there is nothing to reset.
	 */
	public function test_render_page_unoverridden_family_shows_default_marker() {
		delete_option( Preferences::OPTION_KEY );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'gk-block-mcp-row-default', $html, 'an unoverridden family must show the default marker' );
	}

	/**
	 * A family present in content but not registered is flagged orphaned.
	 *
	 * The in-content leg of the row universe reads the inventory cache; a
	 * namespace found there whose plugin is not registered renders with the
	 * orphaned marker so the admin knows it is inactive.
	 */
	public function test_render_page_flags_orphaned_in_content_namespace() {
		set_transient(
			Block_Inventory::CACHE_KEY,
			array( 'namespace_totals' => array( 'ghostpkg' => 3 ) )
		);

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="ghostpkg"', $html, 'an in-content namespace must join the row universe' );
		$this->assertStringContainsString( 'gk-block-mcp-row-orphaned', $html, 'an in-content namespace with no registered plugin must be flagged orphaned' );
	}

	/**
	 * The orphaned cue is programmatically associated with the row's controls.
	 *
	 * A screen-reader user editing an orphaned family typically tabs straight to
	 * the score field, so the "not active on this site" context must reach them
	 * there, not only as loose text or a title tooltip. The orphaned cue carries a
	 * stable id, the score and name inputs reference it via aria-describedby, and
	 * the full explanation is exposed as screen-reader text rather than a
	 * title-only attribute.
	 */
	public function test_render_page_associates_orphaned_cue_with_the_row_controls() {
		set_transient(
			Block_Inventory::CACHE_KEY,
			array( 'namespace_totals' => array( 'ghostpkg' => 3 ) )
		);

		$html = $this->render_policy_html();

		$this->assertMatchesRegularExpression( '/id="(gk-ns-orphaned-\d+)"/', $html, 'the orphaned cue must carry a stable id' );
		preg_match( '/id="(gk-ns-orphaned-\d+)"/', $html, $matches );
		$orphan_id = $matches[1];

		$this->assertStringContainsString( 'aria-describedby="' . $orphan_id . '"', $html, 'an editable control in the orphaned row must be described by the orphaned cue' );
		$this->assertStringContainsString( 'This block family appears in your content', $html, 'the full orphaned explanation must be present as text, not only a title attribute' );
	}

	/**
	 * A namespace that is neither registered, overridden, nor in content does not
	 * appear.
	 *
	 * The row universe is exactly registered union overridden union in-content;
	 * an arbitrary unknown namespace must never render a row.
	 */
	public function test_render_page_omits_namespace_outside_the_row_universe() {
		delete_option( Preferences::OPTION_KEY );

		$html = $this->render_policy_html();

		$this->assertStringNotContainsString( 'value="zzfakepkg"', $html, 'a namespace outside the row universe must not render' );
	}

	/**
	 * The namespace score table offers no free-form "Add row".
	 *
	 * You score the families on your site, not a hypothetical list, so the
	 * namespace table must not render an Add-row control (the replacement table
	 * keeps its own).
	 */
	public function test_render_page_namespace_table_has_no_add_row() {
		$html = $this->render_policy_html();

		$this->assertStringNotContainsString( 'data-target-table="namespace"', $html, 'the namespace score table must not offer a free-form Add row' );
	}

	/**
	 * A stored replacement mapping renders in the replacement table.
	 *
	 * The authoritative map is shown verbatim.
	 */
	public function test_render_page_shows_stored_replacement_mapping() {
		update_option( Preferences::OPTION_KEY, array( 'replacement_map' => array( 'foo/bar' => 'core/paragraph' ) ) );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="foo/bar"', $html, 'a stored replacement mapping must render' );
		$this->assertStringContainsString( 'value="core/paragraph"', $html, 'the replacement target must render' );
	}

	// -- Post-upgrade notice (migration flag) --

	/**
	 * The post-upgrade notice renders when the migration flag is set.
	 *
	 * A site that had saved preferences before the site-aware model gets a
	 * one-time notice telling them their settings were preserved and offering a
	 * review or reset.
	 */
	public function test_render_page_shows_upgrade_notice_when_migration_flag_set() {
		update_option( 'gk_block_api_preferences_notice', '1' );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'gk-block-mcp-prefs-upgrade-notice', $html, 'the post-upgrade notice must render while the migration flag is set' );
	}

	/**
	 * The post-upgrade notice does not render once the flag is absent.
	 *
	 * After dismissal or reset (the flag is gone), the notice must not reappear.
	 */
	public function test_render_page_omits_upgrade_notice_when_flag_absent() {
		delete_option( 'gk_block_api_preferences_notice' );

		$html = $this->render_policy_html();

		$this->assertStringNotContainsString( 'gk-block-mcp-prefs-upgrade-notice', $html, 'the post-upgrade notice must not render without the migration flag' );
	}

	// -- Contract 4: silent row-drop must surface feedback --

	/**
	 * A namespace row with a score but a blank name raises a settings error.
	 *
	 * A score typed with no family name cannot key the map; dropping it without
	 * notice loses the admin's edit, so the sanitizer must register a warning.
	 */
	public function test_sanitize_preferences_flags_namespace_score_with_blank_name() {
		$page = new Settings_Page( new Block_Inventory() );

		$page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => '', 'score' => 90 ) ) )
		);

		$errors = get_settings_errors( Preferences::OPTION_KEY );
		$this->assertNotEmpty( $errors, 'a scored row with no name must surface a settings error' );
	}

	/**
	 * A fully-empty row (no name, no score) does not raise a settings error.
	 *
	 * Only a row the admin actually started filling is worth a warning; a blank
	 * row must save silently.
	 */
	public function test_sanitize_preferences_does_not_flag_fully_empty_row() {
		$page = new Settings_Page( new Block_Inventory() );

		$page->sanitize_preferences(
			array( 'namespace_rows' => array( array( 'name' => '', 'score' => 0 ) ) )
		);

		$errors = get_settings_errors( Preferences::OPTION_KEY );
		$this->assertEmpty( $errors, 'an untouched blank row must not warn' );
	}

	// -- Contract 5: sanitizer is idempotent (survives core's double-sanitize) --

	/**
	 * Sanitizing the sanitizer's own output is a no-op, not a wipe.
	 *
	 * Core double-sanitizes an option on its first write (update_option() then
	 * add_option()), so the second pass receives the canonical
	 * {namespace_scores, replacement_map} shape. Re-running must preserve the
	 * overrides exactly.
	 */
	public function test_sanitize_preferences_is_idempotent_on_its_own_output() {
		$page = new Settings_Page( new Block_Inventory() );

		$once = $page->sanitize_preferences(
			array(
				'namespace_rows'   => array( array( 'name' => 'spectra', 'score' => 75 ) ),
				'replacement_rows' => array( array( 'from' => 'foo/bar', 'to' => 'core/paragraph' ) ),
			)
		);
		$twice = $page->sanitize_preferences( $once );

		$this->assertSame( $once, $twice, 'a second sanitize pass must not change the value' );
		$this->assertSame( 75, $twice['namespace_scores']['spectra'], 'the namespace override must survive re-sanitization' );
		$this->assertSame( 'core/paragraph', $twice['replacement_map']['foo/bar'], 'the replacement override must survive re-sanitization' );
	}

	/**
	 * The first-ever save of the preferences option persists overrides-only.
	 *
	 * Exercises the real mechanism: with the setting registered, the first
	 * update_option() routes through add_option() and runs the sanitize callback
	 * twice on its own output. The override survives, and no shipped default is
	 * layered into storage.
	 */
	public function test_first_save_of_new_option_persists_overrides_only() {
		$page = new Settings_Page( new Block_Inventory() );
		$page->register_settings();
		delete_option( Preferences::OPTION_KEY );

		update_option(
			Preferences::OPTION_KEY,
			array( 'namespace_rows' => array( array( 'name' => 'spectra', 'score' => 75 ) ) )
		);

		$stored = get_option( Preferences::OPTION_KEY );
		$this->assertIsArray( $stored['namespace_scores'] ?? null, 'the first save must persist namespace scores' );
		$this->assertSame( 75, $stored['namespace_scores']['spectra'], 'the saved override must persist' );
		$this->assertArrayNotHasKey( 'core', $stored['namespace_scores'], 'no shipped default may be layered into storage on first save' );
	}

	/**
	 * The "What AI assistants can create" section renders an explicit "Allow all"
	 * toggle, and the score table renders a plain-language tier badge, so a
	 * non-technical admin never faces an empty-means-all checkbox set or a bare
	 * numeric score.
	 */
	public function test_policy_tab_renders_allow_all_toggle_and_tier_badge() {
		$html = $this->render_policy_html();
		$this->assertStringContainsString( 'gk-block-mcp-allow-all', $html, 'the Allow-all-content-types toggle must render' );
		$this->assertStringContainsString( 'Allow all content types', $html );
		$this->assertStringContainsString( 'gk-block-mcp-tier', $html, 'the score tier badge must render' );
		$this->assertStringContainsString( 'Preferred', $html, 'core (90) must show the Preferred tier label' );
	}

	/**
	 * score_tier_label() maps a 0–100 score to the engine's tier wording so the
	 * badge always matches the thresholds enforced at runtime.
	 */
	public function test_score_tier_label_matches_engine_thresholds() {
		$method = new \ReflectionMethod( Settings_Page::class, 'score_tier_label' );
		$method->setAccessible( true );
		$page = new Settings_Page( new Block_Inventory() );

		$this->assertSame( 'Preferred', $method->invoke( $page, 90 ) );
		$this->assertSame( 'Preferred', $method->invoke( $page, 80 ) );
		$this->assertSame( 'Fine', $method->invoke( $page, 50 ) );
		$this->assertSame( 'Discouraged', $method->invoke( $page, 10 ) );
		$this->assertSame( 'Blocked', $method->invoke( $page, 9 ) );
		$this->assertSame( 'Blocked', $method->invoke( $page, 0 ) );
	}

	/**
	 * The trailing "new" replacement row (the one Add row clones) renders a Remove
	 * control, so a row you type into or add is removable without first saving.
	 * It previously had an empty action cell, leaving typed/added rows with no
	 * Remove button.
	 */
	public function test_replacement_table_new_row_has_remove_control() {
		delete_option( Preferences::OPTION_KEY );
		$html = $this->render_policy_html();
		$this->assertStringContainsString( 'Remove this row', $html, 'the replacement new/template row must render a Remove control even with no stored mappings' );
	}

	// -- Security toggles reflect the stored option, not a filter override --

	/**
	 * Each security toggle whose checkbox must mirror the stored option.
	 *
	 * Columns: option key, its override filter, the stored value, the value the
	 * filter forces (opposite of stored), and the checkbox state the box must
	 * render (which follows the STORED value, never the filter).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function security_toggle_override_provider(): array {
		return array(
			'uploads stored on, filter forces off'   => array( \GravityKit\BlockMCP\Media_Manager::UPLOADS_OPTION, 'gk/block-mcp/media/uploads-enabled', '1', false, true ),
			'trash stored off, filter forces on'     => array( \GravityKit\BlockMCP\Post_Manager::ALLOW_TRASH_OPTION, 'gk/block-mcp/post/allow-trash', '0', true, false ),
			'templates stored off, filter forces on' => array( \GravityKit\BlockMCP\Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, 'gk/block-mcp/templates/allow-edits', '0', true, false ),
			'abilities stored off, filter forces on' => array( \GravityKit\BlockMCP\Block_Abilities::ENABLED_OPTION, 'gk/block-mcp/abilities/enabled', '0', true, false ),
		);
	}

	/**
	 * A security toggle's checkbox renders from the stored option, never the
	 * post-filter effective value.
	 *
	 * The checkbox is the form control that POSTs back to the option. When a
	 * gk/block-mcp/* filter overrides the stored value and the box is rendered
	 * from that effective value, saving any setting silently writes the filtered
	 * value into the option — flipping the admin's persisted security choice
	 * (e.g. template-edit or trash access) with no interaction. The box must
	 * follow the stored value; the divergence is surfaced by the "Heads up"
	 * override notice instead. Pins all four toggles (uploads, trash, template
	 * editing, Abilities) against that persist-on-save regression.
	 *
	 * @dataProvider security_toggle_override_provider
	 *
	 * @param string $option         Option key backing the toggle.
	 * @param string $filter         Filter that overrides the stored value.
	 * @param string $stored         Stored option value ('0' or '1').
	 * @param bool   $filter_forces  Value the filter forces (opposite of stored).
	 * @param bool   $expect_checked Whether the checkbox must render checked.
	 * @return void
	 */
	public function test_policy_tab_security_toggle_checkbox_reflects_stored_option_not_filter_override( string $option, string $filter, string $stored, bool $filter_forces, bool $expect_checked ) {
		update_option( $option, $stored );
		add_filter( $filter, $filter_forces ? '__return_true' : '__return_false' );

		$html = $this->render_policy_html();

		$this->assertSame(
			$expect_checked,
			$this->checkbox_is_checked( $html, $option ),
			sprintf( 'the %s checkbox must reflect the stored option (%s), not the filter override', $option, $stored )
		);
		$this->assertStringContainsString(
			'<code>' . $filter . '</code>',
			$html,
			'a filter diverging from the stored value must surface the Heads-up override notice naming the filter'
		);
	}

	/**
	 * Reports whether the checkbox <input> for an option renders as checked.
	 *
	 * Targets the checkbox input (type=checkbox, the one that carries the checked
	 * attribute), not the paired hidden value="0" input of the same name.
	 *
	 * @param string $html   Rendered settings HTML.
	 * @param string $option Option key naming the input.
	 * @return bool
	 */
	private function checkbox_is_checked( string $html, string $option ): bool {
		$pattern = '/<input\s+type="checkbox"[^>]*\bname="' . preg_quote( $option, '/' ) . '"[^>]*>/';
		if ( ! preg_match( $pattern, $html, $m ) ) {
			$this->fail( sprintf( 'no checkbox input found for option %s', $option ) );
		}
		return false !== strpos( $m[0], 'checked' );
	}

}
