<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * Provides just enough of the WP API surface to load and test
 * plugin classes without a full WordPress installation.
 *
 * @package GravityKit\BlockAPI\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

// Common WordPress time constants (referenced by Usage_Stats::CACHE_TTL, etc.).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ── WordPress function stubs ──

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) { return $data; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) { return strip_tags( $str ); }
}
if ( ! function_exists( 'has_shortcode' ) ) {
	function has_shortcode( $content, $tag ) { return false !== strpos( $content, "[$tag" ); }
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content ) { return $content; }
}

// Options storage for Preferences class.
$_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $_test_options;
		return isset( $_test_options[ $key ] ) ? $_test_options[ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		global $_test_options;
		$_test_options[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed =& $args;
		} else {
			parse_str( $args, $parsed );
		}
		return array_merge( $defaults, $parsed );
	}
}

// Stub WP_Block_Type_Registry for Block_Safety.
if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		private static $instance;
		private $types = array();

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_registered( $name ) {
			return isset( $this->types[ $name ] ) ? $this->types[ $name ] : null;
		}

		public function is_registered( $name ) {
			return isset( $this->types[ $name ] );
		}

		public function get_all_registered() {
			return $this->types;
		}

		public function register( $name, $args = array() ) {
			$type = new WP_Block_Type( $name, $args );
			$this->types[ $name ] = $type;
			return $type;
		}
	}
}

// ── Block parsing stubs ──
// Simplified parse_blocks / serialize_blocks: round-trip block arrays as JSON.
// Tests store pre-parsed block arrays as JSON in post_content; parse_blocks decodes
// them back into the same structure. This avoids needing the full WP block grammar.
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		if ( is_array( $content ) ) {
			return $content;
		}
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}
		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		return array();
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		return json_encode( $blocks );
	}
}
if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) {
		return json_encode( $block );
	}
}

// ── Post / revision stubs ──
$GLOBALS['_gk_test_posts'] = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		if ( isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			return $GLOBALS['_gk_test_posts'][ $id ];
		}
		return null;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		$id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;
		if ( $id > 0 && isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			if ( isset( $args['post_content'] ) ) {
				$GLOBALS['_gk_test_posts'][ $id ]->post_content = $args['post_content'];
			}
			if ( isset( $args['post_title'] ) ) {
				$GLOBALS['_gk_test_posts'][ $id ]->post_title = $args['post_title'];
			}
			return $id;
		}
		return 0;
	}
}
if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	function wp_get_post_revisions( $post_id, $args = array() ) {
		return array();
	}
}
if ( ! function_exists( 'setup_postdata' ) ) {
	function setup_postdata( $post ) { return true; }
}
if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata() { return true; }
}
if ( ! function_exists( 'render_block' ) ) {
	function render_block( $block ) { return isset( $block['innerHTML'] ) ? $block['innerHTML'] : ''; }
}

// ── Transient stubs (used by rate limiting) ──
$GLOBALS['_gk_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return isset( $GLOBALS['_gk_test_transients'][ $key ] ) ? $GLOBALS['_gk_test_transients'][ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['_gk_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['_gk_test_transients'][ $key ] );
		return true;
	}
}

// ── WP_Error ──
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! class_exists( 'WP_Block_Type' ) ) {
	class WP_Block_Type {
		public $name;
		public $render_callback;
		public $render_file;

		public function __construct( $name, $args = array() ) {
			$this->name            = $name;
			$this->render_callback = isset( $args['render_callback'] ) ? $args['render_callback'] : null;
			$this->render_file     = isset( $args['render_file'] ) ? $args['render_file'] : null;
		}

		public function is_dynamic() {
			return ! empty( $this->render_callback ) || ! empty( $this->render_file );
		}
	}
}

// Stub WP_HTML_Tag_Processor if not available.
if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	// We can't easily stub this — skip tests that need it.
}

// Autoload plugin classes.
spl_autoload_register( function ( $class ) {
	$prefix   = 'GravityKit\\BlockAPI\\';
	$base_dir = __DIR__ . '/../includes/';
	$len      = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}
	$relative = substr( $class, $len );
	$file     = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );
