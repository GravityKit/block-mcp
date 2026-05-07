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

// Common WordPress time constants (referenced by Block_Inventory::CACHE_TTL, etc.).
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
if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $data, $scheme = 'auth' ) {
		// Mirror WP's HMAC behavior with a deterministic salt for tests.
		// Production uses wp_salt(), but tests just need a stable, unique-input → unique-output hash.
		unset( $scheme );
		return hash_hmac( 'md5', (string) $data, 'gk-test-salt' );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		if ( 0 === $min && 0 === $max ) {
			return mt_rand();
		}
		return mt_rand( $min, $max );
	}
}
if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $post_id ) {
		unset( $post_id );
	}
}
// Minimal in-memory object cache used by the ref-assignment lock. The lock
// only needs add/delete; we don't simulate the TTL because tests live within
// a single process invocation.
$GLOBALS['_gk_test_object_cache'] = array();
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		unset( $group, $expire );
		if ( isset( $GLOBALS['_gk_test_object_cache'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['_gk_test_object_cache'][ $key ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $group );
		if ( ! isset( $GLOBALS['_gk_test_object_cache'][ $key ] ) ) {
			return false;
		}
		unset( $GLOBALS['_gk_test_object_cache'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $post_id ) {
		if ( ! isset( $GLOBALS['_gk_test_posts'][ $post_id ] ) ) {
			return '';
		}
		$post = $GLOBALS['_gk_test_posts'][ $post_id ];
		return isset( $post->{$field} ) ? $post->{$field} : '';
	}
}
// Mirror WordPress's wp_slash/wp_unslash so the test environment behaves like
// real WP — strings are addslashes'd, arrays/objects walked recursively. Using
// identity stubs would let slashing bugs slip through (e.g., save_post_content
// passes wp_slash'd content to wp_update_post; reading it back without an
// unslash would otherwise look fine in tests but break in production).
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = wp_slash( $v );
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = wp_unslash( $v );
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}
// Stub $wpdb. persist_ref_assignments uses $wpdb->update() to write content
// without triggering revisions. The stub mutates _gk_test_posts directly.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public $posts = 'wp_posts';
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			unset( $table, $format, $where_format );
			if ( ! isset( $where['ID'] ) ) {
				return false;
			}
			$id = (int) $where['ID'];
			if ( ! isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
				return false;
			}
			foreach ( $data as $field => $value ) {
				$GLOBALS['_gk_test_posts'][ $id ]->{$field} = $value;
			}
			return 1;
		}
	};
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { unset( $domain ); return $text; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
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
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) { unset( $tag, $args ); }
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
		unset( $wp_error );
		$id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;
		if ( $id <= 0 || ! isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			return 0;
		}
		$post = $GLOBALS['_gk_test_posts'][ $id ];
		// Real WP unslashes string fields once before storage. Mirror that so
		// the wp_slash → wp_update_post → get_post round trip is faithful.
		foreach ( array(
			'post_title',
			'post_content',
			'post_excerpt',
			'post_status',
			'post_name',
			'post_parent',
			'post_date',
			'menu_order',
			'comment_status',
			'ping_status',
			'post_author',
			'post_mime_type',
		) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$value = $args[ $field ];
				if ( is_string( $value ) ) {
					$value = stripslashes( $value );
				}
				$post->{$field} = $value;
			}
		}
		$GLOBALS['_gk_test_posts'][ $id ] = $post;
		return $id;
	}
}
if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	// Backed by $GLOBALS['_gk_test_revisions'][ $post_id ] = array of revision IDs
	// (newest first). Tests that care about the optimistic-concurrency path
	// seed this directly; default behavior is "no revisions yet".
	function wp_get_post_revisions( $post_id, $args = array() ) {
		unset( $args );
		$ids = isset( $GLOBALS['_gk_test_revisions'][ $post_id ] )
			? $GLOBALS['_gk_test_revisions'][ $post_id ]
			: array();
		$out = array();
		foreach ( $ids as $rev_id ) {
			$out[ $rev_id ] = (object) array( 'ID' => $rev_id );
		}
		return $out;
	}
}
if ( ! function_exists( 'setup_postdata' ) ) {
	function setup_postdata( $post ) { unset( $post ); return true; }
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
		unset( $expiration );
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

// Functions WP_HTML_Tag_Processor depends on.
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		// No-op in tests.
		unset( $function_name, $message, $version );
	}
}
if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	function wp_kses_uri_attributes() {
		return array(
			'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data',
			'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile',
			'src', 'usemap', 'xmlns', 'xlink:href',
		);
	}
}

