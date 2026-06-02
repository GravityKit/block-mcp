<?php
/**
 * Connect_Page — admin "Connect an AI Assistant" wizard.
 *
 * Orchestrates the full connect flow: provisioning the agent service account,
 * minting an Application Password, and either streaming a pre-configured .mcpb
 * bundle (Claude Desktop) or returning a secret-free CLI command that the
 * connector CLI uses to drive a browser-Approve handshake.
 *
 * The testable cores are:
 *  - provision_credentials()    — shared credential path: ensure agent, issue password, return array.
 *  - prepare_installer()        — build the .mcpb bundle for Claude Desktop (calls provision_credentials()).
 *  - setup_artifact()           — assemble the ready-to-run npx command (no secret).
 *  - is_loopback_callback()     — validate a callback URL is loopback-only before redirecting creds to it.
 *  - connection_state()         — determines which render branch to show.
 *
 * Admin-menu registration, HTTP streaming, and the redirect-then-render
 * pattern stay as thin as possible so the seams above stay unit-testable.
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
 * Admin "Connect an AI Assistant" wizard for the GK Block API plugin.
 *
 * @since 1.9.0
 */
class Connect_Page {

	/**
	 * Form action for the connect (download bundle / generate config) handler.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const ACTION_CONNECT = 'gk_block_api_connect';

	/**
	 * Form action for the browser-Approve authorize handler.
	 *
	 * The connector CLI opens a browser to the admin page with ?gk_authorize set.
	 * The admin sees the Approve screen; submitting it POSTs here, mints a credential,
	 * and redirects the one-time secret to the loopback callback.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	const ACTION_AUTHORIZE = 'gk_block_api_authorize';

	/**
	 * Form action for the revoke (disconnect) handler.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const ACTION_REVOKE = 'gk_block_api_revoke';

	/**
	 * Slug used when registering the admin submenu page.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const PAGE_SLUG = 'gk-block-api-connect';

	/**
	 * Transient key prefix for one-time paste-mode passwords and setup artifacts.
	 *
	 * The full key is this prefix + the current user ID. The transient expires
	 * in 5 minutes — long enough for the redirect + page reload, short enough
	 * to minimise the window a password sits in the options table.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const PASTE_TRANSIENT_PREFIX = 'gk_block_api_paste_pw_';

	/**
	 * Provision the agent service account and mint a fresh Application Password.
	 *
	 * This is the shared credential-provisioning seam used by both
	 * prepare_installer() (for the .mcpb path) and handle_connect() (for the
	 * artifact path). It runs the full ensure → issue pipeline and returns the
	 * raw credential set so each caller can consume it in its own way.
	 *
	 * @since  1.10.0
	 *
	 * @param  string $client Display name for the connecting client (e.g. 'Claude Code').
	 * @return array|\WP_Error {
	 *     On success, a credential array ready for callers to use.
	 *
	 *     @type string $url      Untrailed home_url() base.
	 *     @type string $user     Agent user login.
	 *     @type string $password One-time plaintext Application Password.
	 *     @type string $uuid     UUID of the minted Application Password.
	 * }
	 */
	public function provision_credentials( $client ) {
		$agent = ( new Agent_Provisioner() )->ensure();
		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$issued = ( new App_Password_Issuer() )->issue( $agent, 'Block MCP — ' . $client );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$agent_user = get_user_by( 'id', $agent );

		return array(
			'url'      => untrailingslashit( home_url() ),
			'user'     => $agent_user ? $agent_user->user_login : Agent_Provisioner::LOGIN,
			'password' => $issued['password'],
			'uuid'     => $issued['uuid'],
		);
	}

