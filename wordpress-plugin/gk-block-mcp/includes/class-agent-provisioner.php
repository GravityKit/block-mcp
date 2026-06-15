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
 * @package GravityKit\BlockMCP
 * @since   2.0.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and resolves the dedicated Block MCP service-account user.
 *
 * @since 2.0.0
 */
class Agent_Provisioner {

	/**
	 * Default login name for the service account.
	 *
	 * Overridable via the `gk/block-mcp/agent/login` filter.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const LOGIN = 'block-mcp';

	/**
	 * Role slug for the minimal agent role.
	 *
	 * @since 2.0.0
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
	 * @since 2.0.0
	 * @var string
	 */
	const META_FLAG = '_gk_block_api_agent';

	/**
	 * Register the minimal block_mcp_agent role idempotently.
	 *
	 * The capability set is passed through the `gk/block-mcp/agent/caps`
	 * filter so site operators can narrow or widen it. The role slug is
	 * passed through `gk/block-mcp/agent/role`; registration is skipped
	 * when the filter returns a non-canonical slug (the operator is
	 * responsible for ensuring that role exists).
	 *
	 * Calling this method when the role already exists is a no-op.
	 *
	 * @since 2.0.0
	 * @return string The effective role slug (post-filter) callers should assign.
	 */
	public static function register_role(): string {
		/**
		 * Tune exactly what the Block MCP agent account is allowed to do.
		 *
		 * The dedicated agent ships locked down — it writes content but can't
		 * delete, change settings, or be used to log in. Reach for this filter
		 * when your workflow needs a tighter or looser fit: hand the agent
		 * permission to clean up its own drafts, or strip publishing so
		 * everything it touches stays a draft for human review.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Let the assistant permanently delete the posts it created.
		 * add_filter( 'gk/block-mcp/agent/caps', function ( $caps ) {
		 *     $caps['delete_posts'] = true;
		 *     return $caps;
		 * } );
		 *
		 * @param array<string,bool> $caps Map of capability name => granted, for the agent role.
		 */
		$caps = apply_filters(
			'gk/block-mcp/agent/caps',
			self::derive_capabilities()
		);

		/**
		 * Run the AI agent on a role you control instead of the built-in one.
		 *
		 * By default the plugin registers and manages a tidy
		 * `block_mcp_agent` role for you. Return your own slug here when you
		 * already govern capabilities centrally — say a membership plugin, a
		 * compliance policy, or a shared "content bot" role across several
		 * tools. The moment you return a custom slug, the plugin steps back and
		 * stops creating or modifying the role, so it's yours to define.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Assign the agent to a role your team already manages.
		 * add_filter( 'gk/block-mcp/agent/role', function () {
		 *     return 'content_automation';
		 * } );
		 *
		 * @param string $role Role slug for the agent account. Default 'block_mcp_agent'.
		 */
		$role = apply_filters( 'gk/block-mcp/agent/role', self::ROLE );

		// Only manage the role when the filter returns the canonical slug — a
		// custom slug means the operator manages that role themselves.
		if ( self::ROLE === $role ) {
			$existing = get_role( self::ROLE );
			if ( ! $existing ) {
				add_role( self::ROLE, 'Block MCP Agent', $caps );
			} else {
				// Re-assert: grant any capability the role is missing — e.g. a CPT
				// (weDocs `docs`) registered or allow-listed after the role was first
				// created. Additive only: never strips caps an operator has added.
				foreach ( $caps as $cap => $granted ) {
					if ( $granted && ! $existing->has_cap( $cap ) ) {
						$existing->add_cap( $cap );
					}
				}
			}
		}

		return $role;
	}

