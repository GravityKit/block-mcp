<?php
/**
 * App_Password_Issuer: availability guard and credential-minting contracts.
 *
 * The issuer is a thin wrapper around WP_Application_Passwords that gates
 * on availability before creating a credential. Key contracts pinned here:
 *
 *  - issue() returns an array with 'password' (one-time plaintext) and 'uuid'
 *    when Application Passwords are available for the user.
 *  - The stored password entry is retrievable via
 *    WP_Application_Passwords::get_user_application_passwords() and carries
 *    the exact label passed to issue().
 *  - issue() returns WP_Error('app_passwords_unavailable') when the feature
 *    is disabled (e.g. no HTTPS), leaving the user account untouched.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\App_Password_Issuer;

/**
 * Tests for App_Password_Issuer::issue().
 *
 * @covers \GravityKit\BlockAPI\App_Password_Issuer
 */
class AppPasswordIssuerTest extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * issue() must return a plaintext password and a UUID when Application
	 * Passwords are available. The password must be exactly 24 characters of
	 * base-64-alphabet + spaces (WP core format). The stored entry must carry
	 * the label verbatim and share the same UUID.
	 */
	public function test_issue_returns_plaintext_password_and_uuid() {
		$result = ( new App_Password_Issuer() )->issue( $this->user_id, 'Block MCP — Claude Desktop' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'password', $result );
		$this->assertArrayHasKey( 'uuid', $result );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9 ]{24}$/', $result['password'] );

		$items = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Block MCP — Claude Desktop', $items[0]['name'] );
		$this->assertSame( $result['uuid'], $items[0]['uuid'] );
	}

	/**
	 * When Application Passwords are unavailable (e.g. non-HTTPS environment),
	 * issue() must return WP_Error with code 'app_passwords_unavailable' rather
	 * than attempting to create a credential on an unsupported installation.
	 */
	public function test_issue_returns_wp_error_when_passwords_unavailable() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$result = ( new App_Password_Issuer() )->issue( $this->user_id, 'Block MCP — Claude Desktop' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'app_passwords_unavailable', $result->get_error_code() );
	}
}
