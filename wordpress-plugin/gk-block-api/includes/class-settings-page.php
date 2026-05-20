<?php
/**
 * Settings page for the Block MCP plugin.
 *
 * Single Settings → Block MCP admin page exposing the policy that drives
 * tier classification, replacement suggestions, dual-storage detection,
 * and the post-type allow-list. All fields persist to existing options
 * already consumed by the runtime — Preferences (tier scores, replacement
 * map), Post_Manager (allow-list), Block_Inventory (dual-storage extras +
 * scan results).
 *
 * Settings API is used for the form fields. The "Re-scan storage modes"
 * and "Reset to defaults" buttons post to admin-post.php with an action
 * + nonce so they don't share state with the form's settings save.
 *
 * Capability: `manage_options`.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Page
 */
class Settings_Page {

	const PAGE_SLUG    = 'gk-block-api-settings';
	const OPTION_GROUP = 'gk_block_api_settings';

	/** Option backing the manual "force-treat as dual-storage" list (UI-editable). */
	const DUAL_MANUAL_OPTION = 'gk_block_api_dual_storage_blocks_manual';

	/**
	 * Block inventory instance.
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Block_Inventory $inventory Used by the "Re-scan storage modes" button.
	 *                                   Defaults are read directly via `Preferences::get_defaults()`
	 *                                   in the renderer — no Preferences instance needed here.
	 */
	public function __construct( Block_Inventory $inventory ) {
		$this->inventory = $inventory;
	}

	/**
	 * Wire up admin hooks. Safe to call from rest_api_init or admin_init —
	 * the inner hooks fire later in the request lifecycle.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gk_block_api_scan_storage_modes', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_gk_block_api_reset_defaults', array( $this, 'handle_reset' ) );
	}

	/**
	 * Add the page under Settings.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Block MCP', 'gk-block-api' ),
			__( 'Block MCP', 'gk-block-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings + sections + fields.
	 */
	public function register_settings() {
		// 1. Preferences (tier scores + replacement map). Stored as a single
		// associative array; we sanitize sub-keys in the callback.
		register_setting(
			self::OPTION_GROUP,
			'gk_block_api_preferences',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_preferences' ),
				'default'           => array(),
			)
		);

