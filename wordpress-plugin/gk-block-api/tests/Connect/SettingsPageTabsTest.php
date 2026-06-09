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
		unset( $_GET['tab'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	/**
	 * Capture the redirect URL a handler emits, swallowing its exit().
	 *
	 * The scan/reset handlers call wp_safe_redirect() (which runs the
	 * 'wp_redirect' filter) then exit(). Hook the filter to grab the location
	 * and throw a catchable marker so exit() doesn't kill the test process.
	 *
	 * @param  callable $invoke Closure that calls the handler under test.
	 * @return string The captured redirect location.
	 */
	private function capture_redirect( callable $invoke ): string {
		$captured = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured ) {
				$captured = (string) $location;
				throw new \RuntimeException( 'redirect_captured' );
			}
		);
		try {
			$invoke();
		} catch ( \RuntimeException $e ) {
			// Expected: the redirect filter threw to stop exit().
			unset( $e );
		} finally {
			remove_all_filters( 'wp_redirect' );
		}
		return $captured;
	}

	/**
	 * The scan and reset handlers live on the Block-policy tab, so their
	 * success notices only render when tab=policy. Both handlers must therefore
	 * preserve tab=policy in their redirect — otherwise the user lands on the
	 * default Connect tab and never sees "scan complete" / "settings reset".
	 *
	 * @dataProvider policy_tab_handler_provider
	 *
	 * @param string $nonce_action check_admin_referer action for the handler.
	 * @param string $method       Settings_Page method to invoke.
	 */
	public function test_policy_tab_handlers_preserve_tab_in_redirect( string $nonce_action, string $method ) {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce                = wp_create_nonce( $nonce_action );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$page = new Settings_Page( new Block_Inventory() );

		$location = $this->capture_redirect(
			static function () use ( $page, $method ) {
				$page->$method();
			}
		);

		$this->assertNotSame( '', $location, "{$method}() must redirect" );
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );
		$this->assertSame(
			'policy',
			$args['tab'] ?? null,
			"{$method}() must redirect back to the Block-policy tab so its success notice is visible"
		);
	}

	/**
	 * Handlers whose success notice renders only on the policy tab.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function policy_tab_handler_provider(): array {
		return array(
			'scan'  => array( 'gk_block_api_scan_storage_modes', 'handle_scan' ),
			'reset' => array( 'gk_block_api_reset_defaults', 'handle_reset' ),
		);
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

		$this->assertStringContainsString( 'Which blocks AI should prefer', $html, 'the block-scores section heading must appear on the policy tab' );
		// Client-side tabs render BOTH panels; on ?tab=policy the Connect panel is
		// present but hidden (not the active view), and the policy panel is visible.
		$this->assertStringContainsString( 'data-tab-panel="connect" hidden', $html, 'the Connect panel must be hidden on the policy tab' );
		$this->assertStringNotContainsString( 'data-tab-panel="policy" hidden', $html, 'the policy panel must be visible (not hidden) on the policy tab' );
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