	/**
	 * Provision the agent, mint a credential, and build a .mcpb bundle.
	 *
	 * Calls provision_credentials() then builds the .mcpb from the returned
	 * creds, keeping the .mcpb path unchanged for the Claude Desktop flow.
	 *
	 * @since  1.9.0
	 *
	 * @param  string      $client      Display name for the connecting client (e.g. 'Claude Desktop').
	 * @param  string|null $server_path Absolute path to index.cjs. Defaults to the bundled server.
	 * @return array|\WP_Error {
	 *     Success array — keys consumed by handle_connect() and render_page().
	 *
	 *     @type string $path     Absolute path to the generated temp .mcpb file.
	 *     @type string $filename Suggested download filename.
	 *     @type string $uuid     UUID of the minted Application Password.
	 *     @type string $mode     'prefill' or 'paste'.
	 *     @type string $password Plaintext password when mode=paste; empty string otherwise.
	 * }
	 */
	public function prepare_installer( $client, $server_path = null ) {
		if ( null === $server_path ) {
			$server_path = GK_BLOCK_API_PLUGIN_DIR . 'assets/mcp-server/index.cjs';
		}

		$creds = $this->provision_credentials( $client );
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		// Determine secret-at-rest mode. 'prefill' embeds the password in the
		// bundle so Claude Desktop pre-fills it on import. 'paste' leaves the
		// bundle's password field blank and returns the plaintext to the UI,
		// trading convenience for keeping the secret out of the download.
		$default_mode = ( defined( 'GK_BLOCK_API_FORCE_PASTE_SECRET' ) && GK_BLOCK_API_FORCE_PASTE_SECRET ) ? 'paste' : 'prefill';

		/**
		 * Filters the secret-at-rest mode for .mcpb bundle generation.
		 *
		 * Returning 'paste' causes the bundle to carry an empty password
		 * default; the plaintext is returned separately for a one-time UI
		 * display. Returning 'prefill' (the default) embeds the password so
		 * the installer requires no manual copy step.
		 *
		 * @since 1.9.0
		 *
		 * @param string $mode 'prefill'|'paste'.
		 */
		$mode = (string) apply_filters( 'gk_block_api_secret_at_rest_mode', $default_mode );

		$bundle_creds = array(
			'url'      => $creds['url'],
			'user'     => $creds['user'],
			'password' => ( 'paste' === $mode ) ? '' : $creds['password'],
			'client'   => $client,
		);

		$path = ( new MCPB_Generator() )->build( $bundle_creds, $server_path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = 'block-mcp-' . ( $host ? $host : 'WordPress' ) . '.mcpb';

		return array(
			'path'     => $path,
			'filename' => $filename,
			'uuid'     => $creds['uuid'],
			'mode'     => $mode,
			'password' => ( 'paste' === $mode ) ? $creds['password'] : '',
		);
	}

	/**
	 * Build the secret-free command artifact for a given client.
	 *
	 * Returns a label, language hint, and a raw body containing only an
	 * `npx -y @gravitykit/block-mcp connect` command. No password or credential
	 * of any kind appears in the body — the credential is delivered later via the
	 * browser-Approve handshake driven by the connector CLI.
	 *
	 * The body is RAW (not HTML-escaped). Callers that write it to HTML must
	 * escape it at output time — render_artifact_card() uses esc_textarea().
	 *
	 * @since  1.11.0
	 *
	 * @param  string $client   One of: 'Claude Code', 'Cursor', 'ChatGPT Desktop', 'ai-prompt'.
	 * @param  string $site_url Untrailed home_url() base to embed in the command.
	 * @return array {
	 *     @type string $label    Short description shown above the textarea (HTML-safe).
	 *     @type string $language Syntax hint ('bash', 'text').
	 *     @type string $body     Raw command string. Must be escaped by the caller before HTML output.
	 * }
	 */
	public function setup_artifact( $client, $site_url ) {
		switch ( $client ) {
			case 'Claude Code':
				return array(
					'label'    => esc_html__( 'Run this in your terminal. A browser window will open — click Approve, and the connection finishes automatically. No password to copy.', 'gk-block-api' ),
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client claude-code",
				);

			case 'Cursor':
				return array(
					'label'    => esc_html__( 'Run this in your terminal. A browser window will open — click Approve, and the connection finishes automatically. No password to copy.', 'gk-block-api' ),
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client cursor",
				);

			case 'ChatGPT Desktop':
				return array(
					'label'    => esc_html__( 'Run this in your terminal. A browser window will open — click Approve, and the connection finishes automatically. No password to copy.', 'gk-block-api' ),
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client chatgpt-desktop",
				);

			case 'ai-prompt':
			default:
				return array(
					'label'    => esc_html__( 'Paste this to your AI assistant. It will run the command, a browser window will open for you to click Approve, and then confirm it can read your blocks.', 'gk-block-api' ),
					'language' => 'text',
					'body'     =>
						"Run `npx -y @gravitykit/block-mcp connect --site {$site_url}` for me, " .
						'then approve the connection in the browser window that opens, ' .
						'and confirm you can read the blocks on one of my pages.',
				);
		}
	}

	/**
	 * Validate that a callback URL is a loopback-only address.
	 *
	 * The connector CLI listens on a random loopback port and passes this URL
	 * as the callback for the browser-Approve flow. Only loopback addresses are
	 * accepted so the minted credential cannot be redirected to a remote host.
	 *
	 * Valid: http://127.0.0.1:51791/cb, http://localhost:8080/callback, http://[::1]:3000/
	 * Invalid: https://evil.com/cb, missing port, file://, http://127.0.0.1.evil.com/
	 *
	 * @since  1.11.0
	 *
	 * @param  string $url Candidate callback URL.
	 * @return bool True when the URL is safe to redirect credentials to.
	 */
	public function is_loopback_callback( $url ) {
		$parts = wp_parse_url( $url );

		// Scheme must be http (plain loopback — no need for TLS on 127.0.0.1).
		if ( ! isset( $parts['scheme'] ) || 'http' !== $parts['scheme'] ) {
			return false;
		}

		// Host must be an explicit loopback address.
		if ( ! isset( $parts['host'] ) ) {
			return false;
		}
		$host           = $parts['host'];
		$loopback_hosts = array( '127.0.0.1', 'localhost', '[::1]', '::1' );
		if ( ! in_array( $host, $loopback_hosts, true ) ) {
			return false;
		}

		// A numeric port must be present — prevents ambiguous default-port redirects.
		if ( ! isset( $parts['port'] ) || ! is_int( $parts['port'] ) ) {
			return false;
		}

		// No userinfo — prevents http://user@evil.com/ style URL confusion.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine the current connection state for render_page() branching.
	 *
	 * @since  1.9.0
	 *
	 * @return string 'needs_https' | 'connected' | 'ready'
	 */
	public function connection_state() {
		if ( ! wp_is_application_passwords_available() ) {
			return 'needs_https';
		}

		$agent_id = (int) get_option( 'gk_block_api_agent_user_id', 0 );
		if ( $agent_id > 0 ) {
			$connections = ( new Connections() )->list( $agent_id );
			if ( ! empty( $connections ) ) {
				return 'connected';
			}
		}

		return 'ready';
	}

	/**
	 * Register admin_post handlers for connect, authorize, and revoke actions.
	 *
	 * The menu page is hosted by Settings_Page; only the form-action handlers
	 * need to be wired here.
	 *
	 * @since 1.9.0
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_AUTHORIZE, array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( $this, 'handle_revoke' ) );
	}

	/**
	 * Handle the connect form submission.
	 *
	 * For Claude Desktop: provisions credentials, builds the .mcpb bundle, and
	 * streams it as an octet-stream download (unchanged behaviour).
	 *
	 * For Claude Code, Cursor, ChatGPT Desktop, and ai-prompt: does NOT provision
	 * any credential. Instead redirects back to the connect tab with ?setup=<client>
	 * so render_section() can display the secret-free CLI command for that client.
	 * The credential is delivered later via the browser-Approve handshake when the
	 * user runs the printed npx command and clicks Approve.
	 *
	 * For 'other': redirects back with ?other=1 so the "coming soon" note is shown.
	 *
	 * @since 1.9.0
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_CONNECT );

		$client = isset( $_POST['client'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via check_admin_referer.
			? sanitize_text_field( wp_unslash( $_POST['client'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: 'Claude Desktop';

		if ( '' === $client ) {
			$client = 'Claude Desktop';
		}

		// Command-artifact clients: no provisioning — redirect back with the client
		// slug so render_section() can display the secret-free npx command.
		$artifact_clients = array( 'Claude Code', 'Cursor', 'ChatGPT Desktop', 'ai-prompt' );
		if ( in_array( $client, $artifact_clients, true ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => Settings_Page::PAGE_SLUG,
						'tab'   => 'connect',
						'setup' => rawurlencode( $client ),
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// 'other' client: redirect with a note flag, no provisioning.
		if ( 'other' === $client ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => Settings_Page::PAGE_SLUG,
						'tab'   => 'connect',
						'other' => '1',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// Default: Claude Desktop — stream the .mcpb bundle.
		$r = $this->prepare_installer( $client );

		if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ) );
		}

		// Stash the plaintext for paste-mode so render_page() can show it once
		// on the redirect back without re-minting.
		if ( 'paste' === $r['mode'] && '' !== $r['password'] ) {
			$transient_key = self::PASTE_TRANSIENT_PREFIX . get_current_user_id();
			set_transient( $transient_key, $r['password'], 5 * MINUTE_IN_SECONDS );
		}

		$path = $r['path'];

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $r['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		try {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} catch ( \Throwable $e ) {
			// Only surface an error page if nothing has been streamed yet —
			// once octet-stream + Content-Length headers are out, the response
			// body is committed and WordPress's HTML error handler would
			// corrupt the partial download.
			if ( ! headers_sent() ) {
				wp_die( esc_html__( 'An error occurred while preparing your download.', 'gk-block-api' ) );
			}
		} finally {
			wp_delete_file( $path );
		}

		exit;
	}

	/**
	 * Handle the browser-Approve authorize POST.
	 *
	 * The connector CLI opens a browser to the authorize screen; the admin sees
	 * a clear Approve/Cancel prompt. Submitting Approve POSTs here. This handler:
	 *  1. Verifies manage_options + nonce (authorization gate).
	 *  2. Reads and sanitizes callback, state, and client from POST.
	 *  3. Validates the callback is a loopback-only URL (credential-redirect guard).
	 *  4. Provisions / re-uses the agent account and mints one Application Password.
	 *  5. Redirects the credential set to the callback — credential stays on-machine.
	 *
	 * wp_redirect() is used instead of wp_safe_redirect() because the target host
	 * is loopback (already validated by is_loopback_callback()) and is therefore
	 * not in WordPress's allowed_redirect_hosts list.
	 *
	 * @since 1.11.0
	 */
	public function handle_authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_AUTHORIZE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above via check_admin_referer.
		$callback = isset( $_POST['callback'] ) ? sanitize_text_field( wp_unslash( $_POST['callback'] ) ) : '';
		$state    = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$client   = isset( $_POST['client'] ) ? sanitize_text_field( wp_unslash( $_POST['client'] ) ) : 'block-mcp';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Callback must resolve to a loopback address — credential must never leave
		// the local machine via an attacker-controlled redirect target.
		if ( ! $this->is_loopback_callback( $callback ) ) {
			wp_die(
				esc_html__( 'Invalid callback URL. Only loopback addresses (127.0.0.1, localhost) are accepted.', 'gk-block-api' ),
				esc_html__( 'Authorization failed', 'gk-block-api' ),
				array( 'response' => 400 )
			);
		}

		$creds = $this->provision_credentials( $client );
		if ( is_wp_error( $creds ) ) {
			wp_die( esc_html( $creds->get_error_message() ) );
		}

		$redirect = add_query_arg(
			array(
				'site'     => rawurlencode( $creds['url'] ),
				'user'     => rawurlencode( $creds['user'] ),
				'password' => rawurlencode( $creds['password'] ),
				'state'    => rawurlencode( $state ),
			),
			$callback
		);

		// wp_redirect() is intentional: the validated loopback host is never in
		// WordPress's allowed_redirect_hosts list, but is_loopback_callback()
		// above already confirmed it is safe.
		wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Revoke the Application Password identified by UUID for the agent user.
	 *
	 * This is the testable core of handle_revoke(). It performs the agent-id
	 * lookup and delegates deletion to Connections::revoke(), returning the
	 * boolean result. Cap/nonce enforcement and the redirect stay in the HTTP
	 * handler so tests can call this seam directly without triggering exit.
	 *
	 * @since  1.9.0
	 *
	 * @param  string $uuid UUID of the Application Password to delete.
	 * @return bool True when the credential was deleted, false otherwise.
	 */
	public function do_revoke( $uuid ) {
		$agent_id = (int) get_option( 'gk_block_api_agent_user_id', 0 );

		if ( $agent_id <= 0 || '' === $uuid ) {
			return false;
		}

		return ( new Connections() )->revoke( $agent_id, $uuid );
	}

	/**
	 * Handle a revoke (disconnect) form submission.
	 *
	 * Validates capabilities and nonce, delegates the credential deletion to
	 * do_revoke(), then redirects back to the page with a success query parameter.
	 *
	 * @since 1.9.0
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_REVOKE );

		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.

		$this->do_revoke( $uuid );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Settings_Page::PAGE_SLUG,
					'tab'     => 'connect',
					'revoked' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Connect onboarding section.
	 *
	 * Outputs only the Connect content — the heading, status notices, the client
	 * picker form, the after-download next-steps panel, and the active-connections
	 * table. The outer <div class="wrap"> and page <h1> are supplied by the host
	 * Settings_Page so this section can live inside a tab without double-wrapping.
	 *
	 * When $_GET['gk_authorize'] is set, renders the browser-Approve screen instead
	 * of the normal connect UI (Part A — authorize mode).
	 *
	 * When $_GET['setup'] carries a client name (written by handle_connect()), the
	 * command artifact for that client is displayed in a readonly textarea. No
	 * credential is shown — the secret arrives later via the Approve handshake.
	 *
	 * Branches on connection_state(): shows an HTTPS requirement notice, a connect
	 * form with client picker, or an active-connections list with revoke buttons.
	 *
	 * All selectors are scoped under .gk-connect to avoid leaking into the rest
	 * of wp-admin.
	 *
	 * @since 1.9.0
	 */
	public function render_section() {
		$state = $this->connection_state();

		// ── Authorize mode ────────────────────────────────────────────────────
		// When the connector CLI sends the admin to ?gk_authorize=1 we show a
		// clear Approve/Cancel prompt instead of the normal connect UI.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- gk_authorize is a mode flag, not user data.
		if ( isset( $_GET['gk_authorize'] ) ) {
			$callback  = isset( $_GET['callback'] ) ? sanitize_text_field( wp_unslash( $_GET['callback'] ) ) : '';
			$state_val = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			$client    = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : 'block-mcp';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$this->render_authorize_screen( $callback, $state_val, $client );
			return;
		}

		// ── Command-artifact mode ─────────────────────────────────────────────
		// handle_connect() redirects back with ?setup=<client> for non-Desktop
		// clients. Render the secret-free command artifact for that client.
		$setup_client = ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_setup        = sanitize_text_field( wp_unslash( $_GET['setup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$artifact_clients = array( 'Claude Code', 'Cursor', 'ChatGPT Desktop', 'ai-prompt' );
			if ( in_array( $raw_setup, $artifact_clients, true ) ) {
				$setup_client = $raw_setup;
			}
		}

		$setup_data = null;
		if ( '' !== $setup_client ) {
			$site_url   = untrailingslashit( home_url() );
			$setup_data = array(
				'client'   => $setup_client,
				'artifact' => $this->setup_artifact( $setup_client, $site_url ),
			);
		}

		// Legacy scalar path: Claude Desktop paste-mode password (shown once after
		// a paste-mode .mcpb download).
		$paste_pw      = '';
		$transient_key = self::PASTE_TRANSIENT_PREFIX . get_current_user_id();
		$stored        = get_transient( $transient_key );
		if ( is_string( $stored ) && '' !== $stored ) {
			$paste_pw = $stored;
			delete_transient( $transient_key );
		}

		// Read-only query-string flags from our own redirects (nonce-free: value
		// is an integer flag, no user data in the message).
		$revoked = isset( $_GET['revoked'] ) ? absint( wp_unslash( $_GET['revoked'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Active connections for the 'connected' state.
		$connections = array();
		if ( 'connected' === $state ) {
			$agent_id    = (int) get_option( 'gk_block_api_agent_user_id', 0 );
			$connections = $agent_id > 0 ? ( new Connections() )->list( $agent_id ) : array();
		}

		?>
		<div class="gk-connect">

		<?php if ( $revoked ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Connection disconnected successfully.', 'gk-block-api' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $paste_pw ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Your application password (shown once):', 'gk-block-api' ); ?></strong><br />
					<code class="gk-connect__paste-pw"><?php echo esc_html( $paste_pw ); ?></code>
				</p>
				<p><?php esc_html_e( 'Copy this password and paste it into the Application Password field when you open the downloaded file. It will not be shown again.', 'gk-block-api' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( null !== $setup_data ) : ?>
			<?php $this->render_artifact_card( $setup_data['client'], $setup_data['artifact'] ); ?>
		<?php endif; ?>

		<div class="gk-connect__card">

			<h2 class="gk-connect__heading"><?php esc_html_e( 'Connect an AI Assistant to Your Site', 'gk-block-api' ); ?></h2>

			<p class="gk-connect__intro">
				<?php esc_html_e( 'This lets an AI app like Claude write and edit the pages and posts on your site for you. Setup takes about a minute — no passwords to copy, no technical files to edit.', 'gk-block-api' ); ?>
			</p>
			<p class="gk-connect__intro">
				<?php esc_html_e( "Setup creates a dedicated 'Block MCP' account the AI uses, separate from your own login. You can disconnect it anytime below.", 'gk-block-api' ); ?>
			</p>

			<?php if ( 'needs_https' === $state ) : ?>

				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'HTTPS required', 'gk-block-api' ); ?></strong>
					</p>
					<p>
						<?php esc_html_e( 'Your site needs a secure connection (HTTPS) first. Most hosts can enable this for free — ask them to turn on HTTPS/SSL, then come back.', 'gk-block-api' ); ?>
					</p>
				</div>

			<?php else : ?>

				<?php if ( 'connected' === $state ) : ?>
					<p class="gk-connect__connected-badge"><strong>&#x2705; <?php esc_html_e( "You're connected", 'gk-block-api' ); ?></strong></p>
				<?php endif; ?>

				<?php $this->render_connect_form(); ?>
				<?php $this->render_next_steps(); ?>

				<?php if ( 'connected' === $state && ! empty( $connections ) ) : ?>
					<div class="gk-connect__connections-card">
						<h3 class="gk-connect__connections-heading"><?php esc_html_e( 'Active connections', 'gk-block-api' ); ?></h3>
						<p class="gk-connect__connections-desc">
							<?php esc_html_e( 'Each entry below is one connected AI client. Clicking Disconnect immediately revokes that client\'s access.', 'gk-block-api' ); ?>
						</p>
						<table class="gk-connect__connections-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Client', 'gk-block-api' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Connected', 'gk-block-api' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last used', 'gk-block-api' ); ?></th>
									<th scope="col"></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $connections as $conn ) : ?>
									<tr>
										<td><?php echo esc_html( $conn['name'] ); ?></td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ), $conn['created'] ) ); ?></td>
										<td>
											<?php
											echo $conn['last_used']
												? esc_html( wp_date( get_option( 'date_format' ), $conn['last_used'] ) )
												: esc_html__( 'Never', 'gk-block-api' );
											?>
										</td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVOKE ); ?>" />
												<input type="hidden" name="uuid" value="<?php echo esc_attr( $conn['uuid'] ); ?>" />
												<?php wp_nonce_field( self::ACTION_REVOKE ); ?>
												<button type="submit" class="gk-connect__disconnect-btn button button-link"><?php esc_html_e( 'Disconnect', 'gk-block-api' ); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

			<?php endif; ?>

		</div><!-- /.gk-connect__card -->

		</div><!-- /.gk-connect -->
		<?php
	}

	/**
	 * Render the setup-artifact card shown after a successful non-.mcpb connect.
	 *
	 * Displays a readonly textarea containing the secret-free `npx connect` command
	 * and a single Copy button. No password field is shown — the credential is
	 * delivered later via the browser-Approve handshake when the user runs the
	 * command and clicks Approve in the browser window that opens.
	 *
	 * @since 1.10.0
	 * @since 1.11.0 Password param removed; command-only artifact, no credential shown.
	 *
	 * @param string $client   Client name (e.g. 'Claude Code').
	 * @param array  $artifact Return value of setup_artifact().
	 * @return void
	 */
	private function render_artifact_card( $client, array $artifact ) {
		?>
		<div class="gk-connect__artifact-card">
			<h3 class="gk-connect__artifact-heading">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: AI client name e.g. "Claude Code" */
						__( '%s setup', 'gk-block-api' ),
						$client
					)
				);
				?>
			</h3>

			<p class="gk-connect__artifact-label"><?php echo $artifact['label']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in setup_artifact(). ?></p>
			<div class="gk-connect__artifact-copy-wrap">
				<textarea
					class="gk-connect__artifact-textarea"
					readonly
					rows="3"
					data-language="<?php echo esc_attr( $artifact['language'] ); ?>"
				><?php echo esc_textarea( $artifact['body'] ); ?></textarea>
				<button type="button" class="gk-connect__artifact-copy-btn button" data-target="artifact"><?php esc_html_e( 'Copy', 'gk-block-api' ); ?></button>
			</div>
			<p class="gk-connect__artifact-no-password-note">
				<?php esc_html_e( 'A browser window will open — click Approve, and the connection finishes automatically. No password to copy.', 'gk-block-api' ); ?>
			</p>
		</div>

		<style>
		.gk-connect__artifact-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
			border-radius: 4px;
			padding: 16px 20px;
			max-width: 800px;
			margin-bottom: 20px;
		}
		.gk-connect__artifact-heading {
			font-size: 1em;
			font-weight: 600;
			color: #1e1e1e;
			margin: 0 0 8px;
		}
		.gk-connect__artifact-label {
			font-size: .9375em;
			color: #1e1e1e;
			margin: 0 0 6px;
		}
		.gk-connect__artifact-copy-wrap {
			display: flex;
			gap: 8px;
			align-items: flex-start;
		}
		.gk-connect__artifact-textarea {
			flex: 1;
			font-family: monospace;
			font-size: .875em;
			resize: vertical;
			background: #f6f7f7;
			border: 1px solid #c3c4c7;
			border-radius: 2px;
			padding: 8px;
			color: #1e1e1e;
		}
		.gk-connect__artifact-copy-btn {
			flex-shrink: 0;
		}
		.gk-connect__artifact-no-password-note {
			font-size: .875em;
			color: #757575;
			margin: 8px 0 0;
		}
		</style>

