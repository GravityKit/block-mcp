<?php
/**
 * Connections: list and revoke agent Application Passwords.
 *
 * A "connection" is any Application Password owned by a given user whose
 * name starts with the 'Block MCP' prefix. The Connections class derives
 * this list directly from WordPress core (no parallel store) so that
 * revoking from the Users → Profile screen and from the plugin UI stay
 * consistent.
 *
 * Contracts pinned here:
 *
 *  - list() returns only passwords whose name starts with 'Block MCP';
 *    unrelated Application Passwords are silently excluded.
 *  - Each returned row exposes 'uuid', 'name', and 'created' keys.
 *  - revoke() deletes the targeted password and returns true on success.
 *  - After revoke(), get_user_application_passwords() returns an empty
 *    array when the revoked entry was the only one.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Connections;

/**
 * Tests for Connections::list() and Connections::revoke().
 *
 * @covers \GravityKit\BlockAPI\Connections
 */
class ConnectionsTest extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Helper: seed an Application Password and return its UUID.
	 *
	 * @param string $name Label to store on the credential.
	 * @return string UUID of the created entry.
	 */
	private function seed( $name ) {
		$created = \WP_Application_Passwords::create_new_application_password(
			$this->user_id,
			array( 'name' => $name )
		);
		return $created[1]['uuid'];
	}

	/**
	 * list() must return only the passwords whose name begins with 'Block MCP'.
	 *
	 * Seeding a second, unrelated Application Password on the same user account
	 * verifies that the prefix filter is applied — not just that the method
	 * returns all stored passwords.
	 *
	 * Each returned row must expose 'uuid', 'name', and 'created' so callers
	 * can display and manage connections without touching core data structures.
	 */
	public function test_list_returns_only_block_mcp_named_passwords() {
		$mine = $this->seed( 'Block MCP — Claude Desktop' );
		$this->seed( 'WooCommerce Mobile App' ); // unrelated, must be excluded
		$rows = ( new Connections() )->list( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $mine, $rows[0]['uuid'] );
		$this->assertSame( 'Block MCP — Claude Desktop', $rows[0]['name'] );
		$this->assertArrayHasKey( 'created', $rows[0] );
	}

	/**
	 * revoke() must delete the targeted Application Password and return true.
	 *
	 * After a successful revoke the user's Application Password list must be
	 * empty (the seeded entry was the only one), confirming core storage was
	 * mutated rather than just an internal flag being cleared.
	 */
	public function test_revoke_deletes_the_named_password() {
		$uuid = $this->seed( 'Block MCP — Claude Desktop' );
		$ok   = ( new Connections() )->revoke( $this->user_id, $uuid );
		$this->assertTrue( $ok );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );
	}
}
