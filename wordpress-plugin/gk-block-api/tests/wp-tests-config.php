<?php
/**
 * wp-phpunit test environment configuration.
 *
 * SQLite backs wpdb via the sqlite-database-integration drop-in installed by
 * tests/install-sqlite-dropin.php, so the DB_* constants below are inert —
 * the drop-in ignores them and writes to a file under WP_CONTENT_DIR.
 */

$plugin_root = dirname( __DIR__ );

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

// Salts — wp-phpunit's bootstrap requires these to be defined.
define( 'AUTH_KEY',         'test-auth-key' );
define( 'SECURE_AUTH_KEY',  'test-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'test-logged-in-key' );
define( 'NONCE_KEY',        'test-nonce-key' );
define( 'AUTH_SALT',        'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'test-logged-in-salt' );
define( 'NONCE_SALT',       'test-nonce-salt' );