// Load the vendored WordPress HTML API (see tests/wp-html-api/README.md).
// These files are required by HtmlTransformerTest. Outside a real WP install,
// they're loaded from the vendor directory so Block_CRUD's auto-transform code
// paths can be exercised without skipping.
if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	$_gk_html_api_dir = __DIR__ . '/wp-html-api';
	if ( is_dir( $_gk_html_api_dir ) ) {
		require_once $_gk_html_api_dir . '/class-wp-html-attribute-token.php';
		require_once $_gk_html_api_dir . '/class-wp-html-span.php';
		require_once $_gk_html_api_dir . '/class-wp-html-text-replacement.php';
		require_once $_gk_html_api_dir . '/class-wp-html-tag-processor.php';
	}
}

// ── Stubs for Post_Manager / Term_Manager / Media_Manager tests ──
//
// These additional stubs cover the WordPress functions touched by the
// v1.2 lifecycle managers. The stub layer focuses on validation/error
// paths; real WP integration is proved by the gkclone E2E smoke
// (scripts/e2e-gkclone.mjs). Issue #2 tracks moving to wp-env-based
// PHPUnit for full integration coverage.

// Capabilities — controllable per test via $GLOBALS['_gk_test_caps'].
// Default: every cap granted. Tests denying a cap set the array key to false.
$GLOBALS['_gk_test_caps']    = array();
$GLOBALS['_gk_test_user_id'] = 1;

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, ...$args ) {
		unset( $args );
		if ( array_key_exists( $cap, $GLOBALS['_gk_test_caps'] ) ) {
			return (bool) $GLOBALS['_gk_test_caps'][ $cap ];
		}
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['_gk_test_user_id'];
	}
}

// Post types — register via $GLOBALS['_gk_test_post_types'].
$GLOBALS['_gk_test_post_types'] = array(
	'post' => (object) array(
		'name'         => 'post',
		'hierarchical' => false,
		'show_in_rest' => true,
		'cap'          => (object) array(
			'create_posts'      => 'edit_posts',
			'edit_posts'        => 'edit_posts',
			'edit_post'         => 'edit_post',
			'publish_posts'     => 'publish_posts',
			'edit_others_posts' => 'edit_others_posts',
		),
	),
	'page' => (object) array(
		'name'         => 'page',
		'hierarchical' => true,
		'show_in_rest' => true,
		'cap'          => (object) array(
			'create_posts'      => 'edit_pages',
			'edit_posts'        => 'edit_pages',
			'edit_post'         => 'edit_page',
			'publish_posts'     => 'publish_pages',
			'edit_others_posts' => 'edit_others_pages',
		),
	),
);

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $type ) {
		return isset( $GLOBALS['_gk_test_post_types'][ $type ] )
			? $GLOBALS['_gk_test_post_types'][ $type ]
			: null;
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names' ) {
		$results = array();
		foreach ( $GLOBALS['_gk_test_post_types'] as $name => $obj ) {
			$match = true;
			foreach ( $args as $arg_key => $arg_value ) {
				if ( ! isset( $obj->{$arg_key} ) || $obj->{$arg_key} !== $arg_value ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				$results[ $name ] = $obj;
			}
		}
		return 'names' === $output ? array_keys( $results ) : $results;
	}
}

