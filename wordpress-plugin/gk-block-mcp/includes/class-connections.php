<?php
/**
 * Connections — lists and revokes agent Application Passwords.
 *
 * A connection is an Application Password owned by a given user whose name
 * starts with the 'Block MCP' prefix. The list is derived directly from
 * WordPress core (no parallel store), so revoking via the Users → Profile
 * screen and via this class stays consistent without any synchronisation.
 *
 * @package GravityKit\BlockMCP
 * @since   2.0.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and revokes Block MCP Application Passwords for a given user.
 *
 * @since 2.0.0
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
	 * @since 2.0.0
	 * @var   string
	 */
	const NAME_PREFIX = 'Block MCP';

	/**
	 * Option that records the facts core's Application Password store can't: which
	 * user holds each connection's credential and who approved it.
	 *
	 * Keyed by Application Password UUID → { user_id, created_by, created_at }.
	 * Stored as a network option so it stays consistent with the network-wide
	 * connection list on multisite; on single-site it transparently falls back
	 * to wp_options. The credential list itself still derives from core — this
	 * only annotates it.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const META_OPTION = 'gk_block_api_connection_meta';

	/**
	 * Record the approving human and host account for a minted credential.
	 *
	 * Merges into any existing entry so partial updates don't clobber siblings.
	 *
	 * @since 2.0.0
	 *
	 * @param string $uuid Application Password UUID the credential was minted as.
	 * @param array  $meta { @type int $user_id, @type int $created_by, @type int $created_at }.
	 * @return void
	 */
	public static function record_meta( $uuid, array $meta ) {
		$uuid = (string) $uuid;
		if ( '' === $uuid ) {
			return;
		}
		$all          = self::meta_all();
		$existing     = isset( $all[ $uuid ] ) && is_array( $all[ $uuid ] ) ? $all[ $uuid ] : array();
		$all[ $uuid ] = array_merge( $existing, $meta );
		update_network_option( null, self::META_OPTION, $all );
	}

	/**
	 * Return the recorded meta for a credential UUID, or null when none exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $uuid Application Password UUID.
	 * @return array|null
	 */
	public static function get_meta( $uuid ) {
		$all  = self::meta_all();
		$uuid = (string) $uuid;
		return isset( $all[ $uuid ] ) && is_array( $all[ $uuid ] ) ? $all[ $uuid ] : null;
	}

	/**
	 * Drop the meta entry for a credential UUID (called when a connection is revoked).
	 *
	 * @since 2.0.0
	 *
	 * @param string $uuid Application Password UUID.
	 * @return void
	 */
	public static function forget_meta( $uuid ) {
		$all  = self::meta_all();
		$uuid = (string) $uuid;
		if ( isset( $all[ $uuid ] ) ) {
			unset( $all[ $uuid ] );
			update_network_option( null, self::META_OPTION, $all );
		}
	}

	/**
	 * The full UUID → meta map. Always an array.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private static function meta_all() {
		$all = get_network_option( null, self::META_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Return all Block MCP Application Passwords for the given user.
	 *
	 * Iterates the user's Application Passwords and keeps only those whose
	 * name starts with NAME_PREFIX. Each row in the returned array contains
	 * the UUID, display name, creation timestamp, and (when recorded by core)
	 * the last-used timestamp.
	 *
	 * @since  2.0.0
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

			$meta = self::get_meta( $item['uuid'] );

			$rows[] = array(
				'uuid'         => (string) $item['uuid'],
				'name'         => (string) $item['name'],
				'created'      => (int) $item['created'],
				'last_used'    => isset( $item['last_used'] ) ? (int) $item['last_used'] : null,
				'created_by'   => is_array( $meta ) && isset( $meta['created_by'] ) ? (int) $meta['created_by'] : null,
				'host_user_id' => (int) $user_id,
				'own_account'  => false,
			);
		}

		return $rows;
	}

	/**
	 * Return connections whose credential lives on a user OTHER than the agent —
	 * the "use my own account" connections.
	 *
	 * Derived from the meta store (the only record of which user holds each
	 * own-account credential), then confirmed against core: each entry must still
	 * exist on its host user AND carry the NAME_PREFIX, so a stale meta row or a
	 * credential the user revoked from their profile screen is silently dropped.
	 *
	 * @since  2.0.0
	 *
	 * @param  int $agent_id The agent user ID, whose own credentials list() already covers.
	 * @return array[] Same row shape as list(), with own_account => true.
	 */
	public function list_self_hosted( $agent_id ) {
		$agent_id = (int) $agent_id;
		$rows     = array();

		foreach ( self::meta_all() as $uuid => $meta ) {
			$host = is_array( $meta ) && isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
			if ( $host <= 0 || $host === $agent_id ) {
				continue;
			}

			$item       = \WP_Application_Passwords::get_user_application_password( $host, (string) $uuid );
			$is_managed = is_array( $item ) && isset( $item['name'] ) && strpos( $item['name'], self::NAME_PREFIX ) === 0;
			if ( ! $is_managed ) {
				continue;
			}

			$rows[] = array(
				'uuid'         => (string) $item['uuid'],
				'name'         => (string) $item['name'],
				'created'      => (int) $item['created'],
				'last_used'    => isset( $item['last_used'] ) ? (int) $item['last_used'] : null,
				'created_by'   => isset( $meta['created_by'] ) ? (int) $meta['created_by'] : null,
				'host_user_id' => $host,
				'own_account'  => true,
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
	 * @since  2.0.0
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

		$result  = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$deleted = ! is_wp_error( $result ) && $result;

		if ( $deleted ) {
			self::forget_meta( $uuid );
		}

		return $deleted;
	}

	/**
	 * Revoke a connection by UUID, resolving which user holds it from the meta store.
	 *
	 * A connection's credential may live on the agent OR on the approving user
	 * (own-account connections). The revoke form only carries the UUID, so this
	 * looks up the host user from the recorded meta and falls back to the supplied
	 * agent ID when no meta exists (older agent-hosted connections). Scope-checking
	 * and meta cleanup are handled by revoke().
	 *
	 * @since  2.0.0
	 *
	 * @param  string $uuid             UUID of the Application Password to delete.
	 * @param  int    $fallback_user_id User to use when meta has no recorded host (the agent).
	 * @return bool True on successful deletion, false otherwise.
	 */
	public function revoke_by_uuid( $uuid, $fallback_user_id ) {
		$meta = self::get_meta( $uuid );
		$host = is_array( $meta ) && ! empty( $meta['user_id'] ) ? (int) $meta['user_id'] : (int) $fallback_user_id;

		return $this->revoke( $host, $uuid );
	}

	/**
	 * Delete every credential recorded in the meta store from its host user.
	 *
	 * Used at uninstall: own-account credentials live on real users, so the agent
	 * teardown doesn't touch them, and an Application Password keeps authenticating
	 * to core REST/XML-RPC even after the plugin is gone. This revokes them at the
	 * source. Agent-hosted entries are covered too (harmless — the agent user is
	 * deleted separately, which removes its credentials anyway). Idempotent: a
	 * credential already gone is skipped.
	 *
	 * @since  2.0.0
	 *
	 * @return void
	 */
	public static function purge_all_recorded() {
		foreach ( self::meta_all() as $uuid => $meta ) {
			$host = is_array( $meta ) && isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
			if ( $host <= 0 ) {
				continue;
			}

			$item       = \WP_Application_Passwords::get_user_application_password( $host, (string) $uuid );
			$is_managed = is_array( $item ) && isset( $item['name'] ) && strpos( $item['name'], self::NAME_PREFIX ) === 0;
			if ( $is_managed ) {
				\WP_Application_Passwords::delete_application_password( $host, (string) $uuid );
			}
		}
	}
}
