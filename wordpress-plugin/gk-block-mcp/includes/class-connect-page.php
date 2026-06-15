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
 * @package GravityKit\BlockMCP
 * @since   2.0.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin "Connect an AI Assistant" wizard for the Block MCP plugin.
 *
 * @since 2.0.0
 */
class Connect_Page {

	/**
	 * Stable slug for the Claude Desktop app client.
	 *
	 * Used as the radio `value`, redirect parameter, command flag, and array key
	 * everywhere the client identity is needed. Human labels are sourced only from
	 * clients() and must never appear in branching logic.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CLIENT_CLAUDE_DESKTOP = 'claude-desktop';

	/**
	 * Stable slug for the Claude Code terminal agent client.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CLIENT_CLAUDE_CODE = 'claude-code';

	/**
	 * Stable slug for the Cursor AI code editor client.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CLIENT_CURSOR = 'cursor';

	/**
	 * Stable slug for the "let my AI set it up" path.
	 *
	 * Selecting this option presents a natural-language prompt the user pastes
	 * into any AI assistant to trigger the npx connect flow.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CLIENT_AI_PROMPT = 'ai-prompt';

	/**
	 * Stable slug for the "something else / not sure" option.
	 *
	 * Redirects with ?other=1 so a coming-soon note is shown instead of
	 * attempting provisioning.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const CLIENT_OTHER = 'other';

	/**
	 * Form action for the connect (download bundle / generate config) handler.
	 *
	 * @since 2.0.0
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
	 * @since 2.0.0
	 * @var string
	 */
	const ACTION_AUTHORIZE = 'gk_block_api_authorize';

	/**
	 * Form action for the revoke (disconnect) handler.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const ACTION_REVOKE = 'gk_block_api_revoke';

	/**
	 * Slug used when registering the admin submenu page.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const PAGE_SLUG = 'gk-block-mcp-connect';

	/**
	 * Transient key prefix for one-time paste-mode passwords and setup artifacts.
	 *
	 * The full key is this prefix + the current user ID. The transient expires
	 * in 5 minutes — long enough for the redirect + page reload, short enough
	 * to minimise the window a password sits in the options table.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const PASTE_OPTION_PREFIX = 'gk_block_api_paste_pw_';

	/**
	 * Form action for the connector credential-exchange handler.
	 *
	 * After Approve, the browser redirect carries only a single-use code; the
	 * connector POSTs that code here to retrieve the credential set once. Wired
	 * on both the logged-in and nopriv admin-post hooks because the connector is
	 * an unauthenticated local process and the code itself is the bearer secret.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const ACTION_EXCHANGE = 'gk_block_api_exchange';

	/**
	 * Option-table key prefix for single-use credential exchange records.
	 *
	 * The full key is this prefix + a SHA-256 hash of the code, so the raw code
	 * lives only in the redirect/connector. The record is a NON-autoloaded option
	 * (never a transient) so the browser->connector handoff survives every hosting
	 * topology: with a persistent object cache, transients live in the cache —
	 * where a per-server (non-shared) cache makes the connector's request miss the
	 * value the browser wrote, and an LRU cache can evict it before the TTL.
	 * wp_options is always the shared database. The password inside is sealed at
	 * rest (see seal_secret()), so a database read cannot recover the live secret.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const EXCHANGE_OPTION_PREFIX = 'gk_block_api_xchg_';

	/**
	 * Marker prefixing a sealed secret in storage.
	 *
	 * Lets unseal_secret() distinguish AES-256-GCM-sealed values from legacy or
	 * plaintext ones (e.g. written on a host without the openssl extension).
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const SEAL_PREFIX = 'gkseal:v1:';

	/**
	 * AES-GCM IV length in bytes (96-bit — the GCM standard).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const SEAL_IV_LEN = 12;

	/**
	 * AES-GCM authentication tag length in bytes (128-bit).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const SEAL_TAG_LEN = 16;

	/**
	 * HKDF "info" label that domain-separates the seal key.
	 *
	 * Ensures the derived AES key can never collide with any other consumer of
	 * wp_salt( 'auth' ) on the site.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const SEAL_INFO = 'gk-block-mcp/credential-seal/v1';

	/**
	 * Lifetime, in seconds, of a single-use credential exchange code.
	 *
	 * Long enough for the browser redirect + the connector's loopback round-trip
	 * to the exchange endpoint, short enough to bound the window the credential
	 * sits in the options table.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const EXCHANGE_TTL = 120;

	/**
	 * Whether the agent account already existed when the connect screen began
	 * rendering — captured before pre_provision_agent() creates it.
	 *
	 * The onboarding copy and the installer button label read this so a genuine
	 * first run still says "a new account will be created", even though the
	 * render then creates it. Per-request: a fresh Connect_Page renders each load.
	 *
	 * @since 2.0.1
	 * @var bool
	 */
	private $agent_preexisted = false;

	/**
	 * Return the slug-keyed client metadata map.
	 *
	 * Each key is the stable, URL-safe slug used everywhere internally (form
	 * values, query-string parameters, command flags). Labels and descriptions
	 * are translatable and used only for display.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	private function clients(): array {
		return array(
			self::CLIENT_CLAUDE_DESKTOP => array(
				'label'       => __( 'Claude Desktop app', 'gk-block-mcp' ),
				'description' => __( 'One-click install. Recommended.', 'gk-block-mcp' ),
			),
			self::CLIENT_CLAUDE_CODE    => array(
				'label'       => __( 'Claude Code', 'gk-block-mcp' ),
				'description' => __( "Anthropic's terminal coding agent.", 'gk-block-mcp' ),
			),
			self::CLIENT_CURSOR         => array(
				'label'       => __( 'Cursor', 'gk-block-mcp' ),
				'description' => __( 'AI code editor.', 'gk-block-mcp' ),
			),
			self::CLIENT_AI_PROMPT      => array(
				'label'       => __( 'Let my AI set it up for me', 'gk-block-mcp' ),
				'description' => __( 'Copy a prompt and let your AI assistant configure it.', 'gk-block-mcp' ),
			),
			self::CLIENT_OTHER          => array(
				'label'       => __( 'Configure it myself', 'gk-block-mcp' ),
				'description' => __( 'Any other MCP client — copy a config.', 'gk-block-mcp' ),
			),
		);
	}

	/**
	 * Return the human-readable label for a client slug.
	 *
	 * Falls back to the slug itself when the slug is not found in clients(),
	 * so callers always receive a printable string.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $slug One of the slugs returned by clients().
	 * @return string Translatable display label.
	 */
	public function client_label( string $slug ): string {
		$clients = $this->clients();
		return isset( $clients[ $slug ] ) ? $clients[ $slug ]['label'] : $slug;
	}

	/**
	 * Return the setup-guide URL shown as the "Need help?" link on the flow.
	 *
	 * @since 2.0.0
	 *
	 * @return string Absolute URL to the Connect setup guide.
	 */
	private function help_url(): string {
		return 'https://www.gravitykit.com/docs/block-mcp/connect-ai-assistant/';
	}

	/**
	 * Echo the persistent "Need help? View the setup guide" link.
	 *
	 * Shown on every state of the Connect flow and on the Approve screen so a
	 * stuck beginner always has a documentation path, not just an email.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $top When true, float the link to the top-right (used directly
	 *                  under the connect heading); otherwise it sits inline.
	 * @return void
	 */
	private function render_help_link( bool $top = false ): void {
		$classes = 'gk-block-mcp-connect__help description' . ( $top ? ' gk-block-mcp-connect__help--top' : '' );
		?>
		<p class="<?php echo esc_attr( $classes ); ?>">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: setup-guide URL */
					__( 'Need help? <a href="%s" target="_blank" rel="noopener noreferrer">View the setup guide</a>.', 'gk-block-mcp' ),
					esc_url( $this->help_url() )
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
		</p>
		<?php
	}

	/**
	 * Mint a fresh Application Password for a new connection.
	 *
	 * This is the shared credential-provisioning seam used by both
	 * prepare_installer() (for the .mcpb path) and handle_connect() (for the
	 * artifact path). For the 'agent' identity it ensures the dedicated agent
	 * account and mints on it; for 'self' it mints on the approving user instead.
	 * Records the connection meta (host, approver) and returns the raw credential
	 * set so each caller can consume it.
	 *
	 * @since  2.0.0
	 *
	 * @param  string $client   Human-readable display name for the connecting client
	 *                          (e.g. the return value of client_label()). Used only as
	 *                          the Application Password label — never matched or branched on.
	 * @param  string $identity Which account holds the credential: 'agent' (the
	 *                          dedicated limited account) or 'self' (the approving
	 *                          user's own account, full capabilities). Anything else
	 *                          — and 'self' when disabled via the
	 *                          gk/block-mcp/identity/allow-self filter — falls back
	 *                          to 'agent'.
	 * @return array|\WP_Error {
	 *     On success, a credential array ready for callers to use.
	 *
	 *     @type string $url      Untrailed home_url() base.
	 *     @type string $user     Login of the account the credential was minted on.
	 *     @type string $password One-time plaintext Application Password.
	 *     @type string $uuid     UUID of the minted Application Password.
	 * }
	 */
	public function provision_credentials( $client, $identity = 'agent' ) {
		$identity = in_array( $identity, array( 'agent', 'self' ), true ) ? $identity : 'agent';

		/**
		 * Forbid full-account connections so the AI is always a limited agent.
		 *
		 * The Approve screen offers two identities: the recommended dedicated
		 * agent, and "your own account" — which mints a credential carrying the
		 * approving user's full capabilities. Return false to take that second
		 * option off the table entirely: the card disappears from the consent
		 * screen and any `self` request is clamped back to the limited agent.
		 * The right move for managed hosts, agencies, or any site where an AI
		 * client should never hold admin-grade access.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Only ever allow the dedicated, least-privilege agent identity.
		 * add_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );
		 *
		 * @param bool $allowed Whether the "your own account" identity is offered. Default true.
		 */
		if ( 'self' === $identity && ! apply_filters( 'gk/block-mcp/identity/allow-self', true ) ) {
			$identity = 'agent';
		}

		$human = get_current_user_id();

		if ( 'self' === $identity ) {
			// Own-account: mint the credential on the approving user, so the AI app
			// acts with that person's full capabilities. Higher blast radius — the
			// Approve screen warns about it. There is no separate byline here:
			// content is authored by them because it is literally their account
			// doing the work. The agent is not provisioned for this path.
			$target_user = $human;
		} else {
			// Resolve the EXISTING agent — never create it here. Creating a
			// user and minting its Application Password in the same request
			// matches a backdoor-provisioning signature that runtime firewalls
			// (e.g. Monarx) block, which silently breaks Connect. The agent is
			// pre-created on the connect-screen render (pre_provision_agent());
			// this request only mints against it.
			$agent = ( new Agent_Provisioner() )->get_existing();
			if ( is_wp_error( $agent ) ) {
				return $agent;
			}
			if ( null === $agent ) {
				return new \WP_Error(
					'gk_block_api_agent_not_ready',
					__( 'The Block MCP account is still being set up. Reload this page and try connecting again.', 'gk-block-mcp' )
				);
			}
			$target_user = $agent;
		}

		$issued = ( new App_Password_Issuer() )->issue( $target_user, $this->connection_label( $client ) );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		// Record which account holds the credential and who approved it.
		// get_current_user_id() is the approving human — provision runs inside
		// their authenticated admin request.
		Connections::record_meta(
			$issued['uuid'],
			array(
				'user_id'    => $target_user,
				'created_by' => $human,
				'created_at' => time(),
			)
		);

		$target = get_user_by( 'id', $target_user );

		return array(
			'url'      => untrailingslashit( home_url() ),
			'user'     => $target ? $target->user_login : Agent_Provisioner::LOGIN,
			'password' => $issued['password'],
			'uuid'     => $issued['uuid'],
		);
	}