		// 2. Post-type allow-list for create_post (BLOCK-12 / v1.2).
		register_setting(
			self::OPTION_GROUP,
			'gk_block_api_post_types_allowlist',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_type_allowlist' ),
				'default'           => array(),
			)
		);

		// 3. Manual dual-storage list — merged with scan results + filter defaults.
		register_setting(
			self::OPTION_GROUP,
			self::DUAL_MANUAL_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_block_name_list' ),
				'default'           => array(),
			)
		);

		// 4. MCP server instructions addendum (BLOCK-19).
		// Stored as a plain-text string. The Instructions class handles
		// sanitize + length-cap + timestamp; the REST endpoint serves it
		// unauthenticated to MCP clients at handshake.
		register_setting(
			self::OPTION_GROUP,
			Instructions::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Instructions::class, 'sanitize_callback' ),
				'default'           => '',
			)
		);

		// 5. Global media-uploads kill-switch. Stored as the string '0' or
		// '1' rather than a PHP bool because update_option() can't
		// reliably persist boolean false when the option is missing
		// (the equality check against the "doesn't exist → false" default
		// short-circuits the write).
		register_setting(
			self::OPTION_GROUP,
			\GravityKit\BlockAPI\Media_Manager::UPLOADS_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					if ( is_bool( $value ) ) {
						return $value ? '1' : '0';
					}
					$truthy = in_array( strtolower( (string) $value ), array( '1', 'on', 'true', 'yes' ), true );
					return $truthy ? '1' : '0';
				},
				'default'           => '1',
			)
		);
	}

	// ──────────────────────────────────────────────────────────────────
	// Sanitization callbacks.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Sanitize the indexed-row form input back into the canonical
	 * `namespace_scores` + `replacement_map` shape Preferences expects.
	 *
	 * Form input is row-indexed so we can rename namespaces/blocks safely
	 * and so a new row's values are correlated. Rows flagged with `delete:1`
	 * are dropped.
	 *
	 * @param mixed $input Raw POST value.
	 * @return array
	 */
	public function sanitize_preferences( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$out = array();

		// Namespace tier scores — indexed rows: [{name, score, delete?}, ...].
		if ( isset( $input['namespace_rows'] ) && is_array( $input['namespace_rows'] ) ) {
			$out['namespace_scores'] = array();
			foreach ( $input['namespace_rows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
					continue;
				}
				$ns = isset( $row['name'] ) ? sanitize_key( $row['name'] ) : '';
				if ( '' === $ns ) {
					continue;
				}
				$score                          = isset( $row['score'] ) ? (int) $row['score'] : 0;
				$out['namespace_scores'][ $ns ] = max( 0, min( 100, $score ) );
			}
		}

		// Replacement map — indexed rows: [{from, to, delete?}, ...].
		if ( isset( $input['replacement_rows'] ) && is_array( $input['replacement_rows'] ) ) {
			$out['replacement_map'] = array();
			foreach ( $input['replacement_rows'] as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
					continue;
				}
				$from = isset( $row['from'] ) ? $this->sanitize_block_name( $row['from'] ) : '';
				$to   = isset( $row['to'] ) ? $this->sanitize_block_name( $row['to'] ) : '';
				if ( '' !== $from && '' !== $to ) {
					$out['replacement_map'][ $from ] = $to;
				}
			}
		}

		// Preserve any other top-level keys the runtime may add (forwards-compat).
		$existing = (array) get_option( 'gk_block_api_preferences', array() );
		return array_merge( $existing, $out );
	}

	/**
	 * Validate post types against the registered list. Filters out
	 * anything that isn't actually registered to prevent typos.
	 *
	 * @param mixed $input Raw POST value.
	 * @return string[]
	 */
	public function sanitize_post_type_allowlist( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$registered = get_post_types( array( 'public' => true ), 'names' );
		$out        = array();
		foreach ( $input as $type ) {
			$slug = sanitize_key( $type );
			if ( '' !== $slug && isset( $registered[ $slug ] ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize a list of fully-qualified block names (one per line in the textarea).
	 *
	 * @param mixed $input Raw POST value — string (textarea) or array.
	 * @return string[]
	 */
	public function sanitize_block_name_list( $input ) {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\r\n,]+/', $input );
		}
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $name ) {
			$clean = $this->sanitize_block_name( $name );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize a single block name. Allows lowercased letters, digits, dashes,
	 * underscores, and a single forward slash separator.
	 *
	 * @param string $name Raw block name to sanitize.
	 * @return string Empty string if invalid.
	 */
	private function sanitize_block_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $name ) ) {
			return '';
		}
		return $name;
	}

	// ──────────────────────────────────────────────────────────────────
	// Action handlers (admin-post.php).
	// ──────────────────────────────────────────────────────────────────

	/**
	 * "Re-scan storage modes" button handler.
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gk_block_api_scan_storage_modes' );

		$result = $this->inventory->scan_storage_modes();

		nocache_headers();
		$args = array(
			'page'    => self::PAGE_SLUG,
			'scanned' => 1,
			'unique'  => (int) $result['unique_blocks'],
			'dual'    => (int) $result['dual_count'],
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * "Reset to defaults" button handler. Deletes all UI-managed options
	 * AND the inventory transients + per-post rate-limit transients so
	 * the next read starts from a true clean slate.
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gk_block_api_reset_defaults' );

		delete_option( 'gk_block_api_preferences' );
		delete_option( 'gk_block_api_post_types_allowlist' );
		delete_option( self::DUAL_MANUAL_OPTION );
		delete_option( Media_Manager::UPLOADS_OPTION );
		delete_option( Block_Inventory::STORAGE_MODES_OPTION );
		delete_option( Instructions::OPTION_KEY );
		delete_option( Instructions::UPDATED_AT_OPTION );
		delete_transient( Block_Inventory::CACHE_KEY );

		// Per-post rate-limit transients accumulate per write activity. Sweep
		// them too so reset is a true clean slate.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_gk_block_api_rate_%'
				   OR option_name LIKE '_transient_timeout_gk_block_api_rate_%'
				   OR option_name LIKE '_transient_gk_block_api_instr_rl_%'
				   OR option_name LIKE '_transient_timeout_gk_block_api_instr_rl_%'"
		);

		nocache_headers();
		$args = array(
			'page'  => self::PAGE_SLUG,
			'reset' => 1,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	// ──────────────────────────────────────────────────────────────────
	// Render.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gk-block-api' ), '', array( 'response' => 403 ) );
		}

		$defaults         = Preferences::get_defaults();
		$prefs            = (array) get_option( 'gk_block_api_preferences', array() );
		$namespace_scores = isset( $prefs['namespace_scores'] ) && is_array( $prefs['namespace_scores'] )
			? $prefs['namespace_scores']
			: $defaults['namespace_scores'];
		$replacement_map  = isset( $prefs['replacement_map'] ) && is_array( $prefs['replacement_map'] )
			? $prefs['replacement_map']
			: $defaults['replacement_map'];
		$post_type_allow  = (array) get_option( 'gk_block_api_post_types_allowlist', array() );
		$manual_dual      = (array) get_option( self::DUAL_MANUAL_OPTION, array() );
		$scan_results     = (array) get_option( Block_Inventory::STORAGE_MODES_OPTION, array() );
		$uploads_enabled  = \GravityKit\BlockAPI\Media_Manager::uploads_enabled();
		$uploads_option   = \GravityKit\BlockAPI\Media_Manager::UPLOADS_OPTION;
		$instructions_val = Instructions::get_addendum();
		$instructions_max = Instructions::MAX_LENGTH;

		$registered_post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Build a sorted list of every registered block name for the searchable
		// dropdown in the replacement-map columns. Uses SORT_NATURAL +
		// SORT_FLAG_CASE so the dropdown reads the way a human would expect:
		// case-insensitive (so `core/` and `Core/` mix correctly), and
		// "image2" sorts after "image1" rather than between "image1" and
		// "image10" the way a plain ASCII sort would.
		$block_names = array();
		if ( class_exists( '\WP_Block_Type_Registry' ) ) {
			$registered_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
			$block_names       = array_keys( $registered_blocks );
			sort( $block_names, SORT_NATURAL | SORT_FLAG_CASE );
		}

		// Notices from action handlers. All inputs unslashed and clamped via
		// absint before composition; the message itself never contains user data.
		$scanned      = isset( $_GET['scanned'] ) ? absint( wp_unslash( $_GET['scanned'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect.
		$unique_count = isset( $_GET['unique'] ) ? absint( wp_unslash( $_GET['unique'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dual_count   = isset( $_GET['dual'] ) ? absint( wp_unslash( $_GET['dual'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset_flag   = isset( $_GET['reset'] ) ? absint( wp_unslash( $_GET['reset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Block MCP Settings', 'gk-block-api' ); ?></h1>

			<?php if ( $scanned ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
					echo esc_html(
						sprintf(
							/* translators: 1: total unique blocks, 2: dual-storage count */
							__( 'Storage-mode scan complete. %1$d unique blocks classified (%2$d dual-storage).', 'gk-block-api' ),
							$unique_count,
							$dual_count
						)
					);
				?>
				</p></div>
			<?php endif; ?>
			<?php if ( $reset_flag ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings reset to defaults.', 'gk-block-api' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'These settings drive how the Block MCP server classifies blocks (preferred / acceptable / avoid / legacy), suggests replacements, and detects dual-storage blocks that need both attributes and innerHTML on every update.', 'gk-block-api' ); ?></p>

			<style>
				/* Keep the Remove checkbox + label on a single line — the 80px
					column width was wrapping the label below the checkbox. */
				.gk-block-api-remove-label {
					white-space: nowrap;
					display: inline-flex;
					align-items: center;
					gap: 4px;
				}
				.gk-block-api-remove-label input[type="checkbox"] {
					margin: 0;
				}
			</style>

			<?php
			/*
			 * Live region for screen-reader announcements when the auto-grow
			 * JS appends a new blank row. Visually hidden via WP's standard
			 * .screen-reader-text class.
			 */
			?>
			<div id="gk-block-api-live" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>

			<datalist id="gk-block-names">
				<?php for ( $i = 0, $bn_count = count( $block_names ); $i < $bn_count; $i++ ) : ?>
					<option value="<?php echo esc_attr( $block_names[ $i ] ); ?>"></option>
				<?php endfor; ?>
			</datalist>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php esc_html_e( 'MCP server instructions', 'gk-block-api' ); ?></h2>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: link to MCP spec, 2: max length */
							__( 'Custom rules that every connected MCP client receives at handshake via <a href="%1$s" target="_blank" rel="noopener noreferrer">serverInfo.instructions</a>. Use it to encode site-specific conventions — callout className mapping, code-block theme, doc structure rules — so LLM agents don\'t have to re-discover them. Plain text up to %2$d characters; appended to the server\'s baseline.', 'gk-block-api' ),
							'https://modelcontextprotocol.io/specification',
							(int) $instructions_max
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
				<p class="description" style="color:#b32d2e;">
					<strong><?php esc_html_e( 'Public data:', 'gk-block-api' ); ?></strong>
					<?php esc_html_e( 'This value is served unauthenticated to every connected MCP client. Do NOT paste secrets, API keys, or internal URLs.', 'gk-block-api' ); ?>
				</p>
				<?php
				/*
				 * No HTML `maxlength` attribute. The browser counts UTF-16
				 * code units while the server counts UTF-8 code points
				 * (mb_strlen / Instructions::MAX_LENGTH), so a maxlength
				 * value of 2000 would block ~1000 emoji at the client
				 * even though the server would accept them. The inline
				 * JS below enforces a true code-point limit that matches
				 * the server's counter.
				 */
				?>
				<textarea
					id="gk-block-api-instructions"
					name="<?php echo esc_attr( Instructions::OPTION_KEY ); ?>"
					rows="8"
					data-max-codepoints="<?php echo esc_attr( (string) $instructions_max ); ?>"
					class="large-text code"
					placeholder="<?php esc_attr_e( "Callouts: use core/group with is-style-callout-info|warning|danger|success|note.\nCode blocks: use kevinbatdorf/code-block-pro with theme=gravitykit-dark, language=auto.\nFirst H2 of every doc should be 'Overview'.", 'gk-block-api' ); ?>"
				><?php echo esc_textarea( $instructions_val ); ?></textarea>
				<p class="description">
					<span id="gk-block-api-instructions-count"><?php echo esc_html( (string) mb_strlen( $instructions_val, 'UTF-8' ) ); ?></span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: max length */
							__( '/ %d characters used. Roughly 500 tokens at max length — keep it short and use concise bulleted rules rather than prose.', 'gk-block-api' ),
							(int) $instructions_max
						)
					);
					?>
				</p>
				<script>
				(function () {
					var ta    = document.getElementById('gk-block-api-instructions');
					var count = document.getElementById('gk-block-api-instructions-count');
					if (!ta || !count) return;
					var max = parseInt(ta.getAttribute('data-max-codepoints'), 10) || 0;

					// Count Unicode code points, not UTF-16 code units, so
					// astral characters (emoji, rare CJK, math symbols)
					// match the server's mb_strlen(...) tally.
					function codePoints(s) { return Array.from(s); }

					ta.addEventListener('input', function () {
						var cps = codePoints(ta.value);
						if (max > 0 && cps.length > max) {
							ta.value = cps.slice(0, max).join('');
							cps = codePoints(ta.value);
						}
						count.textContent = String(cps.length);
					});
				})();
				</script>

				<h2><?php esc_html_e( 'Namespace tier scores', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Score 0–100 per namespace. >= 80 = preferred, >= 50 = acceptable, >= 10 = avoid (warning), < 10 = legacy (hard reject on insert).', 'gk-block-api' ); ?></p>
				<table class="widefat striped gk-block-api-growable" data-row-prefix="gk_block_api_preferences[namespace_rows]" style="max-width: 700px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Namespace', 'gk-block-api' ); ?></th>
							<th scope="col" style="width: 90px;"><?php esc_html_e( 'Score', 'gk-block-api' ); ?></th>
							<th scope="col" style="width: 100px;"><?php esc_html_e( 'Remove', 'gk-block-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$ns_keys  = array_keys( $namespace_scores );
						$ns_count = count( $ns_keys );
						for ( $ns_index = 0; $ns_index < $ns_count; $ns_index++ ) :
							$ns    = $ns_keys[ $ns_index ];
							$score = $namespace_scores[ $ns ];
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="gk-ns-name-<?php echo esc_attr( (string) $ns_index ); ?>"><?php esc_html_e( 'Namespace', 'gk-block-api' ); ?></label>
									<input type="text" id="gk-ns-name-<?php echo esc_attr( (string) $ns_index ); ?>" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][name]" value="<?php echo esc_attr( (string) $ns ); ?>" class="regular-text" data-row-trigger="1" />
								</td>
								<td>
									<label class="screen-reader-text" for="gk-ns-score-<?php echo esc_attr( (string) $ns_index ); ?>"><?php esc_html_e( 'Score', 'gk-block-api' ); ?></label>
									<input type="number" id="gk-ns-score-<?php echo esc_attr( (string) $ns_index ); ?>" min="0" max="100" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][score]" value="<?php echo esc_attr( (string) (int) $score ); ?>" class="small-text" />
								</td>
								<td>
									<label class="gk-block-api-remove-label"><input type="checkbox" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][delete]" value="1" /> <?php esc_html_e( 'Remove', 'gk-block-api' ); ?></label>
								</td>
							</tr>
						<?php endfor; ?>
						<?php $ns_index = $ns_count; ?>
						<tr>
							<td>
								<label class="screen-reader-text" for="gk-ns-name-new"><?php esc_html_e( 'New namespace', 'gk-block-api' ); ?></label>
								<input type="text" id="gk-ns-name-new" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][name]" placeholder="<?php esc_attr_e( 'new-namespace', 'gk-block-api' ); ?>" class="regular-text" data-row-trigger="1" />
							</td>
							<td>
								<label class="screen-reader-text" for="gk-ns-score-new"><?php esc_html_e( 'New score', 'gk-block-api' ); ?></label>
								<input type="number" id="gk-ns-score-new" min="0" max="100" name="gk_block_api_preferences[namespace_rows][<?php echo esc_attr( (string) $ns_index ); ?>][score]" placeholder="0" class="small-text" />
							</td>
							<td></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Replacement map', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When a legacy block is rejected on insert, the error suggests its mapped replacement. The Replacement column shows a searchable list of all currently registered blocks on this site — type to filter, or enter any block name manually.', 'gk-block-api' ); ?></p>
				<table class="widefat striped gk-block-api-growable" data-row-prefix="gk_block_api_preferences[replacement_rows]" style="max-width: 800px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Legacy block', 'gk-block-api' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Replacement', 'gk-block-api' ); ?></th>
							<th scope="col" style="width: 100px;"><?php esc_html_e( 'Remove', 'gk-block-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rm_keys  = array_keys( $replacement_map );
						$rm_count = count( $rm_keys );
						for ( $rm_index = 0; $rm_index < $rm_count; $rm_index++ ) :
							$from = $rm_keys[ $rm_index ];
							$to   = $replacement_map[ $from ];
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="gk-rm-from-<?php echo esc_attr( (string) $rm_index ); ?>"><?php esc_html_e( 'Legacy block', 'gk-block-api' ); ?></label>
									<input type="text" id="gk-rm-from-<?php echo esc_attr( (string) $rm_index ); ?>" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][from]" value="<?php echo esc_attr( (string) $from ); ?>" class="regular-text" data-row-trigger="1" list="gk-block-names" autocomplete="off" />
								</td>
								<td>
									<label class="screen-reader-text" for="gk-rm-to-<?php echo esc_attr( (string) $rm_index ); ?>"><?php esc_html_e( 'Replacement block', 'gk-block-api' ); ?></label>
									<input type="text" id="gk-rm-to-<?php echo esc_attr( (string) $rm_index ); ?>" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][to]" value="<?php echo esc_attr( (string) $to ); ?>" class="regular-text" list="gk-block-names" autocomplete="off" />
								</td>
								<td>
									<label class="gk-block-api-remove-label"><input type="checkbox" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][delete]" value="1" /> <?php esc_html_e( 'Remove', 'gk-block-api' ); ?></label>
								</td>
							</tr>
						<?php endfor; ?>
						<?php $rm_index = $rm_count; ?>
						<tr>
							<td>
								<label class="screen-reader-text" for="gk-rm-from-new"><?php esc_html_e( 'New legacy block', 'gk-block-api' ); ?></label>
								<input type="text" id="gk-rm-from-new" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][from]" placeholder="<?php esc_attr_e( 'legacy/block-name', 'gk-block-api' ); ?>" class="regular-text" data-row-trigger="1" list="gk-block-names" autocomplete="off" />
							</td>
							<td>
								<label class="screen-reader-text" for="gk-rm-to-new"><?php esc_html_e( 'New replacement', 'gk-block-api' ); ?></label>
								<input type="text" id="gk-rm-to-new" name="gk_block_api_preferences[replacement_rows][<?php echo esc_attr( (string) $rm_index ); ?>][to]" placeholder="<?php esc_attr_e( 'core/block-name', 'gk-block-api' ); ?>" class="regular-text" list="gk-block-names" autocomplete="off" />
							</td>
							<td></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Blocks that store data in two places', 'gk-block-api' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Most blocks store their content in one place — either as block attributes (the JSON between the wp:block comments) or as innerHTML. A few blocks store the same data in BOTH at the same time. For these, updating one without the other corrupts the block silently.', 'gk-block-api' ); ?>
				</p>
				<p class="description">
					<?php
					echo wp_kses(
						__( 'A common example is <code>yoast/faq-block</code>: it keeps the questions in <code>attributes.questions</code> AND in the inner <code>&lt;dl&gt;</code> markup. If an agent updates only innerHTML, the structured questions array goes stale.', 'gk-block-api' ),
						array( 'code' => array() )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Block MCP detects most dual-storage blocks automatically by scanning your site. List any extras here (one per line) — when an agent tries to update one of these blocks, it will be required to send both attributes and innerHTML together, preventing the corruption.', 'gk-block-api' ); ?>
				</p>
				<?php $dual_placeholder = "yoast/faq-block\nnamespace/block-name"; ?>
				<textarea name="<?php echo esc_attr( self::DUAL_MANUAL_OPTION ); ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr( $dual_placeholder ); ?>"><?php echo esc_textarea( implode( "\n", $manual_dual ) ); ?></textarea>

				<h2><?php esc_html_e( 'Post types AI agents can create', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Restrict which post types the create_post MCP tool is allowed to use. Check the boxes for the types you want agents to be able to create. Leave everything unchecked to allow any public post type with REST support (the default).', 'gk-block-api' ); ?></p>
				<fieldset class="gk-block-api-allowlist">
					<?php
					$pt_slugs = array_keys( $registered_post_types );
					$pt_count = count( $pt_slugs );
					for ( $pt_idx = 0; $pt_idx < $pt_count; $pt_idx++ ) :
						$slug     = $pt_slugs[ $pt_idx ];
						$type_obj = $registered_post_types[ $slug ];
						?>
						<label>
							<input type="checkbox" name="gk_block_api_post_types_allowlist[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $post_type_allow, true ) ); ?> />
							<?php echo esc_html( $type_obj->labels->singular_name ); ?> <code><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endfor; ?>
				</fieldset>
				<style>
					.gk-block-api-allowlist {
						display: grid;
						grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
						gap: 8px 16px;
						max-width: 1000px;
						margin-top: 8px;
					}
					.gk-block-api-allowlist label {
						display: flex;
						align-items: center;
						gap: 6px;
						white-space: nowrap;
						overflow: hidden;
						text-overflow: ellipsis;
					}
				</style>

				<h2><?php esc_html_e( 'Media uploads', 'gk-block-api' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Master kill-switch for every MCP-driven upload path: multipart, URL sideload, base64. When disabled, the plugin refuses every upload with HTTP 403 before any disk I/O, DNS lookup, or HTTP fetch — useful if you want agents to edit blocks but not write to the media library.', 'gk-block-api' ); ?>
				</p>
				<?php
				// Belt-and-braces: emit '0' even when the box is unchecked so
				// update_option() reliably stores false. PHP omits unchecked
				// checkboxes entirely from $_POST, and the setting's
				// sanitize_callback would then receive nothing.
				?>
				<input type="hidden" name="<?php echo esc_attr( $uploads_option ); ?>" value="0" />
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( $uploads_option ); ?>"
						value="1"
						<?php checked( $uploads_enabled ); ?>
					/>
					<?php esc_html_e( 'Allow MCP agents to upload media', 'gk-block-api' ); ?>
				</label>
				<?php
				// Surface filter-driven overrides so admins aren't confused
				// by a checked box that the API still rejects.
				$option_raw = get_option( $uploads_option, '1' );
				$filtered   = (bool) apply_filters(
					'gk_block_api_uploads_enabled',
					( '0' !== (string) $option_raw && false !== $option_raw )
				);
				if ( ( '0' !== (string) $option_raw && false !== $option_raw ) !== $filtered ) :
					?>
					<p class="description" style="color:#b32d2e;">
						<strong><?php esc_html_e( 'Heads up:', 'gk-block-api' ); ?></strong>
						<?php
						printf(
							/* translators: %s: filter name */
							esc_html__( 'A %s filter is overriding the value of this option.', 'gk-block-api' ),
							'<code>gk_block_api_uploads_enabled</code>'
						);
						?>
					</p>
					<?php
				endif;
				?>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Storage-mode scan', 'gk-block-api' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Walks every published post and classifies each distinct block name as static / dynamic / dual. After running, get_page_blocks annotations and dual-storage enforcement use the live classification instead of the filter defaults. Slow on large sites.', 'gk-block-api' ); ?></p>
			<?php if ( ! empty( $scan_results ) ) : ?>
				<p><strong>
				<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of distinct block names persisted */
							__( 'Last scan classified %d distinct block name(s).', 'gk-block-api' ),
							count( $scan_results )
						)
					);
				?>
				</strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gk_block_api_scan_storage_modes" />
				<?php wp_nonce_field( 'gk_block_api_scan_storage_modes' ); ?>
				<?php submit_button( __( 'Run scan now', 'gk-block-api' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Reset to defaults', 'gk-block-api' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deletes all settings stored above (preferences, post-type allow-list, manual dual-storage list, scan results, inventory cache, and per-post rate-limit transients). The next read falls back to the hard-coded Preferences defaults.', 'gk-block-api' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all Block MCP settings? This cannot be undone.', 'gk-block-api' ) ); ?>');">
				<input type="hidden" name="action" value="gk_block_api_reset_defaults" />
				<?php wp_nonce_field( 'gk_block_api_reset_defaults' ); ?>
				<?php submit_button( __( 'Reset to defaults', 'gk-block-api' ), 'delete', 'submit', false ); ?>
			</form>

			<script>
			/* Auto-grow tables marked .gk-block-api-growable. When the user types
			 * into the last row's "trigger" input (data-row-trigger="1"), clone
			 * that row, blank out its values, and increment the [N] index in
			 * every input's name attribute so the form posts as a fresh entry.
			 * Announces the new row via the polite live region for screen readers. */
			(function () {
				var live = document.getElementById('gk-block-api-live');
				var announcement = <?php echo wp_json_encode( __( 'New row added. You can keep adding entries.', 'gk-block-api' ) ); ?>;

				function announce(msg) {
					if (!live) return;
					// Toggle text so the live region fires even if the message is identical.
					live.textContent = '';
					setTimeout(function () { live.textContent = msg; }, 50);
				}

				var tables = document.querySelectorAll('.gk-block-api-growable');
				for (var t = 0, tlen = tables.length; t < tlen; t++) {
					(function (table) {
						var tbody = table.querySelector('tbody');
						if (!tbody) return;

						var nextIdx = tbody.querySelectorAll('tr').length;

						tbody.addEventListener('input', function (e) {
							var trigger = e.target.closest('[data-row-trigger]');
							if (!trigger) return;
							var lastRow = tbody.lastElementChild;
							if (!lastRow || !lastRow.contains(trigger)) return;
							if (trigger.value === '') return;

							trigger.removeAttribute('data-row-trigger');

							var clone = lastRow.cloneNode(true);
							var idx = nextIdx++;
							var inputs = clone.querySelectorAll('input');
							for (var i = 0, ilen = inputs.length; i < ilen; i++) {
								var input = inputs[i];
								input.value = '';
								if (input.checked) input.checked = false;
								input.removeAttribute('id');
								if (input.name) {
									input.name = input.name.replace(/\[(\d+)\]/, '[' + idx + ']');
								}
							}

							var triggerCell = trigger.closest('td');
							if (triggerCell) {
								var cellIndex = Array.prototype.indexOf.call(triggerCell.parentNode.children, triggerCell);
								var newCell = clone.children[cellIndex];
								if (newCell) {
									var newTrigger = newCell.querySelector('input');
									if (newTrigger) newTrigger.setAttribute('data-row-trigger', '1');
								}
							}
							tbody.appendChild(clone);
							announce(announcement);
						});
					})(tables[t]);
				}
			})();
			</script>
		</div>
		<?php
	}
}
