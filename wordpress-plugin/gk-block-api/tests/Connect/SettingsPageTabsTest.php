<?php
/**
 * Settings_Page tab-navigation contracts.
 *
 * Pins the tab-nav integration introduced when the Connect onboarding was
 * moved from its own admin menu page into the Block MCP Settings page as
 * the default tab. Two contracts:
 *
 *  1. The Settings page renders a <h2 class="nav-tab-wrapper"> containing
 *     links for both the "Connect" and "Block policy" tabs.
 *  2. When no tab is specified (or tab=connect), the Connect onboarding
 *     content ("Connect an AI Assistant") is rendered by default.
 *  3. When tab=policy, the block-policy form is rendered instead.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Settings_Page;

/**
 * Tests for Settings_Page tab navigation and Connect tab embedding.
 *
 * @covers \GravityKit\BlockAPI\Settings_Page
 * @covers \GravityKit\BlockAPI\Connect_Page
 */
class SettingsPageTabsTest extends WP_UnitTestCase {

	/**
	 * Ensure $_GET is clean before and after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	/**
	 * render_page() must output the tab nav wrapper with both tab links.
	 *
	 * Regardless of the active tab, the nav must always be present so users
	 * can switch between Connect and Block policy.
	 */
	public function test_render_page_outputs_tab_nav_wrapper() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-wrapper', $html, 'Tab nav wrapper must be present' );
		$this->assertStringContainsString( 'tab=connect', $html, 'Connect tab link must be present' );
		$this->assertStringContainsString( 'tab=policy', $html, 'Block policy tab link must be present' );
	}

	/**
	 * render_page() with no tab param must render the Connect onboarding content.
	 *
	 * "Connect" is the default tab so the onboarding section loads immediately
	 * when an admin visits Settings → Block MCP for the first time.
	 */
	public function test_render_page_defaults_to_connect_tab() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		unset( $_GET['tab'] );

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Connect an AI Assistant', $html, 'Connect onboarding heading must appear on the default tab' );
	}

	/**
	 * render_page() with tab=connect must render the Connect onboarding content.
	 *
	 * Explicit selection of the Connect tab must produce the same result as
	 * the default.
	 */
	public function test_render_page_connect_tab_shows_onboarding() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['tab'] = 'connect';

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Connect an AI Assistant', $html, 'Connect onboarding heading must appear on connect tab' );
		$this->assertStringContainsString( 'nav-tab-active', $html, 'Active tab indicator must be present' );
	}

	/**
	 * render_page() with tab=policy must render the block-policy form and not
	 * the Connect onboarding section.
	 *
	 * The policy form must be reachable via the second tab without any
	 * regression to the existing settings fields.
	 */
	public function test_render_page_policy_tab_shows_policy_form() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['tab'] = 'policy';

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Namespace tier scores', $html, 'Namespace tier scores heading must appear on policy tab' );
		$this->assertStringNotContainsString( 'Connect an AI Assistant', $html, 'Connect onboarding must NOT appear on policy tab' );
	}

	/**
	 * [F1] The settings page glosses "MCP" in plain language so a non-technical
	 * admin doesn't read it as a developer page on landing.
	 */
	public function test_render_page_glosses_mcp_jargon() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		( new Settings_Page( new Block_Inventory() ) )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Model Context Protocol', $html, 'the page must gloss MCP on first mention' );
	}

	/**
	 * The admin submenu uses the "Block MCP" brand label.
	 */
	public function test_register_menu_uses_block_mcp_label() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		( new Settings_Page( new Block_Inventory() ) )->register_menu();

		global $submenu;
		$found = null;
		if ( isset( $submenu['options-general.php'] ) ) {
			foreach ( $submenu['options-general.php'] as $entry ) {
				if ( isset( $entry[2] ) && Settings_Page::PAGE_SLUG === $entry[2] ) {
					$found = $entry;
					break;
				}
			}
		}

		$this->assertNotNull( $found, 'the settings submenu entry must be registered' );
		$this->assertSame( 'Block MCP', $found[0], 'menu title must be the "Block MCP" brand label' );
	}
}