	/**
	 * Build the Application Password label for a connection.
	 *
	 * On multisite the agent user — and therefore its Application Passwords — is
	 * network-global, so every blog's connection list shows the same credentials.
	 * Appending the originating site's address (host AND path, which distinguishes
	 * both subdomain and subdirectory sub-sites) lets a network admin tell which
	 * sub-site created each connection. List and revoke stay network-wide because
	 * the credential itself is: hiding a connection per-blog would mask access it
	 * actually grants. The label keeps the Connections::NAME_PREFIX so a connection
	 * is still recognised and revocable.
	 *
	 * @since  2.0.0
	 *
	 * @param  string $client Client slug — resolved to its display label via
	 *                        client_label() so the connections list reads
	 *                        "Claude Code", not "claude-code". An already-resolved
	 *                        label passes through client_label() unchanged.
	 * @return string Application Password label.
	 */
	private function connection_label( $client ) {
		$label = 'Block MCP — ' . $this->client_label( $client );

		if ( is_multisite() ) {
			$site     = (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
			$has_site = '' !== $site;
			$suffix   = $has_site ? $site : 'site #' . get_current_blog_id();
			$label   .= ' (' . $suffix . ')';
		}

		return $label;
	}

	/**
	 * Provision the agent, mint a credential, and build a .mcpb bundle.
	 *
	 * Calls provision_credentials() then builds the .mcpb from the returned
	 * creds, keeping the .mcpb path unchanged for the Claude Desktop flow.
	 *
	 * @since  2.0.0
	 *
	 * @param  string      $client      Human-readable display label for the connecting client
	 *                                  (e.g. the return value of client_label('claude-desktop')).
	 *                                  Used as the Application Password name and the .mcpb display_name.
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
			$server_path = GK_BLOCK_MCP_PLUGIN_DIR . 'assets/mcp-server/index.cjs';
		}

		$creds = $this->provision_credentials( $client );
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		// Determine secret-at-rest mode. 'prefill' embeds the password in the
		// bundle so Claude Desktop pre-fills it on import. 'paste' leaves the
		// bundle's password field blank and returns the plaintext to the UI,
		// trading convenience for keeping the secret out of the download.
		$default_mode = ( defined( 'GK_BLOCK_MCP_FORCE_PASTE_SECRET' ) && GK_BLOCK_MCP_FORCE_PASTE_SECRET ) ? 'paste' : 'prefill';

		/**
		 * Decide whether the downloadable .mcpb bundle carries the password.
		 *
		 * By default ('prefill') the generated Claude Desktop bundle embeds the
		 * freshly minted Application Password so installation is a single
		 * double-click. Tighten this to 'paste' on high-security setups: the
		 * downloaded file ships with an empty password field and the plaintext
		 * is shown once in the UI for the admin to copy by hand — so the secret
		 * never lives inside a file that might land in Downloads, a backup, or a
		 * shared drive.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Never write the password into the bundle file.
		 * add_filter( 'gk/block-mcp/credential/seal-mode', function () {
		 *     return 'paste';
		 * } );
		 *
		 * @param string $mode Secret-at-rest mode: 'prefill' to embed, 'paste' to omit. Default 'prefill'.
		 */
		$mode = (string) apply_filters( 'gk/block-mcp/credential/seal-mode', $default_mode );

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
	 * Build the secret-free command artifact for a given client slug.
	 *
	 * Returns a label, language hint, and a raw body containing only an
	 * `npx -y @gravitykit/block-mcp connect` command. No password or credential
	 * of any kind appears in the body — the credential is delivered later via the
	 * browser-Approve handshake driven by the connector CLI.
	 *
	 * The body is RAW (not HTML-escaped). Callers that write it to HTML must
	 * escape it at output time — render_artifact_card() uses esc_textarea().
	 *
	 * @since  2.0.0
	 * @since  2.0.0 Parameter renamed from label string to stable slug.
	 *
	 * @param  string $slug     One of: 'claude-code', 'cursor', 'ai-prompt'.
	 * @param  string $site_url Untrailed home_url() base to embed in the command.
	 * @return array {
	 *     @type string $label    Short description shown above the textarea. Raw text — escape at output.
	 *     @type string $language Syntax hint ('bash', 'text').
	 *     @type string $body     Raw command string. Must be escaped by the caller before HTML output.
	 * }
	 */
	public function setup_artifact( $slug, $site_url ) {
		$terminal_label = __( 'Run this in your terminal:', 'gk-block-mcp' );

		switch ( $slug ) {
			case self::CLIENT_CLAUDE_CODE:
				return array(
					'label'    => $terminal_label,
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client " . self::CLIENT_CLAUDE_CODE,
				);

			case self::CLIENT_CURSOR:
				return array(
					'label'    => $terminal_label,
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client " . self::CLIENT_CURSOR,
				);

			case self::CLIENT_AI_PROMPT:
				return array(
					'label'    => __( 'Paste this to your AI assistant:', 'gk-block-mcp' ),
					'language' => 'text',
					'body'     =>
						"Run `npx -y @gravitykit/block-mcp connect --site {$site_url}` for me, " .
						'then approve the connection in the browser window that opens, ' .
						'and confirm you can read the blocks on one of my pages.',
				);

			case self::CLIENT_OTHER:
			default:
				return array(
					'label'    => $terminal_label,
					'language' => 'bash',
					'body'     => "npx -y @gravitykit/block-mcp connect --site {$site_url} --client print",
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
	 * @since  2.0.0
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
	 * All connections, regardless of which account holds the credential.
	 *
	 * Merges the agent's connections with the own-account connections recorded in
	 * the meta store. Own-account connections exist even when no agent has been
	 * provisioned, so this is the single source of truth for "is anything
	 * connected" and for rendering the Active connections list.
	 *
	 * @since  2.0.0
	 *
	 * @param  int $agent_id The agent user ID (0 when none provisioned).
	 * @return array[] Rows in Connections::list() shape.
	 */
	private function all_connections( $agent_id ) {
		$agent_id = (int) $agent_id;
		$conns    = new Connections();
		$rows     = $agent_id > 0 ? $conns->list( $agent_id ) : array();

		return array_merge( $rows, $conns->list_self_hosted( $agent_id ) );
	}

	/**
	 * Whether the dedicated "Block MCP" agent account already exists.
	 *
	 * True once a connection has provisioned it — the account persists even after
	 * every connection is revoked. Onboarding copy keys off this to switch from
	 * "connecting creates an account" to present tense once the account is there.
	 *
	 * @since  2.0.0
	 *
	 * @return bool
	 */
	private function agent_exists() {
		$agent_id = (int) get_option( 'gk_block_api_agent_user_id', 0 );

		return $agent_id > 0 && false !== get_user_by( 'id', $agent_id );
	}

	/**
	 * Determine the current connection state for render_page() branching.
	 *
	 * @since  2.0.0
	 *
	 * @return string 'needs_https' | 'connected' | 'ready'
	 */
	public function connection_state() {
		if ( ! wp_is_application_passwords_available() ) {
			return 'needs_https';
		}

		$agent_id    = (int) get_option( 'gk_block_api_agent_user_id', 0 );
		$connections = $this->all_connections( $agent_id );
		if ( ! empty( $connections ) ) {
			return 'connected';
		}

		return 'ready';
	}

	/**
	 * Register admin_post handlers for connect, authorize, and revoke actions.
	 *
	 * The menu page is hosted by Settings_Page; only the form-action handlers
	 * need to be wired here.
	 *
	 * @since 2.0.0
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_AUTHORIZE, array( $this, 'handle_authorize' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( $this, 'handle_revoke' ) );

		// The connector exchanges its single-use code for the credential. It is an
		// unauthenticated local process, so the handler is wired on the nopriv hook
		// too; the code itself is the bearer secret (single-use, short-TTL).
		add_action( 'admin_post_' . self::ACTION_EXCHANGE, array( $this, 'handle_exchange' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_EXCHANGE, array( $this, 'handle_exchange' ) );
	}

	/**
	 * Handle the connect form submission.
	 *
	 * For the claude-desktop slug: provisions credentials, builds the .mcpb
	 * bundle, and streams it as an octet-stream download.
	 *
	 * For claude-code, cursor, and ai-prompt: does NOT provision
	 * any credential. Redirects back to the connect tab with ?setup=<slug> so
	 * render_section() can display the secret-free CLI command for that client.
	 * The credential is delivered later via the browser-Approve handshake when the
	 * user runs the printed npx command and clicks Approve.
	 *
	 * For 'other': redirects back with ?other=1 so the "coming soon" note is shown.
	 *
	 * @since 2.0.0
	 * @since 2.0.0 Uses stable slugs from clients(); dropped rawurlencode()
	 *               double-encode (add_query_arg already encodes values).
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_CONNECT );

		// Slugs are URL-safe ASCII — sanitize_key is the right sanitizer.
		$slug = isset( $_POST['client'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via check_admin_referer.
			? sanitize_key( wp_unslash( $_POST['client'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: self::CLIENT_CLAUDE_DESKTOP;

		if ( '' === $slug ) {
			$slug = self::CLIENT_CLAUDE_DESKTOP;
		}

		// Command-artifact clients: no provisioning — redirect back with the slug
		// so render_section() can display the secret-free npx command.
		// add_query_arg() encodes query values; no rawurlencode() wrapper needed.
		$artifact_clients = array( self::CLIENT_CLAUDE_CODE, self::CLIENT_CURSOR, self::CLIENT_AI_PROMPT, self::CLIENT_OTHER );
		if ( in_array( $slug, $artifact_clients, true ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => Settings_Page::PAGE_SLUG,
						'tab'   => 'connect',
						'setup' => $slug,
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// Default: CLIENT_CLAUDE_DESKTOP — stream the .mcpb bundle.
		// Pass the human label so the Application Password name and .mcpb
		// display_name read as "Block MCP — Claude Desktop app".
		$r = $this->prepare_installer( $this->client_label( $slug ) );

		if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ) );
		}

		// Stash the sealed password for paste-mode so render_page() can show it
		// once on the redirect back without re-minting. Stored as a non-autoloaded
		// wp_options record (see put_record()) — same object-cache-safe path as the
		// exchange handoff — not a transient.
		if ( 'paste' === $r['mode'] && '' !== $r['password'] ) {
			$this->put_record(
				self::PASTE_OPTION_PREFIX . get_current_user_id(),
				array( 'password' => $this->seal_secret( $r['password'] ) ),
				5 * MINUTE_IN_SECONDS
			);
		}

		$path = $r['path'];

		// The .mcpb embeds the plaintext credential in prefill mode. A browser
		// abort mid-readfile() can terminate the script before the streaming
		// finally runs, so also unlink the bundle on shutdown —
		// register_shutdown_function fires on user-abort termination. A double
		// unlink (finally + shutdown) is a harmless no-op.
		register_shutdown_function( array( __CLASS__, 'unlink_temp_bundle' ), $path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $r['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		try {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
				error_log( 'gk-block-mcp: installer stream failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			// Only surface an error page if nothing has been streamed yet —
			// once octet-stream + Content-Length headers are out, the response
			// body is committed and WordPress's HTML error handler would
			// corrupt the partial download.
			if ( ! headers_sent() ) {
				wp_die( esc_html__( 'An error occurred while preparing your download.', 'gk-block-mcp' ) );
			}
		} finally {
			self::unlink_temp_bundle( $path );
		}

		exit;
	}

	/**
	 * Delete a generated .mcpb bundle temp file if it still exists.
	 *
	 * The prefill-mode bundle embeds the plaintext Application Password, so it
	 * must not linger on disk. This is the cleanup used both by the streaming
	 * finally and by the shutdown function registered before streaming (which
	 * covers the client-abort case where the finally does not run). Deleting an
	 * already-removed path is a harmless no-op, so the two callers can both
	 * fire for the same bundle without error.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Absolute path to the temp bundle.
	 *
	 * @return void
	 */
	protected static function unlink_temp_bundle( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
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
	 * @since 2.0.0
	 */
	public function handle_authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_AUTHORIZE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above via check_admin_referer.
		$callback = isset( $_POST['callback'] ) ? sanitize_text_field( wp_unslash( $_POST['callback'] ) ) : '';
		$state    = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$client   = isset( $_POST['client'] ) ? sanitize_text_field( wp_unslash( $_POST['client'] ) ) : 'block-mcp';
		$identity = isset( $_POST['identity'] ) ? sanitize_key( wp_unslash( $_POST['identity'] ) ) : 'agent';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Callback must resolve to a loopback address — credential must never leave
		// the local machine via an attacker-controlled redirect target.
		if ( ! $this->is_loopback_callback( $callback ) ) {
			wp_die(
				esc_html__( 'Invalid callback URL. Only loopback addresses (127.0.0.1, localhost) are accepted.', 'gk-block-mcp' ),
				esc_html__( 'Authorization failed', 'gk-block-mcp' ),
				array( 'response' => 400 )
			);
		}

		$creds = $this->provision_credentials( $client, $identity );
		if ( is_wp_error( $creds ) ) {
			wp_die( esc_html( $creds->get_error_message() ) );
		}

		// Deliver the credential out-of-band: store it under a single-use,
		// short-TTL code and hand only the code (never the password) to the
		// loopback callback. The connector then POSTs the code to handle_exchange()
		// to retrieve the credential once, keeping the site-wide password out of
		// the redirect URL (browser history / Referer).
		$code = $this->store_exchange_code( $creds );

		// Default path: return the code as JSON; render_authorize_screen()'s
		// fetch handler navigates to the loopback callback client-side. A
		// server-issued redirect to a loopback/private IP looks like SSRF to
		// origin RASP/WAF layers, which block the response and break the
		// handshake. JSON keeps every loopback address out of the response.
		if ( $this->is_xhr_authorize() ) {
			wp_send_json_success(
				array(
					'code'  => $code,
					'state' => $state,
				)
			);
		}

		// Fallback for a native (non-fetch) submit. add_query_arg() does NOT
		// encode the values it adds (it only re-encodes params already in the
		// URL), so rawurlencode() is required — a code/state with a
		// query-significant char (&, #, +) would otherwise corrupt the target.
		$redirect = add_query_arg(
			array(
				'code'  => rawurlencode( $code ),
				'state' => rawurlencode( $state ),
			),
			$callback
		);

		// Hand off via a 200 HTML page that redirects in the browser, NOT a
		// server redirect. A Location: header to a loopback/private IP reads as
		// SSRF/open-redirect to origin RASP/WAF layers and gets blocked; a 200
		// page carrying the loopback only in its body does not (the block is on
		// the redirect header, not page content). The page redirects via script
		// with a visible manual link as the no-script floor.
		$this->render_loopback_handoff( $redirect, $client );
		$this->halt();
	}

	/**
	 * Terminate the request after an echoed/streamed response.
	 *
	 * Wraps exit so tests can intercept it — a bare exit would end the test
	 * process. Production behaviour is a plain exit.
	 *
	 * @since 2.0.1
	 *
	 * @return void
	 */
	protected function halt() {
		exit;
	}

	/**
	 * Output a 200 HTML page that sends the browser to the loopback callback.
	 *
	 * Used by the native-submit fallback in place of wp_redirect(): a
	 * server-issued redirect to a loopback host is blocked by origin WAF/RASP
	 * layers, so the navigation must happen client-side. The exchange code rides
	 * in the URL (single-use, short-TTL); the password is never here.
	 *
	 * @since 2.0.1
	 *
	 * @param string $redirect Loopback callback URL with code + state.
	 * @param string $client   Client slug, for the human-readable label.
	 * @return void
	 */
	private function render_loopback_handoff( $redirect, $client ) {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$label = $this->client_label( $client );

		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Finishing connection…', 'gk-block-mcp' ); ?></title>
	<script>window.location.replace( <?php echo wp_json_encode( $redirect ); ?> );</script>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; color: #1e1e1e; margin: 0; }
		.gk-handoff { max-width: 480px; margin: 15vh auto 0; padding: 24px 28px; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; text-align: center; }
		.gk-handoff a { color: #2271b1; }
	</style>
</head>
<body>
	<div class="gk-handoff">
		<p>
			<?php
			printf(
				/* translators: %s: the AI app name (client). */
				esc_html__( 'Finishing the connection to %s…', 'gk-block-mcp' ),
				esc_html( $label )
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( $redirect ); ?>"><?php esc_html_e( 'Click here if you are not redirected automatically.', 'gk-block-mcp' ); ?></a>
		</p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Whether the authorize POST wants the exchange code back as JSON.
	 *
	 * True for the in-page fetch() handler (which redirects to the loopback
	 * callback client-side); false for a classic full-page form submit (which
	 * needs the no-JS server redirect). The fetch handler marks its request with
	 * the gk_xhr field; the X-Requested-With header is a secondary signal.
	 *
	 * The caller verifies the nonce before reaching this.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function is_xhr_authorize() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the caller via check_admin_referer().
		if ( isset( $_POST['gk_xhr'] ) && '1' === $_POST['gk_xhr'] ) {
			return true;
		}

		$requested_with = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) )
			: '';

		return 'xmlhttprequest' === $requested_with;
	}

	/**
	 * Store a minted credential set under a single-use exchange code.
	 *
	 * Returns the raw code to embed in the loopback redirect. The record KEY is a
	 * SHA-256 of the code, so a database read cannot reconstruct the code (the
	 * bearer token) and the credential never travels in the redirect URL, browser
	 * history, or Referer. The password is sealed at rest, the record is a
	 * non-autoloaded wp_options row (see put_record()) so the handoff survives
	 * object-cache / multi-server topologies, EXCHANGE_TTL bounds the window, and
	 * redeem_exchange_code() consumes it single-use.
	 *
	 * @since 2.0.0
	 *
	 * @param  array $creds Minted credential set with url/user/password keys.
	 * @return string The raw single-use exchange code.
	 */
	protected function store_exchange_code( array $creds ) {
		$code = bin2hex( random_bytes( 32 ) );

		$this->put_record(
			self::EXCHANGE_OPTION_PREFIX . hash( 'sha256', $code ),
			array(
				'site'     => isset( $creds['url'] ) ? $creds['url'] : '',
				'user'     => isset( $creds['user'] ) ? $creds['user'] : '',
				'password' => $this->seal_secret( isset( $creds['password'] ) ? $creds['password'] : '' ),
			),
			self::EXCHANGE_TTL
		);

		return $code;
	}

	/**
	 * Redeem a single-use exchange code, returning the stored credential once.
	 *
	 * Consumes the record stored by store_exchange_code() (single-use via
	 * take_record()) and returns the creds with the password unsealed — or null
	 * when the code is empty, unknown, expired, already-consumed, or the sealed
	 * password fails to authenticate.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $code Raw exchange code presented by the connector.
	 * @return array|null Credential set with site/user/password keys, or null.
	 */
	protected function redeem_exchange_code( $code ) {
		if ( ! is_string( $code ) || '' === $code ) {
			return null;
		}

		$stored = $this->take_record( self::EXCHANGE_OPTION_PREFIX . hash( 'sha256', $code ) );
		if ( null === $stored ) {
			return null;
		}

		$password = $this->unseal_secret( isset( $stored['password'] ) ? $stored['password'] : '' );
		if ( null === $password ) {
			// Sealed credential failed to authenticate (tampering or a rotated
			// salt). Treat as unusable rather than returning a corrupt secret.
			return null;
		}

		return array(
			'site'     => isset( $stored['site'] ) ? $stored['site'] : '',
			'user'     => isset( $stored['user'] ) ? $stored['user'] : '',
			'password' => $password,
		);
	}

	/**
	 * Persist a short-lived record in wp_options (deliberately NOT a transient).
	 *
	 * Transients are unreliable for this cross-request, cross-server credential
	 * handoff: with a persistent object cache they live in the cache, where a
	 * per-server (non-shared) cache makes the connector's request miss the value
	 * the browser wrote, and an LRU cache can evict it before the TTL. wp_options
	 * is always the shared database (the object cache is only a read-through with a
	 * DB fallback), so the handoff is correct on every topology. The row is written
	 * autoload='no' so these blobs never enter the autoloaded options cache; an
	 * 'expires_at' timestamp replaces the transient auto-expiry and is enforced by
	 * take_record() / gc_records().
	 *
	 * @since 2.0.0
	 *
	 * @param  string $key   Option name.
	 * @param  array  $value Record to store; an 'expires_at' key is added.
	 * @param  int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	private function put_record( $key, array $value, $ttl ) {
		$value['expires_at'] = time() + (int) $ttl;
		delete_option( $key );                 // Keys are single-use/unique; ensure a clean add.
		add_option( $key, $value, '', false ); // autoload = false (non-autoloaded).
	}

	/**
	 * Read and consume a short-lived record written by put_record() (single-use).
	 *
	 * Single-use is atomic on the delete: only the caller whose delete_option()
	 * actually removed the row "wins", so a concurrent replay loses and gets null.
	 * Expired records return null. Returns the record with 'expires_at' stripped,
	 * or null when missing / expired / already-consumed.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $key Option name.
	 * @return array|null
	 */
	private function take_record( $key ) {
		$value = get_option( $key, null );
		if ( ! is_array( $value ) ) {
			return null;
		}

		// Consume: the winner of the delete is the only caller allowed to proceed.
		if ( ! delete_option( $key ) ) {
			return null;
		}

		$expires = isset( $value['expires_at'] ) ? (int) $value['expires_at'] : 0;
		if ( time() > $expires ) {
			return null;
		}

		unset( $value['expires_at'] );
		return $value;
	}

	/**
	 * Opportunistically purge expired exchange/paste records.
	 *
	 * Removes rows whose embedded expires_at has passed. Throttled to roughly once
	 * an hour via a marker option so it does not scan on every connect-page load.
	 * These records are low-volume (one per connect attempt, single-use-deleted on
	 * success) and admin-only, so this opportunistic sweep stands in for a cron
	 * event. The marker key is deliberately outside the swept prefixes.
	 *
	 * @since 2.0.0
	 *
	 * @param  bool $force Skip the once-an-hour throttle (tests / uninstall).
	 * @return void
	 */
	public function gc_records( $force = false ) {
		$marker = 'gk_block_api_cred_gc_at';
		$now    = time();

		if ( ! $force ) {
			$last = (int) get_option( $marker, 0 );
			if ( $now - $last < HOUR_IN_SECONDS ) {
				return;
			}
		}
		update_option( $marker, $now, false );

		global $wpdb;
		foreach ( array( self::EXCHANGE_OPTION_PREFIX, self::PASTE_OPTION_PREFIX ) as $prefix ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( (array) $names as $name ) {
				$record  = get_option( $name );
				$expires = is_array( $record ) && isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;
				if ( ! is_array( $record ) || $now > $expires ) {
					delete_option( $name );
				}
			}
		}
	}

	/**
	 * Whether AES-256-GCM sealing is actually available on this host.
	 *
	 * Verifies the openssl functions AND that the cipher itself is present, rather
	 * than assuming function_exists() implies GCM support. Memoised per request.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	private function can_seal() {
		static $ok = null;
		if ( null === $ok ) {
			$ok = function_exists( 'openssl_encrypt' )
				&& function_exists( 'openssl_decrypt' )
				&& function_exists( 'openssl_get_cipher_methods' )
				&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
		}
		return $ok;
	}

	/**
	 * Derive the 32-byte AES-256 key for credential sealing.
	 *
	 * Uses HKDF with a use-specific info label so the key is domain-separated from
	 * any other consumer of wp_salt( 'auth' ) on the site — two subsystems must
	 * never share an AES key (GCM fails catastrophically under (key, IV) reuse).
	 * The salt lives in wp-config.php, not the database, so a DB read still cannot
	 * derive it. Falls back to a labelled SHA-256 only on the rare build without
	 * hash_hkdf() (PHP < 7.1.2, below the plugin's 7.4 floor).
	 *
	 * @since 2.0.0
	 *
	 * @return string 32 raw key bytes.
	 */
	private function seal_key() {
		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, self::SEAL_INFO );
		}
		return hash( 'sha256', self::SEAL_INFO . '|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Seal a secret for at-rest storage.
	 *
	 * Encrypts with AES-256-GCM under an HKDF-derived key (see seal_key()). A fresh
	 * random IV is generated per call and the GCM authentication tag is stored with
	 * the ciphertext, so a database read or stolen backup cannot recover the value.
	 *
	 * When sealing is unavailable (no openssl/GCM, or encryption fails) it degrades
	 * to returning the plaintext so the connect flow never breaks — but logs the
	 * degradation, because the at-rest guarantee is then void for the short TTL.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $plaintext Secret to seal.
	 * @return string Sealed token (SEAL_PREFIX + base64), or the plaintext on the fallback path.
	 */
	protected function seal_secret( $plaintext ) {
		if ( '' === $plaintext ) {
			return $plaintext;
		}

		if ( ! $this->can_seal() ) {
			error_log( 'gk-block-mcp: AES-256-GCM unavailable (openssl); credential stored UNSEALED for its short TTL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $plaintext;
		}

		$iv  = random_bytes( self::SEAL_IV_LEN );
		$tag = '';

		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->seal_key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::SEAL_TAG_LEN );
		if ( false === $cipher ) {
			error_log( 'gk-block-mcp: openssl_encrypt failed; credential stored UNSEALED for its short TTL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $plaintext;
		}

		return self::SEAL_PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Unseal a value produced by seal_secret().
	 *
	 * On a host that CAN seal, a value WITHOUT the seal marker is anomalous — this
	 * code would never have written plaintext there — so it is rejected (null)
	 * rather than trusted, closing an inject-unsealed-plaintext tampering vector. A
	 * missing marker is accepted as raw plaintext ONLY where sealing is unavailable
	 * (the graceful-degradation path). A sealed token returns null on any
	 * decrypt/authenticate failure — tampering, truncation, or a wrong key after a
	 * salt rotation.
	 *
	 * @since 2.0.0
	 *
	 * @param  mixed $sealed Stored value to unseal.
	 * @return string|null Plaintext, or null when the value cannot be trusted/verified.
	 */
	protected function unseal_secret( $sealed ) {
		if ( ! is_string( $sealed ) ) {
			return null;
		}

		if ( 0 !== strpos( $sealed, self::SEAL_PREFIX ) ) {
			// No seal marker: reject on a seal-capable host (anomalous / injection),
			// accept as plaintext only where sealing is genuinely unavailable.
			return $this->can_seal() ? null : $sealed;
		}

		if ( ! $this->can_seal() ) {
			// A sealed value on a host that cannot decrypt it (e.g. a migrated DB).
			return null;
		}

		$raw = base64_decode( substr( $sealed, strlen( self::SEAL_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$min = self::SEAL_IV_LEN + self::SEAL_TAG_LEN;
		if ( false === $raw || strlen( $raw ) < $min ) {
			return null;
		}

		$iv     = substr( $raw, 0, self::SEAL_IV_LEN );
		$tag    = substr( $raw, self::SEAL_IV_LEN, self::SEAL_TAG_LEN );
		$cipher = substr( $raw, $min );

		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $this->seal_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return ( false === $plain ) ? null : $plain;
	}

	/**
	 * Handle the connector's credential-exchange POST.
	 *
	 * The connector presents the single-use code it received on the loopback
	 * callback; this returns the matching credential set once as JSON and deletes
	 * the code so it cannot be replayed. The code is the bearer secret (there is
	 * no WordPress session), so no nonce is required and the handler is reachable
	 * on the nopriv admin-post hook.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function handle_exchange() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the single-use exchange code IS the bearer credential; there is no WordPress session to protect with a nonce.
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$creds = $this->redeem_exchange_code( $code );

		if ( null === $creds ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or expired code.', 'gk-block-mcp' ) ), 400 );
		}

		wp_send_json_success( $creds );
	}

	/**
	 * Register the connector's credential-exchange REST route.
	 *
	 * The connector POSTs its single-use code here to retrieve the credential
	 * once. REST (`/wp-json/`) is the transport — NOT admin-post.php — because
	 * admin-post.php is routinely 30x'd before the handler runs by canonical/SSL
	 * redirects, the Redirection plugin, and security plugins on real sites, which
	 * the connector cannot follow safely for a credential POST. REST routes escape
	 * those front-end redirect rules. permission_callback is __return_true: the
	 * single-use code IS the bearer credential, so there is no session to gate.
	 *
	 * Wired on rest_api_init (not behind the admin-only settings bootstrap) so it
	 * answers the connector's logged-out request.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'gk-block-api/v1',
			'/connect/exchange',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_exchange' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * REST handler: redeem a single-use exchange code for the credential set.
	 *
	 * Returns the same `{ success: true, data: { site, user, password } }` shape as
	 * the admin-post.php handler so the connector parses both identically; a 400
	 * on an invalid/expired/replayed code.
	 *
	 * @since 2.0.0
	 *
	 * @param  \WP_REST_Request $request Request carrying the single-use `code`.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_exchange( \WP_REST_Request $request ) {
		$creds = $this->redeem_exchange_code( (string) $request->get_param( 'code' ) );

		if ( null === $creds ) {
			return new \WP_Error( 'invalid_code', __( 'Invalid or expired code.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $creds,
			),
			200
		);
	}

	/**
	 * Revoke the Application Password identified by UUID for the agent user.
	 *
	 * This is the testable core of handle_revoke(). It performs the agent-id
	 * lookup and delegates deletion to Connections::revoke(), returning the
	 * boolean result. Cap/nonce enforcement and the redirect stay in the HTTP
	 * handler so tests can call this seam directly without triggering exit.
	 *
	 * @since  2.0.0
	 *
	 * @param  string $uuid UUID of the Application Password to delete.
	 * @return bool True when the credential was deleted, false otherwise.
	 */
	public function do_revoke( $uuid ) {
		if ( '' === $uuid ) {
			return false;
		}

		// Resolve which account holds the credential from the meta store — it may
		// be the agent OR the approving user (own-account connections). Falls back
		// to the agent for older connections recorded before host tracking.
		$agent_id = (int) get_option( 'gk_block_api_agent_user_id', 0 );

		return ( new Connections() )->revoke_by_uuid( $uuid, $agent_id );
	}

	/**
	 * Handle a revoke (disconnect) form submission.
	 *
	 * Validates capabilities and nonce, delegates the credential deletion to
	 * do_revoke(), then redirects back to the page with a success query parameter.
	 *
	 * @since 2.0.0
	 */
	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-mcp' ), '', array( 'response' => 403 ) );
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
	 * Create the dedicated agent ahead of any credential mint.
	 *
	 * Runs on the connect-screen render — a request that mints no Application
	 * Password — so the later Approve / installer-download request only mints
	 * against an existing account. Splitting account creation from credential
	 * minting across two requests sidesteps the backdoor-provisioning signature
	 * runtime firewalls block. Best-effort: a failure here surfaces as a clear
	 * error at mint time (provision_credentials() returns agent_not_ready).
	 *
	 * Skipped when Application Passwords are unavailable — the connect flow
	 * can't proceed anyway, so there is nothing to pre-create.
	 *
	 * @since 2.0.1
	 *
	 * @param string $state Current connection_state(): skips when 'needs_https'.
	 * @return void
	 */
	private function pre_provision_agent( $state ) {
		if ( 'needs_https' === $state ) {
			return;
		}

		( new Agent_Provisioner() )->ensure();
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
	 * All selectors are scoped under .gk-block-mcp-connect to avoid leaking into the rest
	 * of wp-admin.
	 *
	 * @since 2.0.0
	 */
	public function render_section() {
		$state = $this->connection_state();

		// Snapshot agent existence BEFORE pre-provisioning so first-run copy
		// still reads as "an account will be created".
		$this->agent_preexisted = $this->agent_exists();

		// Create the dedicated agent now, on this render, so the later
		// credential-minting request (browser Approve or installer download)
		// only MINTS against an existing account. A single request that both
		// creates a user and mints its Application Password matches a
		// backdoor-provisioning signature that runtime firewalls (e.g. Monarx)
		// block — splitting creation and minting across two requests avoids it.
		$this->pre_provision_agent( $state );

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
		// handle_connect() redirects back with ?setup=<slug> for non-Desktop
		// clients. Render the secret-free command artifact for that slug.
		$setup_client = ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_setup        = sanitize_key( wp_unslash( $_GET['setup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$artifact_clients = array( self::CLIENT_CLAUDE_CODE, self::CLIENT_CURSOR, self::CLIENT_AI_PROMPT, self::CLIENT_OTHER );
			if ( in_array( $raw_setup, $artifact_clients, true ) ) {
				$setup_client = $raw_setup;
			}
		}

		// Opportunistically purge expired exchange/paste records (throttled,
		// admin-only) — stands in for a cron sweep on this low-volume flow.
		$this->gc_records();

		// Claude Desktop paste-mode password (shown once after a paste-mode .mcpb
		// download). take_record() consumes it single-use, so it is shown exactly
		// once even across reloads.
		$paste_pw  = '';
		$paste_rec = $this->take_record( self::PASTE_OPTION_PREFIX . get_current_user_id() );
		if ( is_array( $paste_rec ) && isset( $paste_rec['password'] ) ) {
			$unsealed = $this->unseal_secret( $paste_rec['password'] );
			if ( is_string( $unsealed ) && '' !== $unsealed ) {
				$paste_pw = $unsealed;
			}
		}

		// Read-only query-string flags from our own redirects (nonce-free: value
		// is an integer flag, no user data in the message).
		$revoked = isset( $_GET['revoked'] ) ? absint( wp_unslash( $_GET['revoked'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Active connections for the 'connected' state — across the agent and any
		// own-account hosts.
		$connections = array();
		if ( 'connected' === $state ) {
			$agent_id    = (int) get_option( 'gk_block_api_agent_user_id', 0 );
			$connections = $this->all_connections( $agent_id );
		}

		?>
		<div class="gk-block-mcp-connect">

		<?php if ( $revoked ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Connection disconnected successfully.', 'gk-block-mcp' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $paste_pw ) : ?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Your application password (shown once):', 'gk-block-mcp' ); ?></strong><br />
					<code class="gk-block-mcp-connect__paste-pw"><?php echo esc_html( $paste_pw ); ?></code>
				</p>
				<p><?php esc_html_e( 'Copy this password and paste it into the Application Password field when you open the downloaded file. It will not be shown again.', 'gk-block-mcp' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'connected' === $state && ! empty( $connections ) ) : ?>
			<?php $this->render_active_connections( $connections ); ?>
		<?php endif; ?>

		<div class="postbox gk-block-mcp-connect__card">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Connect an AI Assistant to Your Site', 'gk-block-mcp' ); ?></h2>
			</div>
			<div class="inside">

			<?php $this->render_help_link( true ); ?>

			<p class="gk-block-mcp-connect__intro">
				<?php esc_html_e( 'This lets an AI app like Claude write and edit the pages and posts on your site for you.', 'gk-block-mcp' ); ?>
			</p>
			<div class="notice notice-warning inline">
				<p>
					<?php
					if ( $this->agent_preexisted ) {
						/* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag. */
						$account_copy = __( '%1$sThe AI uses a dedicated "Block MCP" account.%2$s It edits your posts and pages but can\'t sign in or change your settings, and you can remove access anytime by disconnecting.', 'gk-block-mcp' );
					} else {
						/* translators: %1$s: opening <strong> tag, %2$s: closing </strong> tag. */
						$account_copy = __( '%1$sConnecting creates a new user account.%2$s It\'s created the first time you connect — when you download the installer or approve in your browser. Named "Block MCP", the AI uses it to edit your posts and pages. It can\'t sign in or change your settings, and you can remove it anytime by disconnecting.', 'gk-block-mcp' );
					}
					echo wp_kses(
						strtr(
							$account_copy,
							array(
								'%1$s' => '<strong>',
								'%2$s' => '</strong>',
							)
						),
						array( 'strong' => array() )
					);
					?>
				</p>
			</div>

			<?php if ( 'needs_https' === $state ) : ?>

				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'HTTPS required', 'gk-block-mcp' ); ?></strong>
					</p>
					<p>
						<?php esc_html_e( 'Your site needs a secure connection (HTTPS) first. Most hosts can enable this for free — ask them to turn on HTTPS/SSL, then come back.', 'gk-block-mcp' ); ?>
					</p>
				</div>

			<?php else : ?>

				<?php $this->render_connect_form( $setup_client ); ?>
				<?php $this->render_client_next_steps( $setup_client ); ?>

			<?php endif; ?>

			</div><!-- /.inside -->
		</div><!-- /.postbox.gk-block-mcp-connect__card -->

		</div><!-- /.gk-block-mcp-connect -->
		<?php
	}

	/**
	 * Build the manual-setup MCP config shown on the "Configure it myself" path.
	 *
	 * For users who wire up their client by hand instead of running the connect
	 * command: the standard stdio `mcpServers` entry with the real site URL and
	 * placeholders for the username and Application Password the user creates.
	 *
	 * @since 2.0.0
	 *
	 * @param string $site_url Site URL shown in WORDPRESS_URL.
	 * @return string Pretty-printed JSON.
	 */
	private function manual_config_json( $site_url ) {
		$config = array(
			'mcpServers' => array(
				'block-mcp' => array(
					'command' => 'npx',
					'args'    => array( '-y', '@gravitykit/block-mcp' ),
					'env'     => array(
						'WORDPRESS_URL'          => $site_url,
						'WORDPRESS_USER'         => 'your-username',
						'WORDPRESS_APP_PASSWORD' => 'your application password',
					),
				),
			),
		);

		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Render the "Active connections" metabox.
	 *
	 * Lists each connected AI client with a Disconnect button, in its own core
	 * .postbox metabox shown above the connect card when the site is connected.
	 *
	 * @since 2.0.0
	 *
	 * @param array $connections Rows from Connections::list().
	 * @return void
	 */
	private function render_active_connections( array $connections ) {
		?>
		<div class="postbox gk-block-mcp-connect__connections-box">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Active connections', 'gk-block-mcp' ); ?></h2>
			</div>
			<div class="inside">
				<p class="gk-block-mcp-connect__connections-desc">
					<?php esc_html_e( 'Each entry below is one connected AI client. Clicking Disconnect immediately revokes that client\'s access.', 'gk-block-mcp' ); ?>
				</p>
				<table class="gk-block-mcp-connect__connections-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Client', 'gk-block-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Account', 'gk-block-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Approved by', 'gk-block-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connected', 'gk-block-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last used', 'gk-block-mcp' ); ?></th>
							<th scope="col"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $connections as $conn ) : ?>
							<?php
							$approver      = ! empty( $conn['created_by'] ) ? get_userdata( $conn['created_by'] ) : null;
							$approver_name = $approver ? $approver->display_name : '—';
							$is_own        = ! empty( $conn['own_account'] );
							?>
							<tr>
								<td><?php echo esc_html( $conn['name'] ); ?></td>
								<td>
									<?php if ( $is_own ) : ?>
										<?php echo esc_html( $approver_name ); ?>
										<span class="description" style="display:block; color:#8a6d00;"><?php esc_html_e( 'Full access', 'gk-block-mcp' ); ?></span>
									<?php else : ?>
										<?php esc_html_e( 'Block MCP', 'gk-block-mcp' ); ?>
										<span class="description" style="display:block;"><?php esc_html_e( 'Limited account', 'gk-block-mcp' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $approver_name ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ), $conn['created'] ) ); ?></td>
								<td>
									<?php
									echo $conn['last_used']
										? esc_html( wp_date( get_option( 'date_format' ), $conn['last_used'] ) )
										: esc_html__( 'Never', 'gk-block-mcp' );
									?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVOKE ); ?>" />
										<input type="hidden" name="uuid" value="<?php echo esc_attr( $conn['uuid'] ); ?>" />
										<?php wp_nonce_field( self::ACTION_REVOKE ); ?>
										<button type="submit" class="gk-block-mcp-connect__disconnect-btn button-link button-link-delete"><?php esc_html_e( 'Disconnect', 'gk-block-mcp' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the setup-artifact card for an artifact client.
	 *
	 * Displays a readonly textarea containing the secret-free `npx connect` command
	 * (or AI prompt) and a single Copy button. No password field is shown — the
	 * credential is delivered later via the browser-Approve handshake when the user
	 * runs the command and clicks Approve in the browser window that opens.
	 *
	 * Emits markup only. The shared CSS and copy JS that wire every card on the
	 * page live in render_artifact_card_assets(), which must be called once.
	 *
	 * @since 2.0.0
	 * @since 2.0.0 Password param removed; command-only artifact, no credential shown.
	 * @since 2.0.0 $client is now a stable slug; label resolved via client_label().
	 * @since 2.0.0 Markup only; CSS/JS moved to render_artifact_card_assets() for multi-card support.
	 *
	 * @param string $client   Stable client slug (e.g. 'claude-code').
	 * @param array  $artifact Return value of setup_artifact().
	 * @param string $heading  Optional card heading; defaults to "{client label} setup".
	 * @return void
	 */
	private function render_artifact_card( $client, array $artifact, $heading = '' ) {
		if ( '' === $heading ) {
			$heading = sprintf(
				/* translators: %s: AI client name e.g. "Claude Code" */
				__( '%s setup', 'gk-block-mcp' ),
				$this->client_label( $client )
			);
		}
		?>
		<div class="gk-block-mcp-connect__artifact-card">
			<h3 class="gk-block-mcp-connect__artifact-heading"><?php echo esc_html( $heading ); ?></h3>

			<p class="gk-block-mcp-connect__artifact-label"><?php echo esc_html( $artifact['label'] ); ?></p>
			<div class="gk-block-mcp-connect__artifact-copy-wrap">
				<textarea
					class="gk-block-mcp-connect__artifact-textarea"
					readonly
					rows="3"
					data-language="<?php echo esc_attr( $artifact['language'] ); ?>"
				><?php echo esc_textarea( $artifact['body'] ); ?></textarea>
				<button type="button" class="gk-block-mcp-connect__artifact-copy-btn button" data-target="artifact"><?php esc_html_e( 'Copy', 'gk-block-mcp' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Emit the shared CSS and copy-to-clipboard JS for the artifact cards.
	 *
	 * Up to four cards can live in the DOM at once — one inside each artifact
	 * client's "How it works" panel, toggled by the radio selection. The card
	 * markup (render_artifact_card()) is therefore asset-free; this method emits
	 * the stylesheet and a single script that wires every card on the page. Each
	 * Copy button copies its OWN card's textarea, so the four cards never collide.
	 *
	 * Call this exactly once per page render, after the cards are output.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function render_artifact_card_assets() {
		?>
		<style>
		.gk-block-mcp-connect__artifact-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
			border-radius: 4px;
			padding: 16px 20px;
			max-width: 800px;
			margin: 16px 0 20px;
		}
		.gk-block-mcp-connect__artifact-heading {
			font-size: 1em;
			font-weight: 600;
			color: #1e1e1e;
			margin: 0 0 8px;
		}
		.gk-block-mcp-connect__artifact-label {
			font-size: .9375em;
			color: #1e1e1e;
			margin: 0 0 6px;
		}
		.gk-block-mcp-connect__artifact-copy-wrap {
			display: flex;
			flex-direction: column;
			gap: 8px;
			align-items: flex-start;
		}
		.gk-block-mcp-connect__artifact-textarea {
			width: 100%;
			box-sizing: border-box;
			font-family: monospace;
			font-size: .875em;
			resize: vertical;
			background: #f6f7f7;
			border: 1px solid #c3c4c7;
			border-radius: 2px;
			padding: 8px;
			color: #1e1e1e;
		}
		.gk-block-mcp-connect__artifact-copy-btn {
			flex-shrink: 0;
		}
		</style>

		<script>
		(function () {
			var defaultLabel = '<?php echo esc_js( __( 'Copy', 'gk-block-mcp' ) ); ?>';
			var copiedLabel  = '<?php echo esc_js( __( 'Copied!', 'gk-block-mcp' ) ); ?>';

			document.querySelectorAll( '.gk-block-mcp-connect__artifact-card' ).forEach( function ( card ) {
				var textarea = card.querySelector( '.gk-block-mcp-connect__artifact-textarea' );
				var copyBtn  = card.querySelector( '.gk-block-mcp-connect__artifact-copy-btn' );
				if ( ! textarea || ! copyBtn ) return;

				copyBtn.addEventListener( 'click', function () {
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( textarea.value ).then( function () {
							copyBtn.textContent = copiedLabel;
							setTimeout( function () { copyBtn.textContent = defaultLabel; }, 2000 );
						} );
					} else {
						textarea.select();
						document.execCommand( 'copy' );
					}
				} );
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
	 * @since 2.0.0
	 *
	 * @param string $callback Loopback callback URL (displayed for context; validated on POST).
	 * @param string $state    Opaque state token from the connector CLI (echoed back on redirect).
	 * @param string $client   Client label sent by the connector CLI (e.g. 'block-mcp').
	 * @return void
	 */
	private function render_authorize_screen( $callback, $state, $client ) {
		$site_name = get_bloginfo( 'name' );
		?>
		<style>
			/* Restate the native metabox chrome so the consent card matches the
				Connect / Active-connections postboxes: core .postbox styling only
				applies inside #poststuff, and this focused screen renders on its own. */
			.gk-block-mcp-connect .postbox {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				box-shadow: 0 1px 1px rgba( 0, 0, 0, .04 );
			}
			.gk-block-mcp-connect .postbox-header { border-bottom: 0; }
			.gk-block-mcp-connect .postbox .hndle {
				margin: 0;
				padding: 12px 16px;
				font-size: 14px;
				font-weight: 600;
				line-height: 1.4;
				color: #1e1e1e;
				border: 0;
				cursor: auto;
			}
			.gk-block-mcp-connect .postbox .inside { margin: 0; padding: 4px 16px 16px; }
			/* Focused consent layout: one centered card, no surrounding tabs. */
			.gk-block-mcp-connect--authorize { max-width: 640px; margin: 40px auto; }
			.gk-block-mcp-connect--authorize .gk-block-mcp-connect__card { max-width: none; margin: 0; }
		</style>
		<div class="gk-block-mcp-connect gk-block-mcp-connect--authorize">
		<div class="postbox gk-block-mcp-connect__card">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Allow your AI app to connect?', 'gk-block-mcp' ); ?></h2>
			</div>
			<div class="inside">

			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: site name, 2: client identifier */
						__( 'Your AI app (<code>%2$s</code>) on this computer is asking for permission to create and edit content on <strong>%1$s</strong>.', 'gk-block-mcp' ),
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
			<p><?php esc_html_e( 'Approving creates an Application Password that your AI app uses to connect. You can remove this access anytime from the Block MCP settings page.', 'gk-block-mcp' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action"   value="<?php echo esc_attr( self::ACTION_AUTHORIZE ); ?>" />
				<input type="hidden" name="callback" value="<?php echo esc_attr( $callback ); ?>" />
				<input type="hidden" name="state"    value="<?php echo esc_attr( $state ); ?>" />
				<input type="hidden" name="client"   value="<?php echo esc_attr( $client ); ?>" />
				<?php wp_nonce_field( self::ACTION_AUTHORIZE ); ?>
				<?php $current_display_name = wp_get_current_user()->display_name; ?>
				<style>
					.gk-block-mcp-connect__identity { border: 0; margin: 0 0 16px; padding: 0; }
					.gk-block-mcp-connect__identity-legend { font-weight: 600; margin: 0 0 8px; padding: 0; }
					.gk-block-mcp-connect__identity-option {
						display: flex;
						gap: 10px;
						align-items: flex-start;
						border: 1px solid #c3c4c7;
						border-radius: 6px;
						padding: 12px 14px;
						margin: 0 0 8px;
						cursor: pointer;
					}
					.gk-block-mcp-connect__identity-option:hover { border-color: var(--wp-admin-theme-color, #2271b1); }
					.gk-block-mcp-connect__identity-option:has(input:focus-visible) {
						outline: 2px solid var(--wp-admin-theme-color, #2271b1);
						outline-offset: 1px;
					}
					/* Selected state mirrors the connect screen's radio cards: blue border + ring on a light-blue surface. */
					.gk-block-mcp-connect__identity-option:has(input:checked) {
						border-color: var(--wp-admin-theme-color, #2271b1);
						box-shadow: 0 0 0 1px var(--wp-admin-theme-color, #2271b1);
						background: #f0f6fc;
					}
					.gk-block-mcp-connect__identity-option input[type="radio"] {
						margin-top: 3px;
						flex: 0 0 auto;
						accent-color: var(--wp-admin-theme-color, #2271b1);
					}
					.gk-block-mcp-connect__identity-body { display: block; }
					.gk-block-mcp-connect__identity-title { display: block; font-weight: 600; }
					.gk-block-mcp-connect__identity-option .description { display: block; margin-top: 2px; }
					.gk-block-mcp-connect__identity-warning {
						display: block;
						margin-top: 6px;
						padding: 10px 14px;
						background: #f0f6fc;
						border-left: 3px solid #72aee6;
						color: #1e1e1e;
					}
					.gk-block-mcp-connect__actions {
						display: flex;
						align-items: center;
						justify-content: space-between;
						margin-top: 8px;
					}
					.gk-block-mcp-connect__actions .button { margin: 0; }
					/* Cancel as a standard destructive button: red outline that fills on hover. */
					.gk-block-mcp-connect__actions .button-link-delete {
						color: #b32d2e;
						border: 1px solid #b32d2e;
						background: transparent;
						box-shadow: none;
						text-decoration: none;
					}
					.gk-block-mcp-connect__actions .button-link-delete:hover,
					.gk-block-mcp-connect__actions .button-link-delete:focus {
						color: #fff;
						background: #b32d2e;
						border-color: #b32d2e;
					}
					/* Connections-table Disconnect: a red WP-style action link
						(#b32d2e), not the default blue button-link and not the
						bordered destructive button used in __actions above. */
					.gk-block-mcp-connect__disconnect-btn {
						color: #b32d2e;
					}
					.gk-block-mcp-connect__disconnect-btn:hover,
					.gk-block-mcp-connect__disconnect-btn:focus {
						color: #8a2424;
					}
					.gk-block-mcp-connect--authorize .gk-block-mcp-connect__help {
						text-align: center;
						margin: 20px 0 4px;
					}
					.gk-block-mcp-connect__self-ack {
						margin: 0 0 16px;
						padding: 10px 14px;
						background: #fcf0f1;
						border-left: 3px solid #b32d2e;
					}
					.gk-block-mcp-connect__self-ack label { display: flex; gap: 8px; align-items: flex-start; }
					.gk-block-mcp-connect__self-ack input { margin-top: 3px; }
				</style>
				<fieldset class="gk-block-mcp-connect__identity">
					<legend class="gk-block-mcp-connect__identity-legend"><?php esc_html_e( 'How should the AI app act on your site?', 'gk-block-mcp' ); ?></legend>

					<label class="gk-block-mcp-connect__identity-option">
						<input type="radio" name="identity" value="agent" checked="checked" />
						<span class="gk-block-mcp-connect__identity-body">
							<span class="gk-block-mcp-connect__identity-title">
								<?php esc_html_e( 'Dedicated Block MCP account', 'gk-block-mcp' ); ?>
								<em><?php esc_html_e( '(recommended)', 'gk-block-mcp' ); ?></em>
							</span>
							<span class="description"><?php esc_html_e( 'A limited account just for your AI app. It can create and edit content, but can\'t change settings, delete other people\'s content, or sign in. New posts are authored by "Block MCP".', 'gk-block-mcp' ); ?></span>
						</span>
					</label>

					<?php // Applies the gk/block-mcp/identity/allow-self filter (documented in provision_credentials()). ?>
					<?php $self_allowed = (bool) apply_filters( 'gk/block-mcp/identity/allow-self', true ); ?>
					<?php if ( $self_allowed ) : ?>
					<label class="gk-block-mcp-connect__identity-option gk-block-mcp-connect__identity-option--risky">
						<input type="radio" name="identity" value="self" />
						<span class="gk-block-mcp-connect__identity-body">
							<span class="gk-block-mcp-connect__identity-title">
								<?php
								printf(
									/* translators: %s: the approving user's display name */
									esc_html__( 'Your own account (%s)', 'gk-block-mcp' ),
									esc_html( $current_display_name )
								);
								?>
							</span>
							<span class="description">
								<?php
									printf(
										/* translators: 1: opening <strong> tag, 2: closing </strong> tag */
										esc_html__( '%1$sHigher risk:%2$s creates an Application Password on your own account, giving the AI app the same full access you have — including changing site settings and deleting any content. Only choose this if you understand the risk.', 'gk-block-mcp' ),
										'<strong>',
										'</strong>'
									);
								?>
							</span>
						</span>
					</label>
					<?php endif; ?>
				</fieldset>
				<?php if ( $self_allowed ) : ?>
				<div class="gk-block-mcp-connect__self-ack" id="gk-block-mcp-connect__self-ack" hidden>
					<label>
						<input type="checkbox" name="self_ack" id="gk-block-mcp-connect__self-ack-check" />
						<?php esc_html_e( 'I understand this creates an Application Password with my account\'s full access.', 'gk-block-mcp' ); ?>
					</label>
				</div>
				<?php endif; ?>
				<div class="gk-block-mcp-connect__actions">
					<?php submit_button( __( 'Approve', 'gk-block-mcp' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Settings_Page::PAGE_SLUG . '&tab=connect' ) ); ?>" class="button button-link-delete">
						<?php esc_html_e( 'Cancel', 'gk-block-mcp' ); ?>
					</a>
				</div>
				<?php if ( $self_allowed ) : ?>
				<script>
				( function () {
					var script = document.currentScript;
					var form   = script ? script.closest( 'form' ) : null;
					if ( ! form ) { return; }
					var ackRow    = form.querySelector( '#gk-block-mcp-connect__self-ack' );
					var ackCheck  = form.querySelector( '#gk-block-mcp-connect__self-ack-check' );
					var submitBtn = form.querySelector( '#submit' );
					if ( ! ackRow || ! ackCheck || ! submitBtn ) { return; }
					function sync() {
						var selected = form.querySelector( 'input[name="identity"]:checked' );
						var isSelf   = !! selected && 'self' === selected.value;
						var blocked  = isSelf && ! ackCheck.checked;
						ackRow.hidden = ! isSelf;
						submitBtn.disabled = blocked;
						submitBtn.setAttribute( 'aria-disabled', blocked ? 'true' : 'false' );
					}
					form.addEventListener( 'change', sync );
					sync();
				} )();
				</script>
				<?php endif; ?>
				<script>
				/* Approve via fetch() so the server returns the exchange code as
					JSON and the browser navigates to the loopback callback from
					here — a server-issued redirect to 127.0.0.1 reads as SSRF to
					origin WAFs and gets blocked. Falls back to a native submit
					(handled by the no-JS branch in handle_authorize()) if fetch is
					unavailable or fails. */
				( function () {
					var script = document.currentScript;
					var form   = script ? script.closest( 'form' ) : null;
					if ( ! form || ! window.fetch || ! window.URL || ! window.FormData ) { return; }

					// The form has controls named "action" and "submit", which
					// shadow form.action (→ the input, not the URL) and
					// form.submit (→ the input, not the method). Read the action
					// via getAttribute(); submit via the prototype method.
					var actionUrl    = form.getAttribute( 'action' );
					var nativeSubmit = function () { HTMLFormElement.prototype.submit.call( form ); };

					form.addEventListener( 'submit', function ( e ) {
						e.preventDefault();

						var data = new FormData( form );
						data.append( 'gk_xhr', '1' );

						fetch( actionUrl, {
							method:      'POST',
							body:        data,
							credentials: 'same-origin',
							headers:     { 'X-Requested-With': 'XMLHttpRequest' }
						} ).then( function ( res ) {
							return res.ok ? res.json() : null;
						} ).then( function ( json ) {
							if ( ! json || ! json.success || ! json.data || ! json.data.code ) {
								throw new Error( 'bad response' );
							}
							var cb = form.querySelector( 'input[name="callback"]' ).value;
							var st = form.querySelector( 'input[name="state"]' );
							var url = new URL( cb );
							url.searchParams.set( 'code', json.data.code );
							url.searchParams.set( 'state', json.data.state || ( st ? st.value : '' ) );
							window.location.assign( url.toString() );
						} ).catch( function () {
							nativeSubmit(); // native submit bypasses this handler.
						} );
					} );
				} )();
				</script>
			</form>

			<?php $this->render_help_link(); ?>

			</div><!-- /.inside -->
		</div><!-- /.postbox.gk-block-mcp-connect__card -->
		</div><!-- /.gk-block-mcp-connect -->
		<?php
	}

	/**
	 * Render the client-picker form that triggers a bundle download or artifact generation.
	 *
	 * The picker is a fieldset of radio cards so keyboard navigation, screen
	 * readers, and pointer devices all work with standard browser behaviour.
	 * Five clients are offered: claude-desktop (.mcpb download), claude-code,
	 * cursor, ai-prompt, and other. The ai-prompt card is
	 * visually prominent with an accent left-border modifier so it is an obvious
	 * choice for users who are already in an AI session.
	 *
	 * Cards are generated by iterating clients() so the form and the branching
	 * logic share a single source of truth.
	 *
	 * All selectors are scoped under .gk-block-mcp-connect to prevent leaking into
	 * the rest of wp-admin. The design follows the WordPress block-editor /
	 *
	 * @wordpress/components visual language: white card surfaces on the gray
	 * admin background, accent-color via --wp-admin-theme-color.
	 *
	 * @since 2.0.0
	 * @since 2.0.0 Radio values are stable slugs; labels come from clients().
	 *
	 * @param string $default_client Preselected client slug (the ?setup client);
	 *                               empty selects the default Claude Desktop card.
	 * @return void
	 */
	private function render_connect_form( string $default_client = '' ) {
		$clients = $this->clients();

		// In ?setup=<client> mode the picker preselects that client so the radio,
		// the is-selected card, and the visible next-steps panel all match the
		// artifact shown above — otherwise the reload would reset to Claude
		// Desktop and render two contradictory instruction panels at once.
		$selected = ( '' !== $default_client ) ? $default_client : self::CLIENT_CLAUDE_DESKTOP;
		?>
		<style>
		/* ── Outer card ────────────────────────────────────────────────────── */
		/*
		WordPress's core .postbox chrome (white background + shadow) is only fully
		applied inside the post editor's #poststuff context, so on this custom
		settings page we restate the native metabox appearance ourselves — scoped to
		.gk-block-mcp-connect — so the connect box and the Active connections box read
		as real wp-admin metaboxes (white, bordered, header bar) and match.
		*/
		.gk-block-mcp-connect .postbox {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 8px;
			box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			margin-top: 20px;
		}
		.gk-block-mcp-connect .postbox-header {
			border-bottom: 0;
		}
		.gk-block-mcp-connect .postbox .hndle {
			margin: 0;
			padding: 12px 16px;
			font-size: 14px;
			font-weight: 600;
			line-height: 1.4;
			color: #1e1e1e;
			border: 0;
			cursor: auto;
		}
		.gk-block-mcp-connect .postbox .inside {
			margin: 0;
			padding: 4px 16px 16px;
		}
		.gk-block-mcp-connect__card {
			max-width: 800px;
		}
		.gk-block-mcp-connect__help--top {
			float: right;
			margin: 0 0 4px 20px;
		}
		.gk-block-mcp-connect__intro {
			color: #1e1e1e;
			margin: 0 0 12px;
		}

		/* ── Paste-mode password display ───────────────────────────────────── */
		.gk-block-mcp-connect__paste-pw {
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
			color: #646970;
			line-height: 1.4;
		}

		/* ── Primary submit button (components Button is-primary style) ─────── */
		.gk-block-mcp-connect #submit {
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
		.gk-block-mcp-connect #submit:hover,
		.gk-block-mcp-connect #submit:active {
			background: var(--wp-admin-theme-color-darker-10, #1d6196);
			color: #fff;
		}
		.gk-block-mcp-connect #submit:focus-visible {
			outline: none;
			box-shadow: 0 0 0 1.5px #fff, 0 0 0 3px var(--wp-admin-theme-color, #2271b1);
		}

		/* ── "After you download/set up" inner panel ───────────────────────── */
		.gk-block-mcp-connect__next-steps {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 4px;
			padding: 16px 20px;
			max-width: 700px;
			margin-top: 24px;
		}
		.gk-block-mcp-connect__next-steps h3 {
			margin-top: 0;
			font-weight: 600;
			color: #1e1e1e;
		}
		.gk-block-mcp-connect__next-steps ol {
			margin: 0 0 12px;
			padding-left: 1.5em;
		}
		.gk-block-mcp-connect__next-steps li {
			color: #1e1e1e;
			margin-bottom: 8px;
			line-height: 1.5;
		}
		.gk-block-mcp-connect__next-steps p {
			margin-bottom: 0;
			color: #646970;
		}

		/* ── Active connections inner panel ────────────────────────────────── */
		.gk-block-mcp-connect__connections-desc {
			color: #646970;
			font-size: .875em;
			margin: 0 0 12px;
		}
		.gk-block-mcp-connect__connections-table {
			width: 100%;
			border-collapse: collapse;
		}
		.gk-block-mcp-connect__connections-table th {
			text-align: left;
			font-weight: 600;
			color: #646970;
			font-size: .8125em;
			text-transform: uppercase;
			letter-spacing: .03em;
			padding: 6px 12px 6px 0;
			border-bottom: 1px solid #f0f0f1;
		}
		.gk-block-mcp-connect__connections-table td {
			color: #1e1e1e;
			padding: 10px 12px 10px 0;
			border-bottom: 1px solid #f0f0f1;
			font-size: .9375em;
		}
		.gk-block-mcp-connect__connections-table tr:last-child td {
			border-bottom: none;
		}

		/* ── Disconnect button (link-button, tertiary style) ───────────────── */
		.gk-block-mcp-connect__disconnect-btn.button.button-link {
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
		.gk-block-mcp-connect__disconnect-btn.button.button-link:hover {
			color: var(--wp-admin-theme-color-darker-10, #1d6196);
			text-decoration: underline;
		}
		.gk-block-mcp-connect__disconnect-btn.button.button-link:focus-visible {
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
					<?php esc_html_e( 'Which app do you use to chat with AI?', 'gk-block-mcp' ); ?>
				</legend>

				<div class="gk-radio-card-group">

					<?php foreach ( $clients as $slug => $meta ) : ?>
					<label
						class="gk-radio-card<?php echo ( $selected === $slug ) ? ' is-selected' : ''; ?><?php echo ( self::CLIENT_AI_PROMPT === $slug ) ? ' is-ai' : ''; ?>"
						id="<?php echo esc_attr( 'gk-card-' . $slug ); ?>"
					>
						<input
							class="gk-radio-card__radio"
							type="radio"
							name="client"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $selected, $slug ); ?>
						/>
						<span class="gk-radio-card__body">
							<span class="gk-radio-card__title"><?php echo esc_html( $meta['label'] ); ?></span>
							<span class="gk-radio-card__desc"><?php echo esc_html( $meta['description'] ); ?></span>
						</span>
					</label>
					<?php endforeach; ?>

				</div>
			</fieldset>

			<?php
			// "create MCP user" is only accurate the first time — once the agent
			// account exists, downloading the installer just reuses it. Reads the
			// pre-provision snapshot so a genuine first run still says "create".
			$desktop_button_label = $this->agent_preexisted
				? __( 'Download installer', 'gk-block-mcp' )
				: __( 'Download installer & create MCP user', 'gk-block-mcp' );
			?>
			<script>
			(function () {
				function init() {
				var radios    = document.querySelectorAll( 'input[name="client"]' );
				var btn       = document.getElementById( 'submit' );
				var nextSteps = document.querySelectorAll( '.gk-block-mcp-connect__next-steps[data-client]' );

				if ( ! radios.length ) return;

				var labels = {
					'<?php echo esc_js( self::CLIENT_CLAUDE_DESKTOP ); ?>': <?php echo wp_json_encode( $desktop_button_label ); ?>
				};

				// For these clients the copyable command/prompt now lives inline in the
				// matching "How it works" block below, so the primary submit button is
				// hidden — there is nothing to submit.
				var artifactClients = [
					'<?php echo esc_js( self::CLIENT_CLAUDE_CODE ); ?>',
					'<?php echo esc_js( self::CLIENT_CURSOR ); ?>',
					'<?php echo esc_js( self::CLIENT_AI_PROMPT ); ?>',
					'<?php echo esc_js( self::CLIENT_OTHER ); ?>'
				];

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

					if ( btn ) {
						var isArtifact = artifactClients.indexOf( checkedVal ) !== -1;
						// Hide the whole submit paragraph (not just the input) so no
						// empty margin remains for the artifact clients, whose command
						// card carries its own Copy button.
						var btnWrap = btn.closest( '.submit' ) || btn;
						btnWrap.hidden = isArtifact;
						if ( ! isArtifact ) {
							btn.value = labels[ checkedVal ] || labels[ '<?php echo esc_js( self::CLIENT_CLAUDE_DESKTOP ); ?>' ];
						}
					}

					// Show only the next-steps block matching the selected client; hide the rest.
					nextSteps.forEach( function ( el ) {
						var isMatch = el.getAttribute( 'data-client' ) === checkedVal;
						el.style.display = isMatch ? '' : 'none';
						el.setAttribute( 'aria-hidden', isMatch ? 'false' : 'true' );
					} );
				}

				radios.forEach( function ( r ) {
					r.addEventListener( 'change', updateState );
				} );

				updateState();
				}
				if ( 'loading' === document.readyState ) {
					document.addEventListener( 'DOMContentLoaded', init );
				} else {
					init();
				}
			} )();
			</script>

			<?php submit_button( $desktop_button_label, 'primary', 'submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Render per-client "next steps" blocks — one for each of the six client slugs.
	 *
	 * All six blocks are written to the DOM simultaneously. Only the block whose
	 * `data-client` attribute matches the selected client is visible on load; the
	 * others carry `aria-hidden="true"` and are hidden via inline style. The JS
	 * `updateState` handler (in render_connect_form()) swaps visibility whenever the
	 * radio selection changes.
	 *
	 * The Claude Desktop block walks through the .mcpb download. Each of the four
	 * artifact clients (CLI tools + AI prompt) embeds its own secret-free setup card
	 * (render_artifact_card()) inline so the copyable command/prompt appears the
	 * moment that client is selected — no button click, no reload. The `other` block
	 * helps the user pick. After the loop, render_artifact_card_assets() emits the
	 * shared card CSS + copy JS exactly once.
	 *
	 * @since 2.0.0
	 *
	 * @param string $default_client Preselected client slug whose panel is visible
	 *                               on load; empty defaults to Claude Desktop.
	 * @return void
	 */
	private function render_client_next_steps( string $default_client = '' ) {
		$clients = array(
			self::CLIENT_CLAUDE_DESKTOP,
			self::CLIENT_CLAUDE_CODE,
			self::CLIENT_CURSOR,
			self::CLIENT_AI_PROMPT,
			self::CLIENT_OTHER,
		);

		// The default-visible panel matches the preselected client (the ?setup
		// client when present, otherwise Claude Desktop) so it is consistent
		// with the radio selection and the artifact shown above.
		$selected = ( '' !== $default_client ) ? $default_client : self::CLIENT_CLAUDE_DESKTOP;

		foreach ( $clients as $slug ) {
			$is_default  = ( $selected === $slug );
			$hidden_attr = $is_default ? '' : ' style="display:none;"';
			$aria_attr   = $is_default ? '' : ' aria-hidden="true"';
			?>
			<div
				class="gk-block-mcp-connect__next-steps"
				data-client="<?php echo esc_attr( $slug ); ?>"
				<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static safe attribute strings, no user data. ?>
				<?php echo $aria_attr;   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static safe attribute strings, no user data. ?>
			>
				<?php $this->render_client_next_steps_body( $slug ); ?>
			</div>
			<?php
		}

		// The four artifact panels each embed a setup card; emit the shared
		// CSS + copy JS exactly once, after every card is in the DOM.
		$this->render_artifact_card_assets();
	}

	/**
	 * Render the inner content of a single per-client next-steps block.
	 *
	 * Separated from render_client_next_steps() so the markup for each client can
	 * be read and tested in isolation. The containing <div> (with data-client and
	 * visibility attributes) is the caller's responsibility.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug One of the CLIENT_* slug constants.
	 * @return void
	 */
	private function render_client_next_steps_body( string $slug ) {
		switch ( $slug ) {

			case self::CLIENT_CLAUDE_DESKTOP:
				?>
				<h3><?php esc_html_e( 'After you download', 'gk-block-mcp' ); ?></h3>
				<ol>
					<li>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: download URL */
								__( 'Get the <a href="%s" target="_blank" rel="noopener noreferrer">Claude Desktop app</a> if you don\'t have it yet.', 'gk-block-mcp' ),
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
							__( 'Double-click the downloaded file — a Claude Desktop setup file named like <code>block-mcp-yoursite.mcpb</code>. It\'s safe, and double-clicking opens it directly in Claude Desktop. (If your computer asks what to open it with, choose Claude.)', 'gk-block-mcp' ),
							array( 'code' => array() )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Click Enable — everything\'s pre-filled.', 'gk-block-mcp' ); ?></li>
					<li>
						<?php
						echo wp_kses(
							__( 'Try asking: <em>&#8220;Edit the homepage on my site.&#8221;</em>', 'gk-block-mcp' ),
							array( 'em' => array() )
						);
						?>
					</li>
					<li><?php esc_html_e( 'That file briefly holds a private key; once you\'ve clicked Enable you can delete it from Downloads — your AI app has stored the key securely.', 'gk-block-mcp' ); ?></li>
				</ol>
				<?php
				break;

			case self::CLIENT_CLAUDE_CODE:
			case self::CLIENT_CURSOR:
				?>
				<h3><?php esc_html_e( 'How it works', 'gk-block-mcp' ); ?></h3>
				<?php $this->render_artifact_card( $slug, $this->setup_artifact( $slug, untrailingslashit( home_url() ) ) ); ?>
				<ol>
					<li><?php esc_html_e( 'Copy the command above and run it in your terminal.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'A browser window opens.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'Click Approve — the connection finishes automatically.', 'gk-block-mcp' ); ?></li>
				</ol>
				<p class="description">
					<?php esc_html_e( 'This option is for developer tools that use a command line (the Terminal). Not sure what a terminal is? Choose "Claude Desktop app" or "Let my AI set it up for me" above instead — no terminal needed.', 'gk-block-mcp' ); ?>
				</p>
				<?php
				break;

			case self::CLIENT_AI_PROMPT:
				?>
				<h3><?php esc_html_e( 'How it works', 'gk-block-mcp' ); ?></h3>
				<?php $this->render_artifact_card( $slug, $this->setup_artifact( $slug, untrailingslashit( home_url() ) ), __( 'Your setup prompt', 'gk-block-mcp' ) ); ?>
				<ol>
					<li><?php esc_html_e( 'Copy the prompt above and paste it to your AI assistant.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'The assistant runs the command and a browser window opens.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'Click Approve — it confirms the connection.', 'gk-block-mcp' ); ?></li>
				</ol>
				<?php
				break;

			case self::CLIENT_OTHER:
			default:
				?>
				<h3><?php esc_html_e( 'How it works', 'gk-block-mcp' ); ?></h3>
				<p><?php esc_html_e( 'Block MCP works with any client that runs a local MCP server. Run this, approve in the browser, and your terminal prints a ready-to-paste config:', 'gk-block-mcp' ); ?></p>
				<?php $this->render_artifact_card( $slug, $this->setup_artifact( $slug, untrailingslashit( home_url() ) ), __( 'Generate your config', 'gk-block-mcp' ) ); ?>
				<ol>
					<li><?php esc_html_e( 'Copy the command above and run it in your terminal.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'A browser window opens.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'Click Approve.', 'gk-block-mcp' ); ?></li>
					<li><?php esc_html_e( 'Your terminal prints the finished config, with the password filled in — paste it into your MCP client and restart it.', 'gk-block-mcp' ); ?></li>
				</ol>
				<p class="description gk-block-mcp-connect__safety-note" style="display:flex; align-items:flex-start; gap:6px; background:#f0f6fc; border-left:3px solid #72aee6; padding:10px 14px; margin:16px 0 0; max-width:800px;">
					<span class="dashicons dashicons-info-outline" aria-hidden="true" style="color:#2271b1; flex:0 0 auto;"></span>
					<span><?php esc_html_e( 'The password is created on your computer when you approve, and only ever appears in your terminal — never on this page.', 'gk-block-mcp' ); ?></span>
				</p>
				<details class="gk-block-mcp-connect__manual" style="margin-top:16px; max-width:800px;">
				<summary style="cursor:pointer; font-weight:600; color:#1e1e1e;"><?php esc_html_e( 'Prefer to set it up by hand (no command)?', 'gk-block-mcp' ); ?></summary>
				<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Skip the command and connect manually — create your own Application Password and add Block MCP to your client\'s config.', 'gk-block-mcp' ); ?></p>
				<ol>
					<li><?php echo wp_kses( __( 'In <strong>Users → Profile → Application Passwords</strong>, create a password for any user who can edit posts.', 'gk-block-mcp' ), array( 'strong' => array() ) ); ?></li>
					<li><?php esc_html_e( 'Add this to your MCP client\'s config file, filling in that username and the password:', 'gk-block-mcp' ); ?></li>
				</ol>
				<pre style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:12px 14px; margin:8px 0 0; overflow-x:auto; font-size:12px; line-height:1.6; max-width:800px;"><?php echo esc_html( $this->manual_config_json( untrailingslashit( home_url() ) ) ); ?></pre>
				</details>
				<?php
				break;
		}
	}
}