// Post insert / update — extend the existing $_gk_test_posts store.
$GLOBALS['_gk_test_next_post_id'] = 1000;

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args, $wp_error = false ) {
		unset( $wp_error );
		$id = ++$GLOBALS['_gk_test_next_post_id'];

		$post                 = new \stdClass();
		$post->ID             = $id;
		$post->post_type      = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		$post->post_status    = isset( $args['post_status'] ) ? $args['post_status'] : 'draft';
		$post->post_title     = isset( $args['post_title'] ) ? $args['post_title'] : '';
		$post->post_content   = isset( $args['post_content'] ) ? $args['post_content'] : '';
		$post->post_excerpt   = isset( $args['post_excerpt'] ) ? $args['post_excerpt'] : '';
		$post->post_name      = isset( $args['post_name'] ) ? $args['post_name'] : sanitize_title( $post->post_title );
		$post->post_parent    = isset( $args['post_parent'] ) ? (int) $args['post_parent'] : 0;
		$post->post_date      = isset( $args['post_date'] ) ? $args['post_date'] : '2026-01-01 00:00:00';
		$post->menu_order     = isset( $args['menu_order'] ) ? (int) $args['menu_order'] : 0;
		$post->comment_status = isset( $args['comment_status'] ) ? $args['comment_status'] : 'closed';
		$post->ping_status    = isset( $args['ping_status'] ) ? $args['ping_status'] : 'closed';
		$post->post_author    = isset( $args['post_author'] ) ? (int) $args['post_author'] : get_current_user_id();
		$post->post_mime_type = isset( $args['post_mime_type'] ) ? $args['post_mime_type'] : '';

		$GLOBALS['_gk_test_posts'][ $id ] = $post;
		return $id;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) {
		unset( $force );
		if ( isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			unset( $GLOBALS['_gk_test_posts'][ $id ] );
			return true;
		}
		return false;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $id ) {
		if ( ! isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			return false;
		}
		$GLOBALS['_gk_test_posts'][ $id ]->_pre_trash_status = $GLOBALS['_gk_test_posts'][ $id ]->post_status;
		$GLOBALS['_gk_test_posts'][ $id ]->post_status       = 'trash';
		return $GLOBALS['_gk_test_posts'][ $id ];
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( $id ) {
		if ( ! isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			return false;
		}
		$prior = isset( $GLOBALS['_gk_test_posts'][ $id ]->_pre_trash_status )
			? $GLOBALS['_gk_test_posts'][ $id ]->_pre_trash_status
			: 'draft';
		$GLOBALS['_gk_test_posts'][ $id ]->post_status = $prior;
		return $GLOBALS['_gk_test_posts'][ $id ];
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ) {
		return isset( $GLOBALS['_gk_test_posts'][ $id ] )
			? $GLOBALS['_gk_test_posts'][ $id ]->post_mime_type
			: '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post, $context = 'display' ) {
		$id  = is_object( $post ) ? $post->ID : (int) $post;
		$url = 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
		// WordPress escapes ampersands in 'display' context; raw otherwise.
		return 'display' === $context ? str_replace( '&', '&amp;', $url ) : $url;
	}
}

// Featured image — store as post meta.
$GLOBALS['_gk_test_post_meta'] = array();

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post_id, $thumbnail_id ) {
		$GLOBALS['_gk_test_post_meta'][ $post_id ]['_thumbnail_id'] = (int) $thumbnail_id;
		return true;
	}
}
if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( $post_id ) {
		unset( $GLOBALS['_gk_test_post_meta'][ $post_id ]['_thumbnail_id'] );
		return true;
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		return isset( $GLOBALS['_gk_test_post_meta'][ $post_id ]['_thumbnail_id'] )
			? $GLOBALS['_gk_test_post_meta'][ $post_id ]['_thumbnail_id']
			: 0;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return isset( $GLOBALS['_gk_test_post_meta'][ $post_id ] ) ? $GLOBALS['_gk_test_post_meta'][ $post_id ] : array();
		}
		$value = isset( $GLOBALS['_gk_test_post_meta'][ $post_id ][ $key ] )
			? $GLOBALS['_gk_test_post_meta'][ $post_id ][ $key ]
			: '';
		return $single ? $value : ( '' === $value ? array() : array( $value ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['_gk_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $value );
		if ( isset( $GLOBALS['_gk_test_post_meta'][ $post_id ][ $key ] ) ) {
			unset( $GLOBALS['_gk_test_post_meta'][ $post_id ][ $key ] );
			return true;
		}
		return false;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		if ( isset( $GLOBALS['_gk_test_posts'][ $id ] ) ) {
			return $GLOBALS['_gk_test_posts'][ $id ]->post_type;
		}
		return 'post';
	}
}

// Taxonomies and terms.
$GLOBALS['_gk_test_taxonomies'] = array(
	'category' => array( 'object_types' => array( 'post' ), 'hierarchical' => true ),
	'post_tag' => array( 'object_types' => array( 'post' ), 'hierarchical' => false ),
);
$GLOBALS['_gk_test_terms']        = array(); // term_id => WP_Term-like object
$GLOBALS['_gk_test_post_terms']   = array(); // post_id => taxonomy => [term_ids]
$GLOBALS['_gk_test_next_term_id'] = 1;

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return isset( $GLOBALS['_gk_test_taxonomies'][ $taxonomy ] );
	}
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object_type, $output = 'names' ) {
		$type    = is_string( $object_type ) ? $object_type : ( isset( $object_type->post_type ) ? $object_type->post_type : '' );
		$objects = array();
		foreach ( $GLOBALS['_gk_test_taxonomies'] as $tax => $cfg ) {
			if ( in_array( $type, $cfg['object_types'], true ) ) {
				$objects[ $tax ] = (object) array_merge( array( 'name' => $tax ), $cfg );
			}
		}
		return 'names' === $output ? array_keys( $objects ) : $objects;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $name;
		public $slug;
		public $description = '';
		public $parent      = 0;
		public $count       = 0;
		public $taxonomy;

		public function __construct( $args ) {
			foreach ( $args as $k => $v ) {
				$this->{$k} = $v;
			}
		}
	}
}