	/**
	 * Build the agent's capability set from the post types the REST API operates
	 * on: `post`, `page`, plus every `show_in_rest` type (mirroring
	 * Post_Manager's allow-list). Each type contributes its mapped edit/publish
	 * primitives via the post-type capability object, so a CPT with a custom
	 * `capability_type` — e.g. weDocs `docs` → `edit_docs` / `edit_others_docs` /
	 * `edit_published_docs` / `publish_docs` — is editable, not just readable.
	 *
	 * Deliberately NO delete_*, NO edit_private_*, NO unfiltered_html, NO
	 * manage_options — the agent writes and publishes, but never hard-deletes,
	 * edits private content, or changes settings.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string,bool> Capability name => granted.
	 */
	private static function derive_capabilities(): array {
		$caps = array(
			'read'         => true,
			'upload_files' => true,
		);

		$types = array( 'post', 'page' );
		if ( function_exists( 'get_post_types' ) ) {
			$types = array_unique(
				array_merge( $types, array_values( get_post_types( array( 'show_in_rest' => true ), 'names' ) ) )
			);
		}

		$primitives = array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts' );

		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object ) {
				continue;
			}
			foreach ( $primitives as $primitive ) {
				if ( isset( $object->cap->$primitive ) ) {
					$caps[ $object->cap->$primitive ] = true;
				}
			}
		}

		return $caps;
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
	 * @since  2.0.0
	 * @return int|\WP_Error Agent user ID, or WP_Error on failure.
	 */
	public function ensure() {
		$existing = $this->get_existing();
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( null !== $existing ) {
			return $existing;
		}

		/**
		 * Name the AI agent's user account to match your house style.
		 *
		 * The service account is created and looked up by this login, so it's
		 * the username teammates will see in the author dropdown and the users
		 * list. Pick something that reads cleanly on the byline — `ai-editor`,
		 * `acme-content-bot` — or align it with a naming convention your other
		 * automation already follows. Set it before the first connection; the
		 * login is how the plugin finds the account on every run.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Give the agent a friendlier, on-brand username.
		 * add_filter( 'gk/block-mcp/agent/login', function () {
		 *     return 'acme-ai-editor';
		 * } );
		 *
		 * @param string $login Account login name for the agent. Default 'block-mcp'.
		 */
		$login = apply_filters( 'gk/block-mcp/agent/login', self::LOGIN );

		// Register the role (idempotent) up front so it exists on the CURRENT
		// blog before we assign it — on multisite the global agent user may have
		// been created on another blog, where this blog's role didn't yet exist.
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
		$this->ensure_role_on_current_blog( $user_id, $role );
		update_option( 'gk_block_api_agent_user_id', $user_id, false );

		return $user_id;
	}

	/**
	 * Resolve the already-provisioned agent without creating it.
	 *
	 * Mirrors ensure()'s existing-account branch — login lookup, service-account
	 * flag check, and the per-blog role assertion — but never calls
	 * wp_insert_user(). The credential-minting request uses this so it does NOT
	 * also create a user: a request that both creates an account and mints an
	 * Application Password for it matches a backdoor-provisioning signature that
	 * runtime firewalls (e.g. Monarx) block. Creation happens earlier, on the
	 * connect-screen render; minting runs here against the existing account.
	 *
	 * @since 2.0.1
	 *
	 * @return int|null|\WP_Error Agent user ID; null when not yet provisioned;
	 *                            WP_Error when the login is held by a real user.
	 */
	public function get_existing() {
		$login = apply_filters( 'gk/block-mcp/agent/login', self::LOGIN );

		// Register the role (idempotent) so the agent's caps resolve on this
		// blog. This writes no user, so it does not contribute to the
		// create-user-and-mint signature the minting request must avoid.
		$role = self::register_role();

		$existing = get_user_by( 'login', $login );
		if ( ! $existing instanceof \WP_User ) {
			return null;
		}

		$meta_flag = get_user_meta( $existing->ID, self::META_FLAG, true );
		if ( '1' !== $meta_flag ) {
			return new \WP_Error(
				'agent_login_taken',
				sprintf(
					/* translators: %s: filter name */
					__(
						'A user with that login already exists but is not the Block MCP service account. Use the %s filter to specify a different login.',
						'gk-block-mcp'
					),
					'gk/block-mcp/agent/login'
				)
			);
		}

		// The agent user is network-global, but capabilities are per-blog.
		// Without this the agent has the role only on the blog it was first
		// created on, so REST writes 403 on every other blog of a network.
		$this->ensure_role_on_current_blog( $existing->ID, $role );

		update_option( 'gk_block_api_agent_user_id', $existing->ID, false );
		return $existing->ID;
	}

	/**
	 * Ensure the agent has the agent role (and thus its caps) on the CURRENT blog.
	 *
	 * Capabilities live in per-blog usermeta, so a network-global agent user gets
	 * caps only on the blog where it was first provisioned. On multisite, this
	 * adds the user to the current blog with the role when it is not yet a member;
	 * then (on any site type) it re-asserts the role so the caps exist. Idempotent.
	 *
	 * @since 2.0.0
	 *
	 * @param  int    $user_id Agent user ID.
	 * @param  string $role    Agent role slug.
	 * @return void
	 */
	private function ensure_role_on_current_blog( $user_id, $role ) {
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user_id, $role );
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof \WP_User && ! in_array( $role, (array) $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}

	/**
	 * Tear down the agent service account and all associated credentials.
	 *
	 * Reads the agent user ID from the gk_block_api_agent_user_id option,
	 * verifies the user carries the _gk_block_api_agent meta flag (so a
	 * stale option pointing at a real user can never cause accidental
	 * deletion), revokes every Application Password on that user, deletes the
	 * user, removes the option, and removes the block_mcp_agent role.
	 *
	 * The entire operation is gated on the
	 * `gk/block-mcp/agent/remove-on-uninstall` filter (default true). When
	 * the filter returns false, purge() is a no-op — an operator who wants to
	 * keep the service account across plugin reinstalls can opt out without
	 * forking the plugin.
	 *
	 * This method is idempotent: calling it when no agent exists (missing
	 * option, already-deleted user) completes silently with no errors.
	 *
	 * Content reassignment strategy
	 * ──────────────────────────────
	 * Single-site: wp_delete_user() accepts a reassign argument; authored posts
	 * are transferred to that user ID rather than deleted.
	 *
	 * Multisite: wpmu_delete_user() does not accept a reassign argument and
	 * deletes the user's posts across the network. To preserve content, this
	 * method updates post_author on the current blog before calling
	 * wpmu_delete_user(). Other blogs in the network are outside the scope of
	 * a single-site uninstall; if full network coverage is required, a
	 * network-admin uninstall hook should loop over blogs and call purge() on
	 * each. The current blog's content is always protected.
	 *
	 * The reassign target is resolved via the gk/block-mcp/agent/reassign-to
	 * filter (default 0). When the filter returns falsy, the first
	 * administrator on the site is used. If no administrator exists — an edge
	 * case on misconfigured sites — the fallback is null, which restores the
	 * original deletion behaviour for that branch only.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function purge() {
		/**
		 * Keep the AI agent account alive across an uninstall/reinstall cycle.
		 *
		 * On uninstall the plugin cleans up after itself: it deletes the agent
		 * user, revokes its credentials, and removes the custom role. Return
		 * false to preserve that account instead — ideal when you provision
		 * credentials out-of-band, ship the agent as part of a fleet image, or
		 * simply don't want a temporary deactivation to force every connected
		 * client to reconnect.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Survive reinstalls so connected clients keep working.
		 * add_filter( 'gk/block-mcp/agent/remove-on-uninstall', '__return_false' );
		 *
		 * @param bool $remove Whether to remove the agent on uninstall. Default true.
		 */
		if ( ! apply_filters( 'gk/block-mcp/agent/remove-on-uninstall', true ) ) {
			return;
		}

		$agent_id = (int) get_option( 'gk_block_api_agent_user_id' );

		if ( $agent_id > 0 && '1' === get_user_meta( $agent_id, self::META_FLAG, true ) ) {
			// Revoke all Application Passwords before the user account is deleted
			// so the credentials cannot be replayed even during a brief window
			// where the deleted user row might still reside in an opcode cache.
			\WP_Application_Passwords::delete_all_application_passwords( $agent_id );

			// Resolve the reassign target: filter first, then first administrator.
			/**
			 * Choose who inherits the agent's content when its account is deleted.
			 *
			 * When the agent user is removed on uninstall, everything it authored
			 * needs a new owner. By default that's the first administrator on the
			 * site — but you rarely want a bot's portfolio dumped on whoever
			 * happens to be admin #1. Point this at a dedicated editorial owner so
			 * the byline stays meaningful and nothing is orphaned. Return 0 to
			 * keep the default behaviour.
			 *
			 * @since 2.0.0
			 *
			 * @example
			 * // Hand the agent's posts to your managing editor (user ID 42).
			 * add_filter( 'gk/block-mcp/agent/reassign-to', function () {
			 *     return 42;
			 * } );
			 *
			 * @param int $reassign_to Target user ID, or 0 to use the first administrator. Default 0.
			 */
			$reassign = (int) apply_filters( 'gk/block-mcp/agent/reassign-to', 0 );

			if ( ! $reassign ) {
				$admins   = get_users(
					array(
						'role'   => 'administrator',
						'number' => 1,
						'fields' => 'ID',
					)
				);
				$reassign = $admins ? (int) $admins[0] : 0;
			}

			// wp_delete_user() lives in wp-admin/includes/user.php and is not
			// loaded on front-end or WP-CLI requests.
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			if ( is_multisite() ) {
				if ( ! function_exists( 'wpmu_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
				}

				// wpmu_delete_user() takes no reassign arg and deletes the
				// network-global agent's posts on every blog in one pass —
				// reassign on every blog first, or cross-blog content is lost.
				self::reassign_agent_posts_network_wide( $agent_id, $reassign );

				wpmu_delete_user( $agent_id );
			} else {
				wp_delete_user( $agent_id, $reassign ? $reassign : null );
			}
		}

		delete_option( 'gk_block_api_agent_user_id' );
		remove_role( self::ROLE );
	}

	/**
	 * Reassign the agent's authored posts to a surviving owner on every blog,
	 * for the multisite teardown path (the caller deletes the user network-wide
	 * right after).
	 *
	 * Per blog the target is the filter value when set, else that blog's first
	 * administrator — resolved per-blog so posts never go to a non-member. A blog
	 * with no resolvable target is skipped: its posts fall through to core's
	 * deletion, matching the single-site no-administrator fallback.
	 *
	 * @since 2.0.0
	 *
	 * @param  int $agent_id        Agent user ID whose posts are reassigned.
	 * @param  int $filter_reassign Explicit reassign target, or 0 to resolve per-blog.
	 * @return void
	 */
	private static function reassign_agent_posts_network_wide( $agent_id, $filter_reassign ) {
		global $wpdb;

		$blog_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );

			$target = $filter_reassign;
			if ( ! $target ) {
				$admins = get_users(
					array(
						'role'   => 'administrator',
						'number' => 1,
						'fields' => 'ID',
					)
				);
				$target = $admins ? (int) $admins[0] : 0;
			}

			if ( $target ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->posts,
					array( 'post_author' => $target ),
					array( 'post_author' => $agent_id ),
					array( '%d' ),
					array( '%d' )
				);
				clean_user_cache( $agent_id );
			}

			restore_current_blog();
		}
	}

	/**
	 * Block interactive login for the service account.
	 *
	 * Hooked to `authenticate` at priority 30 — after wp_authenticate_username_password
	 * and wp_authenticate_application_password (both at priority 20) — so it
	 * intercepts results from both paths.  It intentionally blocks ONLY interactive
	 * (non-API) login.  Application Password / REST / XML-RPC authentication uses
	 * this same filter, so the method returns $user unchanged when the request is
	 * an API request; doing otherwise would cut off the agent's own REST auth.
	 *
	 * The agent's random_bytes password already makes interactive login infeasible
	 * by any realistic attacker.  This filter is defence-in-depth for the
	 * interactive path only.
	 *
	 * Two rejection paths exist when the request is NOT an API request:
	 *  1. A prior filter has already resolved `$user` to the agent's WP_User.
	 *  2. `$user` is null or WP_Error and the supplied `$username` resolves to
	 *     the agent — the common case where standard password auth has not yet run.
	 *
	 * Pass-through for every other user, and for all API requests.
	 *
	 * @since 2.0.0
	 *
	 * @param null|\WP_User|\WP_Error $user     Authenticating user, or a prior filter's result.
	 * @param string                  $username Login name supplied by the caller.
	 * @return null|\WP_User|\WP_Error The user unchanged, or WP_Error for the service account.
	 */
	public static function block_agent_login( $user, string $username = '' ) {
		// Application Passwords authenticate via this same `authenticate` filter
		// (wp_authenticate_application_password, priority 20).  Never interfere
		// with API requests — the interactive-login block is defence-in-depth for
		// the browser login form only.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading a WordPress core filter, not registering a plugin-owned hook.
		$is_api_request = apply_filters(
			'application_password_is_api_request',
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $is_api_request ) {
			return $user;
		}

		// Path 1: a prior filter already resolved a WP_User — check the meta flag.
		if ( $user instanceof \WP_User && '1' === get_user_meta( $user->ID, self::META_FLAG, true ) ) {
			return new \WP_Error(
				'agent_no_login',
				__( 'This is a service account and cannot log in interactively.', 'gk-block-mcp' )
			);
		}

		// Path 2: look up by the supplied login when no WP_User has been resolved
		// yet. This covers both the pre-password-check case (user is null) and
		// the post-check case where a prior filter returned a WP_Error (e.g.
		// incorrect_password from wp_authenticate_username_password at priority
		// 20 — running after us). By re-checking the username at this point, the
		// block applies regardless of whether the password was correct or not.
		if ( ! $user instanceof \WP_User && '' !== $username ) {
			$looked_up = get_user_by( 'login', $username );
			if ( $looked_up instanceof \WP_User && '1' === get_user_meta( $looked_up->ID, self::META_FLAG, true ) ) {
				return new \WP_Error(
					'agent_no_login',
					__( 'This is a service account and cannot log in interactively.', 'gk-block-mcp' )
				);
			}
		}

		return $user;
	}
}
