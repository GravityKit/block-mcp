<?php
/**
 * Connections — lists and revokes agent Application Passwords.
 *
 * A connection is an Application Password owned by a given user whose name
 * starts with the 'Block MCP' prefix. The list is derived directly from
 * WordPress core (no parallel store), so revoking via the Users → Profile
 * screen and via this class stays consistent without any synchronisation.
 *
 * @package GravityKit\BlockAPI
 * @since   1.9.0
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and revokes Block MCP Application Passwords for a given user.
 *
 * @since 1.9.0
 */
class Connections {

	/**
	 * Prefix that identifies Application Passwords managed by this plugin.
	 *
	 * Any Application Password whose name begins with this string is
	 * considered a Block MCP connection. The comparison is case-sensitive
	 * and intentionally exact so names like 'Block MCP — Claude Desktop'
	 * and 'Block MCP — Cursor' are both matched while unrelated credentials
	 * are excluded.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const NAME_PREFIX = 'Block MCP';

	/**
	 * Return all Block MCP Application Passwords for the given user.
	 *
	 * Iterates the user's Application Passwords and keeps only those whose
	 * name starts with NAME_PREFIX. Each row in the returned array contains
	 * the UUID, display name, creation timestamp, and (when recorded by core)
	 * the last-used timestamp.
	 *
	 * @since  1.9.0
	 *
	 * @param  int $user_id WordPress user ID to query.
	 * @return array[] Each element: {
	 *     @type string   $uuid      UUID of the Application Password entry.
	 *     @type string   $name      Human-readable label stored on the credential.
	 *     @type int      $created   Unix timestamp when the credential was created.
	 *     @type int|null $last_used Unix timestamp of the most recent use, or null.
	 * }
	 */
	public function list( $user_id ) {
		$all  = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$rows = array();

		foreach ( $all as $item ) {
			if ( strpos( $item['name'], self::NAME_PREFIX ) !== 0 ) {
				continue;
			}

			$rows[] = array(
				'uuid'      => (string) $item['uuid'],
				'name'      => (string) $item['name'],
				'created'   => (int) $item['created'],
				'last_used' => isset( $item['last_used'] ) ? (int) $item['last_used'] : null,
			);
		}

		return $rows;
	}

	/**
	 * Revoke a single Block MCP Application Password by UUID.
	 *
	 * Only deletes credentials this plugin manages: the named entry must exist
	 * for the user AND its name must begin with NAME_PREFIX — the same scope
	 * list() enforces. This keeps a crafted or stale UUID from removing an
	 * unrelated Application Password if this method is ever called against a
	 * user that also holds non-Block MCP credentials. Returns true only when
	 * core confirms the entry was removed; false when the UUID is unknown, the
	 * credential is out of scope, or core returns a WP_Error.
	 *
	 * @since  1.9.0
	 *
	 * @param  int    $user_id WordPress user ID that owns the credential.
	 * @param  string $uuid    UUID of the Application Password to delete.
	 * @return bool True on successful deletion, false otherwise.
	 */
	public function revoke( $user_id, $uuid ) {
		$item       = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		$is_managed = is_array( $item ) && isset( $item['name'] ) && strpos( $item['name'], self::NAME_PREFIX ) === 0;

		if ( ! $is_managed ) {
			return false;
		}

		$result = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		return ! is_wp_error( $result ) && $result;
	}
}