if ( ! function_exists( '_gk_test_make_term' ) ) {
	function _gk_test_make_term( $taxonomy, $name, $extra = array() ) {
		$id = ++$GLOBALS['_gk_test_next_term_id'];
		$term = new WP_Term(
			array_merge(
				array(
					'term_id'  => $id,
					'name'     => $name,
					'slug'     => sanitize_title( $name ),
					'taxonomy' => $taxonomy,
				),
				$extra
			)
		);
		$GLOBALS['_gk_test_terms'][ $id ] = $term;
		return $term;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( ! isset( $GLOBALS['_gk_test_terms'][ $term_id ] ) ) {
			return null;
		}
		$term = $GLOBALS['_gk_test_terms'][ $term_id ];
		if ( $taxonomy && $term->taxonomy !== $taxonomy ) {
			return null;
		}
		return $term;
	}
}

if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		$slug = is_object( $term ) ? $term->slug : (string) $term;
		return 'https://example.test/?term=' . $slug;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
		if ( $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', 'invalid' );
		}
		$results = array();
		foreach ( $GLOBALS['_gk_test_terms'] as $term ) {
			if ( $taxonomy && $term->taxonomy !== $taxonomy ) {
				continue;
			}
			if ( isset( $args['parent'] ) && (int) $term->parent !== (int) $args['parent'] ) {
				continue;
			}
			if ( ! empty( $args['hide_empty'] ) && (int) $term->count <= 0 ) {
				continue;
			}
			if ( ! empty( $args['search'] ) && false === stripos( $term->name, $args['search'] ) ) {
				continue;
			}
			if ( ! empty( $args['slug'] ) && $term->slug !== $args['slug'] ) {
				continue;
			}
			if ( ! empty( $args['include'] ) && ! in_array( (int) $term->term_id, array_map( 'intval', $args['include'] ), true ) ) {
				continue;
			}
			$results[] = $term;
		}

		$orderby = isset( $args['orderby'] ) ? $args['orderby'] : 'name';
		$order   = isset( $args['order'] ) && 'DESC' === strtoupper( $args['order'] ) ? -1 : 1;
		usort(
			$results,
			function ( $a, $b ) use ( $orderby, $order ) {
				$av = isset( $a->{$orderby} ) ? $a->{$orderby} : ( 'name' === $orderby ? $a->name : 0 );
				$bv = isset( $b->{$orderby} ) ? $b->{$orderby} : ( 'name' === $orderby ? $b->name : 0 );
				if ( is_numeric( $av ) && is_numeric( $bv ) ) {
					return ( $av <=> $bv ) * $order;
				}
				return strcasecmp( (string) $av, (string) $bv ) * $order;
			}
		);

		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$number = isset( $args['number'] ) ? (int) $args['number'] : 0;
		if ( $number > 0 ) {
			$results = array_slice( $results, $offset, $number );
		} elseif ( $offset > 0 ) {
			$results = array_slice( $results, $offset );
		}
		return $results;
	}
}

if ( ! function_exists( 'wp_count_terms' ) ) {
	function wp_count_terms( $args = array() ) {
		$count_args = $args;
		unset( $count_args['number'], $count_args['offset'] );
		return count( get_terms( $count_args ) );
	}
}

if ( ! function_exists( 'wp_set_post_terms' ) ) {
	function wp_set_post_terms( $post_id, $terms = array(), $taxonomy = 'post_tag', $append = false ) {
		$terms = (array) $terms;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', 'invalid' );
		}
		$existing = isset( $GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ] )
			? $GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ]
			: array();
		$ids = array_map( 'intval', $terms );
		$GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ] = $append ? array_unique( array_merge( $existing, $ids ) ) : $ids;
		return $GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ];
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy = 'post_tag', $args = array() ) {
		$ids = isset( $GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ] )
			? $GLOBALS['_gk_test_post_terms'][ $post_id ][ $taxonomy ]
			: array();
		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return $ids;
		}
		return array_map(
			function ( $id ) {
				return isset( $GLOBALS['_gk_test_terms'][ $id ] ) ? $GLOBALS['_gk_test_terms'][ $id ] : null;
			},
			$ids
		);
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( strip_tags( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9\s-]/', '', $title );
		$title = preg_replace( '/[\s-]+/', '-', $title );
		return trim( $title, '-' );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$filename = strip_tags( (string) $filename );
		$filename = preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $filename );
		return trim( $filename, '-' );
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}
}

