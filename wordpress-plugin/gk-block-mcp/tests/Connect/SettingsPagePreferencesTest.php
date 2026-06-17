<?php
/**
 * Settings_Page namespace-score / replacement-map preference contracts.
 *
 * Pins the "stored preferences layer over shipped defaults" contract. Three
 * seams where the stored option and the hardcoded GravityKit defaults must agree
 * on the same "override layered on defaults" model the runtime uses:
 *
 *  1. render_page() shows the shipped namespace/replacement defaults even when a
 *     single custom entry is stored — the UI mirrors the merged view
 *     Preferences::get_preferences() enforces at runtime, never the raw stored
 *     subset.
 *  2. sanitize_preferences() layers the posted rows OVER
 *     Preferences::get_defaults(), so a partial submission cannot erase the
 *     shipped defaults from storage.
 *  3. A posted row that carries a score/value but a blank name surfaces a
 *     settings error rather than being dropped silently.
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
	 * Reset request + settings-error state around every test.
	 *
	 * sanitize_preferences() pushes onto the $wp_settings_errors global; the
	 * render tests read $_GET['tab']. Clear both so tests don't leak into one
	 * another.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_settings_errors;
		$wp_settings_errors = array();
	}

	/**
	 * @return void
	 */
	public function tear_down() {
		global $wp_settings_errors;
		$wp_settings_errors = array();
		unset( $_GET['tab'] );
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

	// ── Contract 1: render path layers stored over shipped defaults ──

	/**
	 * Storing a single custom namespace score must not hide the shipped
	 * namespace defaults from the score table.
	 *
	 * The runtime (Preferences::get_preferences()) always layers stored scores
	 * over the 11 hardcoded defaults via wp_parse_args, so the settings table
	 * must render that same merged set — otherwise the admin sees a table that
	 * contradicts the policy actually in force.
	 */
	public function test_render_page_shows_shipped_namespace_defaults_alongside_stored_entry() {
		update_option( Preferences::OPTION_KEY, array( 'namespace_scores' => array( 'mything' => 90 ) ) );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="mything"', $html, 'the stored custom namespace row must render' );
		$this->assertStringContainsString( 'value="jetpack"', $html, 'a shipped namespace default must still render' );
		$this->assertStringContainsString( 'value="stackable"', $html, 'a second shipped namespace default must still render' );
	}

	/**
	 * Storing a single custom replacement must not hide the shipped
	 * replacement-map defaults from the table.
	 *
	 * Same layering contract as the namespace table, for the parallel
	 * replacement-map section.
	 */
	public function test_render_page_shows_shipped_replacement_defaults_alongside_stored_entry() {
		update_option( Preferences::OPTION_KEY, array( 'replacement_map' => array( 'foo/bar' => 'core/paragraph' ) ) );

		$html = $this->render_policy_html();

		$this->assertStringContainsString( 'value="foo/bar"', $html, 'the stored custom replacement row must render' );
		$this->assertStringContainsString( 'value="stackable/heading"', $html, 'a shipped replacement default must still render' );
		$this->assertStringContainsString( 'value="ugb/columns"', $html, 'a second shipped replacement default must still render' );
	}

	// ── Contract 2: sanitize path layers posted rows over shipped defaults ──

	/**
	 * A partial namespace submission layers over the shipped defaults, never
	 * replaces them.
	 *
	 * Only a subset of rows can reach the sanitizer (the UI may render fewer, or a
	 * row may not post). The sanitizer merges posted rows onto
	 * Preferences::get_defaults() so the stored value is always defaults +
	 * overrides, never a lossy subset.
	 */
	public function test_sanitize_preferences_layers_namespace_scores_over_shipped_defaults() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array(
				'namespace_rows' => array(
					array(
						'name'  => 'mything',
						'score' => 90,
					),
				),
			)
		);

		$this->assertSame( 90, $out['namespace_scores']['mything'], 'the posted override must be stored' );
		$this->assertArrayHasKey( 'core', $out['namespace_scores'], 'a shipped default must survive a partial submission' );
		$this->assertSame( 90, $out['namespace_scores']['core'], 'the shipped default value must be preserved' );
		$this->assertArrayHasKey( 'jetpack', $out['namespace_scores'], 'every shipped default must survive' );
		$this->assertSame( 0, $out['namespace_scores']['jetpack'], 'the shipped default value must be preserved' );
	}

	/**
	 * A posted score for a shipped namespace must override the default value
	 * rather than being lost under it.
	 *
	 * Layering must keep posted values winning over the defaults — demoting
	 * core from 90 to 40 has to persist as 40.
	 */
	public function test_sanitize_preferences_posted_namespace_score_overrides_default() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array(
				'namespace_rows' => array(
					array(
						'name'  => 'core',
						'score' => 40,
					),
				),
			)
		);

		$this->assertSame( 40, $out['namespace_scores']['core'], 'the posted score must override the shipped default' );
	}

	/**
	 * A partial replacement submission must layer over the shipped defaults.
	 *
	 * Parallel to the namespace contract, for the replacement map.
	 */
	public function test_sanitize_preferences_layers_replacement_map_over_shipped_defaults() {
		$page = new Settings_Page( new Block_Inventory() );

		$out = $page->sanitize_preferences(
			array(
				'replacement_rows' => array(
					array(
						'from' => 'foo/bar',
						'to'   => 'core/paragraph',
					),
				),
			)
		);

		$this->assertSame( 'core/paragraph', $out['replacement_map']['foo/bar'], 'the posted replacement must be stored' );
		$this->assertArrayHasKey( 'stackable/heading', $out['replacement_map'], 'a shipped replacement default must survive' );
		$this->assertSame( 'core/heading', $out['replacement_map']['stackable/heading'], 'the shipped replacement target must be preserved' );
	}

	// ── Contract 3: silent row-drop must surface feedback ──

	/**
	 * A namespace row with a score but a blank name must raise a settings error,
	 * not vanish silently.
	 *
	 * The auto-grow table makes it easy to enter a score and never type a name
	 * (or to have a JS race swallow the row). The sanitizer drops nameless rows;
	 * doing so without feedback means the admin's edit disappears with no notice.
	 * The fix registers a settings error so the save screen warns them.
	 */
	public function test_sanitize_preferences_flags_namespace_score_with_blank_name() {
		$page = new Settings_Page( new Block_Inventory() );

		$page->sanitize_preferences(
			array(
				'namespace_rows' => array(
					array(
						'name'  => '',
						'score' => 90,
					),
				),
			)
		);

		$errors = get_settings_errors( Preferences::OPTION_KEY );
		$this->assertNotEmpty( $errors, 'a scored row with no name must surface a settings error' );
	}

	/**
	 * A fully-empty trailing row (no name, no score) is the normal "blank new
	 * row" and must NOT raise a settings error.
	 *
	 * The auto-grow table always submits one empty trailing row; warning on it
	 * would fire on every single save. Only a row the user actually started
	 * filling (a score with no name) is worth a warning.
	 */
	public function test_sanitize_preferences_does_not_flag_fully_empty_row() {
		$page = new Settings_Page( new Block_Inventory() );

		$page->sanitize_preferences(
			array(
				'namespace_rows' => array(
					array(
						'name'  => '',
						'score' => 0,
					),
				),
			)
		);

		$errors = get_settings_errors( Preferences::OPTION_KEY );
		$this->assertEmpty( $errors, 'an untouched blank trailing row must not warn' );
	}

	// ── Contract 4: sanitizer is idempotent (survives core's double-sanitize) ──

	/**
	 * Sanitizing the sanitizer's own output is a no-op, not a wipe.
	 *
	 * Core double-sanitizes an option on its first write: update_option()
	 * sanitizes, then delegates to add_option() (the option doesn't exist yet),
	 * which sanitizes again — so the second pass receives the canonical
	 * {namespace_scores, replacement_map} shape, not the form's indexed rows. The
	 * sanitizer accepts that canonical shape so a re-run preserves the value.
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
		$this->assertArrayHasKey( 'core', $twice['namespace_scores'], 'shipped defaults must survive re-sanitization' );
	}

	/**
	 * The first-ever save of the preferences option persists, not vanishes.
	 *
	 * Exercises the real mechanism: with the setting registered, updating a
	 * brand-new option routes update_option() through add_option(), which runs the
	 * sanitize callback a second time on its own output. The sanitizer's
	 * idempotency keeps that first save intact.
	 */
	public function test_first_save_of_new_option_persists_through_core_double_sanitize() {
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
		$this->assertArrayHasKey( 'core', $stored['namespace_scores'], 'shipped defaults must persist on first save' );
	}
}