		<script>
		(function () {
			var card = document.querySelector( '.gk-connect__artifact-card' );
			if ( ! card ) return;

			var artifactTextarea = card.querySelector( '.gk-connect__artifact-textarea' );
			var artifactCopyBtn  = card.querySelector( '.gk-connect__artifact-copy-btn' );
			if ( ! artifactTextarea || ! artifactCopyBtn ) return;

			artifactCopyBtn.addEventListener( 'click', function () {
				var defaultLabel = '<?php echo esc_js( __( 'Copy', 'gk-block-api' ) ); ?>';
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( artifactTextarea.value ).then( function () {
						artifactCopyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'gk-block-api' ) ); ?>';
						setTimeout( function () { artifactCopyBtn.textContent = defaultLabel; }, 2000 );
					} );
				} else {
					artifactTextarea.select();
					document.execCommand( 'copy' );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render the browser-Approve screen.
	 *
	 * Shown when render_section() detects ?gk_authorize in the query string.
	 * Presents a clear heading, site/client context, and Approve/Cancel controls.
	 * The Approve form POSTs to handle_authorize(), carrying the loopback callback,
	 * state token, and client label as hidden fields with a nonce.
	 *
	 * @since 1.11.0
	 *
	 * @param string $callback Loopback callback URL (displayed for context; validated on POST).
	 * @param string $state    Opaque state token from the connector CLI (echoed back on redirect).
	 * @param string $client   Client label sent by the connector CLI (e.g. 'block-mcp').
	 * @return void
	 */
	private function render_authorize_screen( $callback, $state, $client ) {
		$site_name = get_bloginfo( 'name' );
		?>
		<div class="gk-connect">
		<div class="gk-connect__card">

			<h2 class="gk-connect__heading"><?php esc_html_e( 'Authorize a connection', 'gk-block-api' ); ?></h2>

			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: site name, 2: client label */
						__( 'A local application on this computer is asking to connect to <strong>%1$s</strong> as the Block MCP agent (<code>%2$s</code>).', 'gk-block-api' ),
						esc_html( $site_name ),
						esc_html( $client )
					),
					array(
						'strong' => array(),
						'code'   => array(),
					)
				);
				?>
			</p>
			<p><?php esc_html_e( 'Approving creates or reuses the dedicated block-mcp account and sends a credential to the local app. You can revoke it anytime from this page.', 'gk-block-api' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action"   value="<?php echo esc_attr( self::ACTION_AUTHORIZE ); ?>" />
				<input type="hidden" name="callback" value="<?php echo esc_attr( $callback ); ?>" />
				<input type="hidden" name="state"    value="<?php echo esc_attr( $state ); ?>" />
				<input type="hidden" name="client"   value="<?php echo esc_attr( $client ); ?>" />
				<?php wp_nonce_field( self::ACTION_AUTHORIZE ); ?>
				<?php submit_button( __( 'Approve', 'gk-block-api' ), 'primary', 'submit', false ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Settings_Page::PAGE_SLUG . '&tab=connect' ) ); ?>">
					<?php esc_html_e( 'Cancel', 'gk-block-api' ); ?>
				</a>
			</p>

		</div><!-- /.gk-connect__card -->
		</div><!-- /.gk-connect -->
		<?php
	}

