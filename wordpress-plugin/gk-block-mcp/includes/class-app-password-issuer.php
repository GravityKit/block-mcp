<?php
/**
 * App_Password_Issuer: mints an Application Password for a given user.
 *
 * A thin, testable wrapper around WP_Application_Passwords that gates
 * creation on feature availability before delegating to core. The one-time
 * plaintext password returned by core is surfaced to the caller here; core
 * discards it immediately after, so the caller must persist or transmit it
 * before this method returns.
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
 * Mints an Application Password for the supplied user.
 *
 * @since 2.0.0
 */
class App_Password_Issuer {

	/**
	 * Create an Application Password for the given user.
	 *
	 * Returns the one-time plaintext password and the UUID of the stored
	 * credential on success. Returns WP_Error when Application Passwords
	 * are unavailable for the installation or when core creation fails.
	 *
	 * @since  2.0.0
	 *
	 * @param  int    $user_id User to create the credential for.
	 * @param  string $label   Human-readable name stored alongside the credential.
	 * @return array|\WP_Error {
	 *     Success: array containing the minted credential.
	 *
	 *     @type string $password One-time plaintext password as returned by
	 *                            WP_Application_Passwords::create_new_application_password().
	 *     @type string $uuid     UUID of the stored Application Password entry.
	 * }
	 */
	public function issue( $user_id, $label ) {
		$user_id = absint( $user_id );
		$label   = sanitize_text_field( $label );

		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error(
				'app_passwords_unavailable',
				__( 'Application Passwords are unavailable. Your site likely needs HTTPS.', 'gk-block-mcp' )
			);
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $label )
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		list( $plaintext, $item ) = $created;

		return array(
			'password' => (string) $plaintext,
			'uuid'     => (string) $item['uuid'],
		);
	}
}
