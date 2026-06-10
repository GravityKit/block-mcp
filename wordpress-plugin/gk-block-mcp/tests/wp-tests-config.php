<?php
/**
 * wp-phpunit test environment configuration.
 *
 * SQLite backs wpdb via the sqlite-database-integration drop-in installed by
 * tests/install-sqlite-dropin.php, so the DB_* constants below are inert —
 * the drop-in ignores them and writes to a file under WP_CONTENT_DIR.
 */

$plugin_root = dirname( __DIR__ );

// Register a real theme root before WordPress loads. The wp-phpunit install
// subprocess (includes/install.php) loads this config and then wp-settings.php,
// which fires after_setup_theme -> _add_default_theme_supports() ->
// wp_is_block_theme() BEFORE it registers any test theme directory. The
// no-content WordPress build ships no wp-content/themes, so without this the
// global is empty there and wp_is_block_theme() trips its _doing_it_wrong guard
// — fatal under CI's error_reporting=E_ALL inside process-isolated tests.
// Pointing at the theme fixtures wp-phpunit ships (which include the
// WP_DEFAULT_THEME 'default' theme) keeps the global non-empty and resolvable in
// every process and subprocess. The normal wp-phpunit bootstrap repopulates this
// the same way; this covers the install subprocess, which it does not.
$tests_theme_root = realpath( $plugin_root . '/vendor/wp-phpunit/wp-phpunit/data/themedir1' );
if ( false !== $tests_theme_root ) {
	$GLOBALS['wp_theme_directories'] = array( $tests_theme_root );
}

define( 'ABSPATH',         $plugin_root . '/vendor/wordpress/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL',  'admin@example.org' );
define( 'WP_TESTS_TITLE',  'Test Blog' );
define( 'WP_PHP_BINARY',   'php' );
define( 'WPLANG',          '' );

// SQLite uses a single file under wp-content; the constants below satisfy
// wpdb's expectations without ever being read by MySQL.
define( 'DB_NAME',     'wordpress_test' );
define( 'DB_USER',     'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST',     'localhost' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_DISPLAY', true );

// Keep GravityKit Foundation out of the test boot. The plugin's main file gates
// its Foundation preflight + Core::register on this constant; defining it here
// means the SQLite-backed harness never loads Foundation's admin / licensing /
// remote subsystems, which break the drop-in: incompatible SQL, _doing_it_wrong
// notices that CI's E_ALL turns into failures, and remote HTTP.
define( 'GK_BLOCK_MCP_DISABLE_FOUNDATION', true );

// Salts — wp-phpunit's bootstrap requires these to be defined.
define( 'AUTH_KEY',         'test-auth-key' );
define( 'SECURE_AUTH_KEY',  'test-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'test-logged-in-key' );
define( 'NONCE_KEY',        'test-nonce-key' );
define( 'AUTH_SALT',        'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'test-logged-in-salt' );
define( 'NONCE_SALT',       'test-nonce-salt' );
