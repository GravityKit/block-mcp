<?php
/**
 * Connect_Page — admin "Connect an AI Assistant" wizard.
 *
 * Orchestrates the full connect flow: provisioning the agent service account,
 * minting an Application Password, and streaming a pre-configured .mcpb
 * bundle to the browser. Also renders the active-connections list and handles
 * individual revoke requests.
 *
 * The testable core is prepare_installer() — it assembles the bundle creds and
 * calls into Agent_Provisioner, App_Password_Issuer, and MCPB_Generator. All
 * admin-menu registration and HTTP-streaming logic delegates here but is kept
 * as thin as possible so the seam stays unit-testable.
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
	 * Form action for the connect (download bundle) handler.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const ACTION_CONNECT = 'gk_block_api_connect';

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
	 * Transient key prefix for one-time paste-mode passwords.
	 *
	 * The full key is this prefix + the current user ID. The transient expires
	 * in 5 minutes — long enough for the download + page reload, short enough
	 * to minimise the window a password sits in the options table.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const PASTE_TRANSIENT_PREFIX = 'gk_block_api_paste_pw_';

	/**
	 * Provision the agent, mint a credential, and build a .mcpb bundle.
	 *
	 * This is the testable seam for the connect flow. All decisions — which
	 * URL to embed, whether to prefill or blank the password — happen here.
	 * The HTTP streaming / cleanup happens in handle_connect(), keeping I/O
	 * out of this method.
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

		// Provision (or resolve) the agent service account.
		$agent = ( new Agent_Provisioner() )->ensure();
		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		// Mint an Application Password for this client.
		$issued = ( new App_Password_Issuer() )->issue( $agent, 'Block MCP — ' . $client );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$issued_plaintext = $issued['password'];
		$issued_uuid      = $issued['uuid'];

		// Determine secret-at-rest mode. 'prefill' embeds the password in the
		// bundle so Claude Desktop pre-fills it on import. 'paste' leaves the
		// bundle's password field blank and returns the plaintext to the UI,
		// trading convenience for keeping the secret out of the download.
		$force_paste = ( defined( 'GK_BLOCK_API_FORCE_PASTE_SECRET' ) && GK_BLOCK_API_FORCE_PASTE_SECRET ) ? 'paste' : 'prefill';

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
		$mode = (string) apply_filters( 'gk_block_api_secret_at_rest_mode', $force_paste );

		// Build credentials for the bundle generator.
		$agent_user = get_user_by( 'id', $agent );

		$creds = array(
			'url'      => untrailingslashit( home_url() ),
			'user'     => $agent_user ? $agent_user->user_login : Agent_Provisioner::LOGIN,
			'password' => ( 'paste' === $mode ) ? '' : $issued_plaintext,
			'client'   => $client,
		);

		$path = ( new MCPB_Generator() )->build( $creds, $server_path );

		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = 'block-mcp-' . ( $host ? $host : 'WordPress' ) . '.mcpb';

		return array(
			'path'     => $path,
			'filename' => $filename,
			'uuid'     => $issued_uuid,
			'mode'     => $mode,
			'password' => ( 'paste' === $mode ) ? $issued_plaintext : '',
		);
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
	 * Register all admin hooks. Safe to call from plugins_loaded.
	 *
	 * @since 1.9.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( $this, 'handle_revoke' ) );
	}

	/**
	 * Register the admin submenu page under Settings.
	 *
	 * @since 1.9.0
	 */
	public function register_menu() {
		add_options_page(
			__( 'Connect an AI Assistant', 'gk-block-api' ),
			__( 'AI Assistant', 'gk-block-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the connect form submission: build and stream the .mcpb bundle.
	 *
	 * Validates capabilities and nonce, calls prepare_installer(), then streams
	 * the temp file as an octet-stream download. The try/finally block ensures
	 * the temp file is deleted even if streaming raises an exception.
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
		} finally {
			wp_delete_file( $path );
		}

		exit;
	}

	/**
	 * Handle a revoke (disconnect) form submission.
	 *
	 * Validates capabilities and nonce, revokes the Application Password
	 * identified by the posted UUID, then redirects back to the page with a
	 * success query parameter.
	 *
	 * @since 1.9.0
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_REVOKE );

		$uuid     = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$agent_id = (int) get_option( 'gk_block_api_agent_user_id', 0 );

		if ( $agent_id > 0 && '' !== $uuid ) {
			( new Connections() )->revoke( $agent_id, $uuid );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'revoked' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Connect page.
	 *
	 * Branches on connection_state(): shows an HTTPS requirement notice, a
	 * connect form with client picker and post-download next-steps, or an
	 * active-connections list with per-connection revoke buttons.
	 *
	 * @since 1.9.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		$state = $this->connection_state();

		// One-time paste-mode password surfaced from a prior connect download.
		$paste_pw      = '';
		$transient_key = self::PASTE_TRANSIENT_PREFIX . get_current_user_id();
		$stored_pw     = get_transient( $transient_key );
		if ( is_string( $stored_pw ) && '' !== $stored_pw ) {
			$paste_pw = $stored_pw;
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Connect an AI Assistant to Your Site', 'gk-block-api' ); ?></h1>

			<?php if ( $revoked ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection disconnected successfully.', 'gk-block-api' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $paste_pw ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Your application password (shown once):', 'gk-block-api' ); ?></strong><br />
						<code style="font-size:1.1em; user-select:all;"><?php echo esc_html( $paste_pw ); ?></code>
					</p>
					<p><?php esc_html_e( 'Copy this password and paste it into the Application Password field when you open the downloaded file. It will not be shown again.', 'gk-block-api' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'This lets an AI app like Claude write and edit the pages and posts on your site for you. Setup takes about a minute — no passwords to copy, no technical files to edit.', 'gk-block-api' ); ?>
			</p>
			<p>
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
					<p><strong><?php esc_html_e( '✅ You\'re connected', 'gk-block-api' ); ?></strong></p>
				<?php endif; ?>

				<?php $this->render_connect_form(); ?>
				<?php $this->render_next_steps(); ?>

				<?php if ( 'connected' === $state && ! empty( $connections ) ) : ?>
					<hr />
					<h2><?php esc_html_e( 'Active connections', 'gk-block-api' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Each entry below is one connected AI client. Clicking Disconnect immediately revokes that client\'s access.', 'gk-block-api' ); ?>
					</p>
					<table class="widefat striped" style="max-width: 800px;">
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
											<?php submit_button( __( 'Disconnect', 'gk-block-api' ), 'small delete', 'submit', false ); ?>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Render the client-picker form that triggers a bundle download.
	 *
	 * Extracted so render_page() stays readable. The 'other' client option
	 * still proceeds with a Claude Desktop bundle for v1 — a browser-based
	 * path is planned but not yet available.
	 *
	 * @since 1.9.0
	 */
	private function render_connect_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
			<?php wp_nonce_field( self::ACTION_CONNECT ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="gk-block-api-client"><?php esc_html_e( 'Which app do you use to chat with AI?', 'gk-block-api' ); ?></label>
					</th>
					<td>
						<select id="gk-block-api-client" name="client">
							<option value="Claude Desktop"><?php esc_html_e( 'Claude Desktop app', 'gk-block-api' ); ?></option>
							<option value="other"><?php esc_html_e( "Something else / I'm not sure", 'gk-block-api' ); ?></option>
						</select>
						<p class="description" id="gk-block-api-other-note" style="display:none; color:#646970;">
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
					</td>
				</tr>
			</table>

			<script>
			(function () {
				var sel  = document.getElementById('gk-block-api-client');
				var note = document.getElementById('gk-block-api-other-note');
				if (!sel || !note) return;
				sel.addEventListener('change', function () {
					note.style.display = (sel.value === 'other') ? '' : 'none';
				});
			})();
			</script>

			<?php submit_button( __( 'Connect Claude Desktop', 'gk-block-api' ), 'primary', 'submit', true ); ?>
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
		<div style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:16px 20px; max-width:700px; margin-top:24px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'After you download', 'gk-block-api' ); ?></h3>
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
			<p style="margin-bottom:0; color:#646970;">
				<?php esc_html_e( 'That file briefly holds a private key; once you\'ve clicked Enable you can delete it from Downloads — your AI app has stored the key securely.', 'gk-block-api' ); ?>
			</p>
		</div>
		<?php
	}
}
