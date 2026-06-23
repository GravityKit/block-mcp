<?php
/**
 * Site-aware preferences schema migration contracts.
 *
 * The 2.1 model makes block preferences site-aware and opinion-free. Sites that
 * saved preferences under the older model must keep them untouched, so the
 * migration:
 *
 *  1. Stamps the current schema version so it runs once, including on an
 *     auto-update that swaps plugin files without re-activation (the activation
 *     hook would never fire there). On multisite it migrates the current blog
 *     lazily on first request rather than fanning out over every site.
 *  2. Never drops or rewrites a saved value: the opinion-free read layer already
 *     takes stored preferences verbatim, so the migration leaves them exactly as
 *     saved.
 *  3. Flags a one-time admin notice only for a site that had saved preferences,
 *     so the admin learns the model is now site-aware and can review or reset.
 *
 * @package GravityKit\BlockMCP\Tests\Preferences
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Preferences;

/**
 * @covers \GravityKit\BlockMCP\maybe_migrate
 */
class PreferencesMigrationTest extends WP_UnitTestCase {

	/**
	 * Reset the schema version, notice flag, saved preferences, and inventory
	 * cache so each test controls the full starting state. maybe_migrate() runs
	 * once during the test bootstrap; clearing here isolates every case.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		delete_option( \GravityKit\BlockMCP\DB_VERSION_OPTION );
		delete_option( \GravityKit\BlockMCP\PREFERENCES_NOTICE_OPTION );
		delete_option( Preferences::OPTION_KEY );
		delete_transient( Block_Inventory::CACHE_KEY );
	}

	/**
	 * A representative option as a pre-site-aware site would have stored it: the
	 * old model layered shipped defaults into storage, so the saved option holds
	 * a full namespace set plus a replacement map.
	 *
	 * @return array<string, array>
	 */
	private function pre_site_aware_saved_preferences(): array {
		return array(
			'namespace_scores' => array( 'core' => 90, 'jetpack' => 0, 'stackable' => 10 ),
			'replacement_map'  => array( 'ugb/text' => 'core/paragraph' ),
		);
	}

	/**
	 * A site with no stored schema version is stamped to the current version.
	 *
	 * Pins that the migration runs on a pre-version-option site and records the
	 * version so it never re-runs.
	 */
	public function test_maybe_migrate_stamps_the_current_schema_version() {
		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertSame(
			\GravityKit\BlockMCP\CURRENT_DB_VERSION,
			get_option( \GravityKit\BlockMCP\DB_VERSION_OPTION ),
			'the migration must stamp the current schema version'
		);
	}

	/**
	 * Saved preferences survive the migration byte-for-byte.
	 *
	 * The opinion-free read layer takes stored values verbatim, so the migration
	 * must not rewrite or drop anything the admin's site relied on.
	 */
	public function test_maybe_migrate_preserves_saved_preferences_verbatim() {
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );
		$saved = $this->pre_site_aware_saved_preferences();
		update_option( Preferences::OPTION_KEY, $saved );

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertSame( $saved, get_option( Preferences::OPTION_KEY ), 'saved preferences must survive the migration unchanged' );
	}

	/**
	 * A site that had saved preferences gets the one-time review notice.
	 *
	 * The notice tells the admin their saved settings were preserved under the
	 * now site-aware model and offers a reset.
	 */
	public function test_maybe_migrate_flags_notice_for_site_with_saved_preferences() {
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );
		update_option( Preferences::OPTION_KEY, $this->pre_site_aware_saved_preferences() );

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertSame( '1', get_option( \GravityKit\BlockMCP\PREFERENCES_NOTICE_OPTION ), 'a site with saved preferences must get the one-time review notice' );
	}

	/**
	 * A site with no saved preferences never sees the notice.
	 *
	 * A fresh or never-configured site simply adopts the opinion-free defaults;
	 * there is nothing to review, so the notice must stay off.
	 */
	public function test_maybe_migrate_does_not_flag_notice_without_saved_preferences() {
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertFalse( get_option( \GravityKit\BlockMCP\PREFERENCES_NOTICE_OPTION ), 'a site with no saved preferences must not see the notice' );
	}

	/**
	 * An already-current site does not re-run the migration.
	 *
	 * With the version already stamped, maybe_migrate() must short-circuit and
	 * leave no side effects (here: no notice flag), so it is safe on every
	 * request.
	 */
	public function test_maybe_migrate_is_a_noop_when_already_current() {
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, \GravityKit\BlockMCP\CURRENT_DB_VERSION, false );
		update_option( Preferences::OPTION_KEY, $this->pre_site_aware_saved_preferences() );

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertFalse( get_option( \GravityKit\BlockMCP\PREFERENCES_NOTICE_OPTION ), 'an already-current site must not re-run the migration' );
	}

	/**
	 * A schema upgrade drops the inventory cache written by the old schema.
	 *
	 * Mirrors the activation handler's self-healing: a stale cache from an older
	 * version must not survive into the new one.
	 */
	public function test_maybe_migrate_clears_the_inventory_cache_on_upgrade() {
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );
		set_transient( Block_Inventory::CACHE_KEY, array( 'namespace_totals' => array( 'core' => 1 ) ) );

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertFalse( get_transient( Block_Inventory::CACHE_KEY ), 'a schema upgrade must drop the inventory cache written by the old schema' );
	}

	/**
	 * On multisite, a request against one blog migrates only that blog.
	 *
	 * The schema version is a per-blog option, so maybe_migrate() on plugins_loaded
	 * stamps the current blog lazily and leaves sibling blogs to migrate on their
	 * own first request. Pins that a sibling blog is untouched.
	 *
	 * @group ms-required
	 */
	public function test_maybe_migrate_stamps_only_the_current_blog() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only contract.' );
		}

		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );

		$other_blog = self::factory()->blog->create();
		switch_to_blog( $other_blog );
		update_option( \GravityKit\BlockMCP\DB_VERSION_OPTION, '1.4.2', false );
		restore_current_blog();

		\GravityKit\BlockMCP\maybe_migrate();

		$this->assertSame(
			\GravityKit\BlockMCP\CURRENT_DB_VERSION,
			get_option( \GravityKit\BlockMCP\DB_VERSION_OPTION ),
			'the current blog must be migrated'
		);

		switch_to_blog( $other_blog );
		$sibling_version = get_option( \GravityKit\BlockMCP\DB_VERSION_OPTION );
		restore_current_blog();

		$this->assertSame( '1.4.2', $sibling_version, 'a sibling blog must not be migrated by another blog request' );
	}
}
