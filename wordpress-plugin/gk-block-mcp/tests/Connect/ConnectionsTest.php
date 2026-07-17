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
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Connections;

/**
 * Tests for Connections::list() and Connections::revoke().
 *
 * @covers \GravityKit\BlockMCP\Connections
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

	/**
	 * revoke() must return false when the supplied UUID does not match any
	 * stored credential and must leave the unrelated seeded password intact.
	 *
	 * This pins the false-path contract: a caller passing a stale or invalid
	 * UUID receives a clear false result rather than a silent no-op, and
	 * any existing credentials on the user account are unaffected.
	 */
	public function test_revoke_returns_false_for_unknown_uuid() {
		$uuid = $this->seed( 'Block MCP — Claude Desktop' );

		$ok = ( new Connections() )->revoke( $this->user_id, 'nonexistent-uuid' );

		$this->assertFalse( $ok );

		$remaining = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $remaining, 'Seeded password must be untouched' );
		$this->assertSame( $uuid, $remaining[0]['uuid'] );
	}

	/**
	 * [WP-F10] revoke() must refuse to delete an Application Password whose name
	 * does not begin with the Block MCP prefix, even when the UUID is valid for
	 * the user.
	 *
	 * list() scopes to NAME_PREFIX, but revoke() previously deleted by
	 * (user_id, uuid) without that check — so a crafted UUID could remove an
	 * unrelated credential if this method were ever called against a user that
	 * also holds non-Block MCP passwords. The fix re-checks the prefix before
	 * deleting. This pins both halves: revoke() returns false AND the unrelated
	 * password is left intact.
	 */
	public function test_revoke_refuses_non_block_mcp_password() {
		$other = $this->seed( 'WooCommerce Mobile App' ); // not a Block MCP connection

		$ok = ( new Connections() )->revoke( $this->user_id, $other );

		$this->assertFalse( $ok, 'revoke() must not delete a credential outside the Block MCP prefix' );

		$remaining = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $remaining, 'the unrelated password must remain' );
		$this->assertSame( $other, $remaining[0]['uuid'] );
	}

	/**
	 * [WP-F10] revoke() must refuse a name that merely CONTAINS 'Block MCP' but
	 * does not START with it.
	 *
	 * This pins the strpos( $name, NAME_PREFIX ) === 0 boundary. The sibling
	 * test above (a name with no prefix at all) passes even under a looser
	 * "contains" check (strpos !== false), so it cannot catch a regression that
	 * swaps === 0 for !== false. This one can: 'My Block MCP Helper' contains the
	 * prefix at offset 3, so a contains-check would wrongly delete it.
	 */
	public function test_revoke_refuses_name_containing_prefix_not_at_start() {
		$other = $this->seed( 'My Block MCP Helper' ); // contains 'Block MCP', but not as a prefix

		$ok = ( new Connections() )->revoke( $this->user_id, $other );

		$this->assertFalse( $ok, 'a name where the prefix is not at position 0 must be refused' );

		$remaining = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertCount( 1, $remaining, 'the password must remain' );
		$this->assertSame( $other, $remaining[0]['uuid'] );
	}

	// ── connection meta: audit trail + byline ────────────────────────

	public function tear_down() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		parent::tear_down();
	}

	/**
	 * Seed an Application Password on a specific user and return its UUID.
	 *
	 * @param int    $user_id User to own the credential.
	 * @param string $name    Label to store.
	 * @return string UUID.
	 */
	private function seed_for( $user_id, $name ) {
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
		return $created[1]['uuid'];
	}

	/**
	 * record_meta() then get_meta() round-trips the stored fields; an unknown UUID
	 * returns null.
	 */
	public function test_record_and_get_meta_round_trips() {
		Connections::record_meta( 'uuid-1', array( 'created_by' => 7, 'user_id' => 9, 'created_at' => 123 ) );

		$meta = Connections::get_meta( 'uuid-1' );
		$this->assertSame( 7, $meta['created_by'] );
		$this->assertSame( 9, $meta['user_id'] );
		$this->assertSame( 123, $meta['created_at'] );
		$this->assertNull( Connections::get_meta( 'no-such-uuid' ) );
	}

	/**
	 * A second record_meta() for the same UUID merges into the existing entry
	 * rather than replacing it, so a partial update can't drop sibling fields.
	 */
	public function test_record_meta_merges_into_existing() {
		Connections::record_meta( 'uuid-1', array( 'created_by' => 7 ) );
		Connections::record_meta( 'uuid-1', array( 'user_id' => 9 ) );

		$meta = Connections::get_meta( 'uuid-1' );
		$this->assertSame( 7, $meta['created_by'], 'created_by must survive the partial update' );
		$this->assertSame( 9, $meta['user_id'] );
	}

	/**
	 * A connection's meta must survive a concurrent writer flushing a stale copy
	 * of the aggregate option.
	 *
	 * record_meta/forget_meta used to read-modify-write a single shared array, so
	 * two connections minted at once could clobber each other — the loser's row
	 * vanished and its self-hosted credential stayed unrevoked at uninstall. Each
	 * connection now lives in its own option row, so a stale aggregate overwrite
	 * cannot drop it.
	 */
	public function test_record_meta_survives_a_stale_aggregate_overwrite() {
		Connections::record_meta( 'uuid-A', array( 'user_id' => 11, 'created_by' => 1 ) );
		// A concurrent writer read the aggregate before B was recorded.
		$stale = get_network_option( null, Connections::META_OPTION, array() );

		Connections::record_meta( 'uuid-B', array( 'user_id' => 22, 'created_by' => 1 ) );

		// That writer now flushes its stale aggregate view, dropping B under the
		// old single-array storage.
		update_network_option( null, Connections::META_OPTION, $stale );

		$b = Connections::get_meta( 'uuid-B' );
		$this->assertIsArray( $b, "B's meta must survive a stale aggregate overwrite" );
		$this->assertSame( 22, (int) $b['user_id'] );
	}

	/**
	 * forget_meta() removes the entry for a UUID and leaves siblings intact.
	 */
	public function test_forget_meta_removes_entry() {
		Connections::record_meta( 'uuid-1', array( 'created_by' => 7 ) );
		Connections::record_meta( 'uuid-2', array( 'created_by' => 9 ) );

		Connections::forget_meta( 'uuid-1' );

		$this->assertNull( Connections::get_meta( 'uuid-1' ) );
		$this->assertSame( 9, Connections::get_meta( 'uuid-2' )['created_by'] );
	}

	/**
	 * list() joins the recorded meta onto each row: created_by and
	 * own_account=false for agent-hosted credentials.
	 */
	public function test_list_includes_recorded_meta() {
		$uuid = $this->seed( 'Block MCP — Cursor' );
		Connections::record_meta( $uuid, array( 'created_by' => $this->user_id, 'user_id' => $this->user_id ) );

		$rows = ( new Connections() )->list( $this->user_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->user_id, $rows[0]['created_by'] );
		$this->assertFalse( $rows[0]['own_account'] );
		$this->assertSame( $this->user_id, $rows[0]['host_user_id'] );
	}

	/**
	 * Revoking a connection also forgets its meta, so a re-minted credential that
	 * happens to reuse a UUID can't inherit a stale byline.
	 */
	public function test_revoke_forgets_meta() {
		$uuid = $this->seed( 'Block MCP — Cursor' );
		Connections::record_meta( $uuid, array( 'created_by' => $this->user_id ) );

		( new Connections() )->revoke( $this->user_id, $uuid );

		$this->assertNull( Connections::get_meta( $uuid ) );
	}

	/**
	 * list_self_hosted() surfaces own-account connections (credentials minted on a
	 * user other than the agent) with own_account=true, and excludes the agent's.
	 */
	public function test_list_self_hosted_returns_own_account_connections() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$human    = $this->user_id;

		$self_uuid = $this->seed_for( $human, 'Block MCP — Cursor' );
		Connections::record_meta( $self_uuid, array( 'created_by' => $human, 'user_id' => $human ) );

		// An agent-hosted entry must NOT appear in the self-hosted list.
		$agent_uuid = $this->seed_for( $agent_id, 'Block MCP — Claude Desktop' );
		Connections::record_meta( $agent_uuid, array( 'created_by' => $human, 'user_id' => $agent_id ) );

		$rows = ( new Connections() )->list_self_hosted( $agent_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( $self_uuid, $rows[0]['uuid'] );
		$this->assertTrue( $rows[0]['own_account'] );
		$this->assertSame( $human, $rows[0]['host_user_id'] );
	}

	/**
	 * revoke_by_uuid() resolves the host user from the meta store, so an
	 * own-account credential is deleted from the user who actually holds it even
	 * though the revoke form only carries the UUID.
	 */
	public function test_revoke_by_uuid_resolves_host_from_meta() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$human    = $this->user_id;

		$uuid = $this->seed_for( $human, 'Block MCP — Cursor' );
		Connections::record_meta( $uuid, array( 'user_id' => $human, 'created_by' => $human ) );

		$ok = ( new Connections() )->revoke_by_uuid( $uuid, $agent_id );

		$this->assertTrue( $ok );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $human ) );
	}

	/**
	 * purge_all_recorded() deletes every credential the meta store knows about,
	 * from whichever user holds it — the uninstall path for own-account
	 * credentials that the agent teardown can't reach.
	 */
	public function test_purge_all_recorded_revokes_recorded_credentials() {
		$other = self::factory()->user->create( array( 'role' => 'author' ) );

		$a = $this->seed_for( $this->user_id, 'Block MCP — Cursor' );
		$b = $this->seed_for( $other, 'Block MCP — Claude Code' );
		Connections::record_meta( $a, array( 'user_id' => $this->user_id ) );
		Connections::record_meta( $b, array( 'user_id' => $other ) );

		Connections::purge_all_recorded();

		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $other ) );
	}
}