	/**
	 * Render the client-picker form that triggers a bundle download or artifact generation.
	 *
	 * The picker is a fieldset of radio cards so keyboard navigation, screen
	 * readers, and pointer devices all work with standard browser behaviour.
	 * Six clients are offered: Claude Desktop (.mcpb download), Claude Code,
	 * Cursor, ChatGPT Desktop, an "ai-prompt" path, and an "other" fallback.
	 * The "Let my AI set it up" card is visually prominent with an accent
	 * left-border modifier so it is an obvious choice for users who are already
	 * in an AI session.
	 *
	 * All selectors are scoped under .gk-connect to prevent leaking into
	 * the rest of wp-admin. The design follows the WordPress block-editor /
	 *
	 * @wordpress/components visual language: white card surfaces on the gray
	 * admin background, accent-color via --wp-admin-theme-color.
	 *
	 * @since 1.9.0
	 */
	private function render_connect_form() {
		?>
		<style>
		/* ── Outer card ────────────────────────────────────────────────────── */
		.gk-connect__card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
			padding: 24px 28px;
			max-width: 800px;
			margin-top: 16px;
		}

		/* ── Typography ────────────────────────────────────────────────────── */
		.gk-connect__heading {
			font-size: 1.1em;
			font-weight: 600;
			color: #1e1e1e;
			margin: 0 0 12px;
			padding: 0;
			border: none;
		}
		.gk-connect__intro {
			color: #1e1e1e;
			margin: 0 0 12px;
		}
		.gk-connect__connected-badge {
			color: #1e1e1e;
			margin: 0 0 16px;
		}