// ── Media stubs ──
//
// These cover the validation-path tests for Media_Manager. The actual
// upload happy-path is exercised end-to-end against gkclone — see issue #2
// for moving to wp-env-based PHPUnit so we can test with real WP media.

$GLOBALS['_gk_test_media_allowed_mimes'] = array(
	'png'      => 'image/png',
	'jpg|jpeg' => 'image/jpeg',
	'gif'      => 'image/gif',
	'webp'     => 'image/webp',
	'svg'      => 'image/svg+xml',
);
$GLOBALS['_gk_test_max_upload_size']     = 26214400; // 25 MB
$GLOBALS['_gk_test_url_responses']       = array(); // url => filepath OR WP_Error

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return is_string( $url ) ? trim( $url ) : '';
	}
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? $url : false;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return (int) $GLOBALS['_gk_test_max_upload_size'];
	}
}

// Tests can prime DNS resolution so the Media_Manager SSRF guard is
// deterministic. Maps host → IP. Hosts not in the map fall through to PHP's
// built-in resolver, which on most CI hosts returns the host unchanged on
// failure (causing the SSRF guard to reject — appropriate for genuine
// invalid_url tests).
$GLOBALS['_gk_test_dns'] = array();
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '' ) {
		$tmp = tempnam( sys_get_temp_dir(), 'gkbla' );
		if ( $tmp && $filename ) {
			$dest = $tmp . '-' . sanitize_file_name( $filename );
			rename( $tmp, $dest );
			return $dest;
		}
		return $tmp;
	}
}
if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( $file, $filename ) {
		// $file (the temp path) ignored in stubs — real WP reads file contents
		// to verify magic bytes match the extension. Tests trust the extension.
		unset( $file );
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		foreach ( $GLOBALS['_gk_test_media_allowed_mimes'] as $exts => $mime ) {
			foreach ( explode( '|', $exts ) as $allowed ) {
				if ( $allowed === $ext ) {
					return array( 'ext' => $ext, 'type' => $mime, 'proper_filename' => $filename );
				}
			}
		}
		return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
	}
}
if ( ! function_exists( 'download_url' ) ) {
	function download_url( $url, $timeout = 300 ) {
		unset( $timeout );
		if ( isset( $GLOBALS['_gk_test_url_responses'][ $url ] ) ) {
			$resp = $GLOBALS['_gk_test_url_responses'][ $url ];
			return $resp;
		}
		return new \WP_Error( 'http_404', 'No fixture for URL: ' . $url );
	}
}
if ( ! function_exists( 'media_handle_sideload' ) ) {
	function media_handle_sideload( $file, $post_id = 0, $desc = null, $post_data = array() ) {
		unset( $desc, $post_data );
		$mime = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $mime['type'] ) ) {
			return new \WP_Error( 'invalid_mime', 'invalid' );
		}
		$id = wp_insert_post( array(
			'post_type'      => 'attachment',
			'post_title'     => pathinfo( $file['name'], PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'post_parent'    => (int) $post_id,
			'post_mime_type' => $mime['type'],
		) );
		// Track the source path for get_attached_file.
		$GLOBALS['_gk_test_attached_files'][ $id ] = $file['tmp_name'];
		// Synthetic image metadata for image MIMEs.
		if ( 0 === strpos( $mime['type'], 'image/' ) ) {
			$GLOBALS['_gk_test_attachment_meta'][ $id ] = array(
				'width'  => 1,
				'height' => 1,
				'file'   => $file['name'],
				'sizes'  => array(),
			);
		}
		return $id;
	}
}
if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( $file_id, $post_id = 0, $post_data = array(), $overrides = array() ) {
		unset( $post_data, $overrides );
		if ( ! isset( $_FILES[ $file_id ] ) ) {
			return new \WP_Error( 'no_file', 'no file in $_FILES' );
		}
		$file = $_FILES[ $file_id ];
		return media_handle_sideload( $file, $post_id );
	}
}
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id ) {
		return isset( $GLOBALS['_gk_test_attachment_meta'][ $attachment_id ] )
			? $GLOBALS['_gk_test_attachment_meta'][ $attachment_id ]
			: array();
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return '';
		}
		return 'https://example.test/wp-content/uploads/' . $post->post_title . '.bin';
	}
}
if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
		unset( $size );
		$url = wp_get_attachment_url( $attachment_id );
		return $url ? array( $url, 1, 1 ) : false;
	}
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return isset( $GLOBALS['_gk_test_attached_files'][ $attachment_id ] )
			? $GLOBALS['_gk_test_attached_files'][ $attachment_id ]
			: '';
	}
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
