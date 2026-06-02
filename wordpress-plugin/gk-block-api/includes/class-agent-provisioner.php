<?php
/**
 * Agent_Provisioner — creates and resolves the dedicated service-account user.
 *
 * The provisioner owns the lifecycle of the non-human identity that holds
 * the Application Password an AI client uses to authenticate against the
 * REST API. Keeping credentials on a purpose-built account (rather than
 * a real user's account) limits the blast radius of a leaked password:
 * the account carries only the capabilities the agent needs, and interactive
 * login is blocked at the authenticate filter level.
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
 * Creates and resolves the dedicated Block MCP service-account user.
 *
 * @since 1.9.0
 */
class Agent_Provisioner {

	/**
	 * Default login name for the service account.
	 *
	 * Overridable via the `gk_block_api_agent_login` filter.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const LOGIN = 'block-mcp';

	/**
	 * Role slug for the minimal agent role.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const ROLE = 'block_mcp_agent';

	/**
	 * User-meta key that marks a user as the provisioned service account.
	 *
	 * Only users that carry this meta with value '1' are recognised as the
	 * agent. Without it, an existing user with the target login is treated as
	 * a real account that must not be adopted.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const META_FLAG = '_gk_block_api_agent';

	/**
	 * Register the minimal block_mcp_agent role idempotently.
	 *
	 * The capability set is passed through the `gk_block_api_agent_caps`
	 * filter so site operators can narrow or widen it. The role slug is
	 * passed through `gk_block_api_agent_role`; registration is skipped
	 * when the filter returns a non-canonical slug (the operator is
	 * responsible for ensuring that role exists).
	 *
	 * Calling this method when the role already exists is a no-op.
	 *
	 * @since 1.9.0
	 * @return string The effective role slug (post-filter) callers should assign.
	 */
	public static function register_role(): string {
		$caps = apply_filters(
			'gk_block_api_agent_caps',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'edit_pages'           => true,
				'edit_others_pages'    => true,
				'edit_published_pages' => true,
				'upload_files'         => true,
				'manage_categories'    => true,
				// Deliberately NO delete_*, NO unfiltered_html, NO manage_options.
			)
		);

		$role = apply_filters( 'gk_block_api_agent_role', self::ROLE );

		// Only register when the filter returns the canonical slug — a custom
		// slug means the operator manages that role themselves.
		if ( self::ROLE === $role && ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Block MCP Agent', $caps );
		}

		return $role;
	}

	/**
	 * Resolve or create the agent user, returning its ID on success.
	 *
	 * Behaviour:
	 *  - If a user with the target login exists and carries the agent meta
	 *    flag, its ID is returned (and the option is refreshed).
	 *  - If a user with the target login exists but lacks the meta flag, a
	 *    WP_Error is returned — the account belongs to a real person and must
	 *    not be adopted silently.
	 *  - Otherwise the role is registered, a new user is created with a
	 *    cryptographically random password, the meta flag is set, and the ID
	 *    is returned.
	 *
	 * This method is idempotent: calling it repeatedly when the agent already
	 * exists produces the same result.
	 *
	 * @since  1.9.0
	 * @return int|\WP_Error Agent user ID, or WP_Error on failure.
	 */
	public function ensure() {
		$login = apply_filters( 'gk_block_api_agent_login', self::LOGIN );

		$existing = get_user_by( 'login', $login );

		if ( $existing instanceof \WP_User ) {
			if ( '1' !== get_user_meta( $existing->ID, self::META_FLAG, true ) ) {
				return new \WP_Error(
					'agent_login_taken',
					sprintf(
						/* translators: %s: filter name */
						__(
							'A user with that login already exists but is not the Block MCP service account. Use the %s filter to specify a different login.',
							'gk-block-api'
						),
						'gk_block_api_agent_login'
					)
				);
			}

			update_option( 'gk_block_api_agent_user_id', $existing->ID, false );
			return $existing->ID;
		}

		// No existing user — register the role and create the account. Reuse
		// the slug register_role() resolved so the filter is applied once.
		$role = self::register_role();

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$email = 'block-mcp@' . ( $host ? $host : 'localhost' );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => bin2hex( random_bytes( 32 ) ),
				'user_email'   => $email,
				'display_name' => 'Block MCP (service account)',
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::META_FLAG, '1' );
		update_option( 'gk_block_api_agent_user_id', $user_id, false );

		return $user_id;
	}

	/**
	 * Block interactive login for the service account.
	 *
	 * Filters `authenticate`: any user carrying the agent meta flag is
	 * rejected with an `agent_no_login` WP_Error, so the account's credentials
	 * work only for non-interactive (REST / Application Password) requests.
	 * Pass-through for every other user.
	 *
	 * @since 1.9.0
	 *
	 * @param null|\WP_User|\WP_Error $user Authenticating user, or a prior filter's result.
	 * @return null|\WP_User|\WP_Error The user unchanged, or WP_Error for the service account.
	 */
	public static function block_agent_login( $user ) {
		if ( $user instanceof \WP_User && '1' === get_user_meta( $user->ID, self::META_FLAG, true ) ) {
			return new \WP_Error(
				'agent_no_login',
				__( 'This is a service account and cannot log in interactively.', 'gk-block-api' )
			);
		}

		return $user;
	}
}