		/* ── Paste-mode password display ───────────────────────────────────── */
		.gk-connect__paste-pw {
			font-size: 1.1em;
			user-select: all;
		}

		/* ── Radio card group ──────────────────────────────────────────────── */
		.gk-radio-card-group {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
			gap: 12px;
			margin: 8px 0 16px;
		}
		.gk-radio-card {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 14px 16px;
			cursor: pointer;
			transition: border-color .1s, box-shadow .1s;
		}
		.gk-radio-card:hover {
			border-color: var(--wp-admin-theme-color, #2271b1);
		}
		.gk-radio-card:has(input:focus-visible) {
			outline: 2px solid var(--wp-admin-theme-color, #2271b1);
			outline-offset: 2px;
		}
		.gk-radio-card:has(input:checked),
		.gk-radio-card.is-selected {
			border-color: var(--wp-admin-theme-color, #2271b1);
			box-shadow: 0 0 0 1px var(--wp-admin-theme-color, #2271b1);
			background: #fff;
		}

		/* ── "Let my AI set it up" accent card ─────────────────────────────── */
		.gk-radio-card.is-ai {
			border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
		}
		.gk-radio-card.is-ai:has(input:checked),
		.gk-radio-card.is-ai.is-selected {
			background: #f0f6fc;
		}

		.gk-radio-card__radio {
			margin-top: 3px;
			flex-shrink: 0;
			accent-color: var(--wp-admin-theme-color, #2271b1);
		}
		.gk-radio-card__body {
			display: flex;
			flex-direction: column;
			gap: 3px;
		}
		.gk-radio-card__title {
			font-weight: 600;
			color: #1e1e1e;
			line-height: 1.4;
		}
		.gk-radio-card__desc {
			font-size: .875em;
			color: #757575;
			line-height: 1.4;
		}

		/* ── Primary submit button (components Button is-primary style) ─────── */
		.gk-connect #submit {
			background: var(--wp-admin-theme-color, #2271b1);
			color: #fff;
			border: none;
			border-radius: 2px;
			padding: 6px 16px;
			min-height: 36px;
			font-size: 13px;
			line-height: 1.4;
			font-weight: 500;
			cursor: pointer;
			text-decoration: none;
			box-shadow: none;
		}
		.gk-connect #submit:hover,
		.gk-connect #submit:active {
			background: var(--wp-admin-theme-color-darker-10, #1d6196);
			color: #fff;
		}
		.gk-connect #submit:focus-visible {
			outline: none;
			box-shadow: 0 0 0 1.5px #fff, 0 0 0 3px var(--wp-admin-theme-color, #2271b1);
		}

		/* ── "After you download/set up" inner panel ───────────────────────── */
		.gk-connect__next-steps {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 4px;
			padding: 16px 20px;
			max-width: 700px;
			margin-top: 24px;
		}
		.gk-connect__next-steps h3 {
			margin-top: 0;
			font-weight: 600;
			color: #1e1e1e;
		}
		.gk-connect__next-steps ol {
			margin: 0 0 12px;
			padding-left: 1.5em;
		}
		.gk-connect__next-steps li {
			color: #1e1e1e;
			margin-bottom: 8px;
			line-height: 1.5;
		}
		.gk-connect__next-steps p {
			margin-bottom: 0;
			color: #757575;
		}

		/* ── Active connections inner panel ────────────────────────────────── */
		.gk-connect__connections-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 4px;
			padding: 16px 20px;
			max-width: 800px;
			margin-top: 24px;
		}
		.gk-connect__connections-heading {
			font-size: 1em;
			font-weight: 600;
			color: #1e1e1e;
			margin: 0 0 4px;
		}
		.gk-connect__connections-desc {
			color: #757575;
			font-size: .875em;
			margin: 0 0 12px;
		}
		.gk-connect__connections-table {
			width: 100%;
			border-collapse: collapse;
		}
		.gk-connect__connections-table th {
			text-align: left;
			font-weight: 600;
			color: #757575;
			font-size: .8125em;
			text-transform: uppercase;
			letter-spacing: .03em;
			padding: 6px 12px 6px 0;
			border-bottom: 1px solid #f0f0f1;
		}
		.gk-connect__connections-table td {
			color: #1e1e1e;
			padding: 10px 12px 10px 0;
			border-bottom: 1px solid #f0f0f1;
			font-size: .9375em;
		}
		.gk-connect__connections-table tr:last-child td {
			border-bottom: none;
		}

		/* ── Disconnect button (link-button, tertiary style) ───────────────── */
		.gk-connect__disconnect-btn.button.button-link {
			color: var(--wp-admin-theme-color, #2271b1);
			text-decoration: none;
			padding: 0;
			background: none;
			border: none;
			box-shadow: none;
			font-size: .9375em;
			cursor: pointer;
			height: auto;
			min-height: 0;
			line-height: inherit;
		}
		.gk-connect__disconnect-btn.button.button-link:hover {
			color: var(--wp-admin-theme-color-darker-10, #1d6196);
			text-decoration: underline;
		}
		.gk-connect__disconnect-btn.button.button-link:focus-visible {
			outline: 2px solid var(--wp-admin-theme-color, #2271b1);
			outline-offset: 2px;
			box-shadow: none;
		}
		</style>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
			<?php wp_nonce_field( self::ACTION_CONNECT ); ?>

			<fieldset style="border:none; margin:0; padding:0;">
				<legend style="font-weight:600; margin-bottom:8px;">
					<?php esc_html_e( 'Which app do you use to chat with AI?', 'gk-block-api' ); ?>
				</legend>

				<div class="gk-radio-card-group" role="radiogroup">

					<label class="gk-radio-card is-selected" id="gk-card-claude-desktop">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="Claude Desktop"
							checked
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( 'Claude Desktop app', 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( 'One-click install. Recommended.', 'gk-block-api' ); ?></span>
						</span>
					</label>

					<label class="gk-radio-card" id="gk-card-claude-code">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="Claude Code"
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( 'Claude Code', 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( "Anthropic's terminal coding agent.", 'gk-block-api' ); ?></span>
						</span>
					</label>

					<label class="gk-radio-card" id="gk-card-cursor">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="Cursor"
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( 'Cursor', 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( 'AI code editor.', 'gk-block-api' ); ?></span>
						</span>
					</label>

					<label class="gk-radio-card" id="gk-card-chatgpt">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="ChatGPT Desktop"
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( 'ChatGPT Desktop', 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( 'OpenAI desktop app.', 'gk-block-api' ); ?></span>
						</span>
					</label>

					<label class="gk-radio-card is-ai" id="gk-card-ai-prompt">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="ai-prompt"
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( 'Let my AI set it up for me', 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( 'Copy a prompt and let your AI assistant configure it.', 'gk-block-api' ); ?></span>
						</span>
					</label>

					<label class="gk-radio-card" id="gk-card-other">
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="other"
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php esc_html_e( "Something else / I'm not sure", 'gk-block-api' ); ?></span>
							<span class="gk-radio-card__desc"><?php esc_html_e( 'Web apps, or not sure yet.', 'gk-block-api' ); ?></span>
						</span>
					</label>

				</div>

				<p class="description" id="gk-block-api-other-note" style="display:none; color:#646970; margin-top:4px;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: mailto link */
							__( 'Browser-based setup is coming soon. In the meantime, <a href="%s">contact support</a> or install Claude Desktop to get started.', 'gk-block-api' ),
							'mailto:support@gravitykit.com'
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			</fieldset>

			<script>
			(function () {
				var radios = document.querySelectorAll( 'input[name="client"]' );
				var note   = document.getElementById( 'gk-block-api-other-note' );
				var btn    = document.getElementById( 'submit' );

				if ( ! radios.length ) return;

				var labels = {
					'Claude Desktop' : '<?php echo esc_js( __( 'Download installer', 'gk-block-api' ) ); ?>',
					'Claude Code'    : '<?php echo esc_js( __( 'Generate setup config', 'gk-block-api' ) ); ?>',
					'Cursor'         : '<?php echo esc_js( __( 'Generate setup config', 'gk-block-api' ) ); ?>',
					'ChatGPT Desktop': '<?php echo esc_js( __( 'Generate setup config', 'gk-block-api' ) ); ?>',
					'ai-prompt'      : '<?php echo esc_js( __( 'Copy AI setup prompt', 'gk-block-api' ) ); ?>',
					'other'          : '<?php echo esc_js( __( 'Choose an app above', 'gk-block-api' ) ); ?>'
				};

				function updateState() {
					var checkedVal = '';
					radios.forEach( function ( r ) {
						var card = r.closest( '.gk-radio-card' );
						if ( r.checked ) {
							checkedVal = r.value;
							if ( card ) card.classList.add( 'is-selected' );
						} else {
							if ( card ) card.classList.remove( 'is-selected' );
						}
					} );

					if ( note ) {
						note.style.display = ( 'other' === checkedVal ) ? '' : 'none';
					}

					if ( btn ) {
						var label = labels[ checkedVal ] || labels[ 'Claude Desktop' ];
						btn.value = label;
					}
				}

				radios.forEach( function ( r ) {
					r.addEventListener( 'change', updateState );
				} );

				updateState();
			} )();
			</script>

			<?php submit_button( __( 'Download installer', 'gk-block-api' ), 'primary', 'submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Render the static "After you download" numbered next-steps panel.
	 *
	 * Shown whenever the connect form is visible so the post-download moment
	 * is never silent — users know what to do with the file immediately.
	 *
	 * @since 1.9.0
	 */
	private function render_next_steps() {
		?>
		<div class="gk-connect__next-steps">
			<h3><?php esc_html_e( 'After you download', 'gk-block-api' ); ?></h3>
			<ol>
				<li>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: download URL */
							__( 'Get the <a href="%s" target="_blank" rel="noopener noreferrer">Claude Desktop app</a> if you don\'t have it yet.', 'gk-block-api' ),
							'https://claude.ai/download'
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</li>
				<li>
					<?php
					echo wp_kses(
						__( 'Open the downloaded file (it\'s named like <code>block-mcp-yoursite.mcpb</code>) by double-clicking it.', 'gk-block-api' ),
						array( 'code' => array() )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Click Enable — everything\'s pre-filled.', 'gk-block-api' ); ?></li>
				<li>
					<?php
					echo wp_kses(
						__( 'Try asking: <em>&#8220;Edit the homepage on my site.&#8221;</em>', 'gk-block-api' ),
						array( 'em' => array() )
					);
					?>
				</li>
			</ol>
			<p>
				<?php esc_html_e( 'That file briefly holds a private key; once you\'ve clicked Enable you can delete it from Downloads — your AI app has stored the key securely.', 'gk-block-api' ); ?>
			</p>
		</div>
		<?php
	}
}
