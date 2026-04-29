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

	/** @var Block_Inventory */
	private $inventory;

	/**
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

		// Augment the dual-storage filter list with the UI-editable manual entries.
		add_filter( 'gk_block_api_dual_storage_blocks', array( $this, 'merge_manual_dual_list' ) );
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
		//    associative array; we sanitize sub-keys in the callback.
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
	}

	// ──────────────────────────────────────────────────────────────────
	// Sanitization callbacks.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Sanitize the namespace_scores + replacement_map sub-arrays.
	 *
	 * @param mixed $input Raw POST value.
	 * @return array
	 */
	public function sanitize_preferences( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$out = array();

		if ( isset( $input['namespace_scores'] ) && is_array( $input['namespace_scores'] ) ) {
			$out['namespace_scores'] = array();
			foreach ( $input['namespace_scores'] as $namespace => $score ) {
				$ns_clean = sanitize_key( $namespace );
				if ( '' === $ns_clean ) {
					continue;
				}
				$score_clean = max( 0, min( 100, (int) $score ) );
				$out['namespace_scores'][ $ns_clean ] = $score_clean;
			}
		}

		if ( isset( $input['replacement_map'] ) && is_array( $input['replacement_map'] ) ) {
			$out['replacement_map'] = array();
			foreach ( $input['replacement_map'] as $from => $to ) {
				$from_clean = $this->sanitize_block_name( $from );
				$to_clean   = $this->sanitize_block_name( $to );
				if ( '' !== $from_clean && '' !== $to_clean ) {
					$out['replacement_map'][ $from_clean ] = $to_clean;
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
	 * @param mixed $input
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
	 * @param string $name
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
	// Filter integration.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Merge the UI-editable manual list into the canonical dual-storage list.
	 *
	 * @param string[] $defaults
	 * @return string[]
	 */
	public function merge_manual_dual_list( $defaults ) {
		$manual = (array) get_option( self::DUAL_MANUAL_OPTION, array() );
		if ( empty( $manual ) ) {
			return $defaults;
		}
		return array_values( array_unique( array_merge( (array) $defaults, $manual ) ) );
	}

	// ──────────────────────────────────────────────────────────────────
	// Action handlers (admin-post.php).
	// ──────────────────────────────────────────────────────────────────

	/**
	 * "Re-scan storage modes" button handler.
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), 403 );
		}
		check_admin_referer( 'gk_block_api_scan_storage_modes' );

		$result = $this->inventory->scan_storage_modes();

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
	 * "Reset to defaults" button handler. Deletes all UI-managed options;
	 * the next read will fall back to the hard-coded Preferences defaults.
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gk-block-api' ), 403 );
		}
		check_admin_referer( 'gk_block_api_reset_defaults' );

		delete_option( 'gk_block_api_preferences' );
		delete_option( 'gk_block_api_post_types_allowlist' );
		delete_option( self::DUAL_MANUAL_OPTION );
		delete_option( Block_Inventory::STORAGE_MODES_OPTION );
		delete_transient( Block_Inventory::CACHE_KEY );

		$args = array(
			'page' => self::PAGE_SLUG,
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
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gk-block-api' ), 403 );
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

		$registered_post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Notices from action handlers.
		$scanned_notice = isset( $_GET['scanned'] ) ? sprintf(
			/* translators: 1: total unique blocks, 2: dual-storage count */
			esc_html__( 'Storage-mode scan complete. %1$d unique blocks classified (%2$d dual-storage).', 'gk-block-api' ),
			(int) ( $_GET['unique'] ?? 0 ),
			(int) ( $_GET['dual'] ?? 0 )
		) : '';
		$reset_notice = isset( $_GET['reset'] ) ? esc_html__( 'Settings reset to defaults.', 'gk-block-api' ) : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Block MCP Settings', 'gk-block-api' ); ?></h1>

			<?php if ( $scanned_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo $scanned_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already escaped above. ?></p></div>
			<?php endif; ?>
			<?php if ( $reset_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $reset_notice ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'These settings drive how the Block MCP server classifies blocks (preferred / acceptable / avoid / legacy), suggests replacements, and detects dual-storage blocks that need both attributes and innerHTML on every update.', 'gk-block-api' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php esc_html_e( 'Namespace tier scores', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Score 0–100 per namespace. >= 80 = preferred, >= 50 = acceptable, >= 10 = avoid (warning), < 10 = legacy (hard reject on insert).', 'gk-block-api' ); ?></p>
				<table class="widefat striped" style="max-width: 600px;">
					<thead><tr><th><?php esc_html_e( 'Namespace', 'gk-block-api' ); ?></th><th><?php esc_html_e( 'Score', 'gk-block-api' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $namespace_scores as $ns => $score ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ns ); ?>/</code><input type="hidden" name="gk_block_api_preferences[namespace_scores][<?php echo esc_attr( $ns ); ?>]" value="<?php echo esc_attr( $ns ); ?>" /></td>
								<td><input type="number" min="0" max="100" name="gk_block_api_preferences[namespace_scores][<?php echo esc_attr( $ns ); ?>]" value="<?php echo esc_attr( (string) $score ); ?>" class="small-text" /></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td><input type="text" name="gk_block_api_preferences[namespace_scores][__new_namespace]" placeholder="<?php esc_attr_e( 'new-namespace', 'gk-block-api' ); ?>" /></td>
							<td><input type="number" min="0" max="100" name="gk_block_api_preferences[namespace_scores][__new_score]" placeholder="0" class="small-text" /></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Replacement map', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When a legacy block is rejected on insert, the error suggests its mapped replacement. Format: legacy/block-name → preferred/block-name.', 'gk-block-api' ); ?></p>
				<table class="widefat striped" style="max-width: 700px;">
					<thead><tr><th><?php esc_html_e( 'Legacy block', 'gk-block-api' ); ?></th><th><?php esc_html_e( 'Replacement', 'gk-block-api' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $replacement_map as $from => $to ) : ?>
							<tr>
								<td><input type="text" name="gk_block_api_preferences[replacement_map][<?php echo esc_attr( $from ); ?>][from]" value="<?php echo esc_attr( $from ); ?>" /></td>
								<td><input type="text" name="gk_block_api_preferences[replacement_map][<?php echo esc_attr( $from ); ?>]" value="<?php echo esc_attr( $to ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Manual dual-storage blocks', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Block names (one per line) that should be treated as dual-storage in addition to whatever the site-scan and the gk_block_api_dual_storage_blocks filter contribute. Use this to force-classify a block before running a full scan.', 'gk-block-api' ); ?></p>
				<textarea name="<?php echo esc_attr( self::DUAL_MANUAL_OPTION ); ?>" rows="5" class="large-text code" placeholder="yoast/faq-block&#10;namespace/block"><?php echo esc_textarea( implode( "\n", $manual_dual ) ); ?></textarea>

				<h2><?php esc_html_e( 'create_post post-type allow-list', 'gk-block-api' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Limit which post types the create_post tool can create. Leave all unchecked to allow any public post type with REST support.', 'gk-block-api' ); ?></p>
				<fieldset>
					<?php foreach ( $registered_post_types as $slug => $type_obj ) : ?>
						<label style="margin-right: 16px;">
							<input type="checkbox" name="gk_block_api_post_types_allowlist[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $post_type_allow, true ) ); ?> />
							<?php echo esc_html( $type_obj->labels->singular_name ); ?> <code><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Storage-mode scan', 'gk-block-api' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Walks every published post and classifies each distinct block name as static / dynamic / dual. After running, get_page_blocks annotations and dual-storage enforcement use the live classification instead of the filter defaults. Slow on large sites.', 'gk-block-api' ); ?></p>
			<?php if ( ! empty( $scan_results ) ) : ?>
				<p><strong><?php
					/* translators: %d: number of distinct block names persisted */
					echo esc_html( sprintf( __( 'Last scan classified %d distinct block name(s).', 'gk-block-api' ), count( $scan_results ) ) );
				?></strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gk_block_api_scan_storage_modes" />
				<?php wp_nonce_field( 'gk_block_api_scan_storage_modes' ); ?>
				<?php submit_button( __( 'Run scan now', 'gk-block-api' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Reset to defaults', 'gk-block-api' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deletes all settings stored above (preferences, post-type allow-list, manual dual-storage list, scan results, inventory cache). The next read falls back to the hard-coded Preferences defaults.', 'gk-block-api' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all Block MCP settings? This cannot be undone.', 'gk-block-api' ) ); ?>');">
				<input type="hidden" name="action" value="gk_block_api_reset_defaults" />
				<?php wp_nonce_field( 'gk_block_api_reset_defaults' ); ?>
				<?php submit_button( __( 'Reset to defaults', 'gk-block-api' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
