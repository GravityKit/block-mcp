<?php
/**
 * PHPUnit bootstrap for gk-block-api against real WordPress.
 *
 * Boots wp-phpunit (which itself loads WordPress with the SQLite drop-in
 * installed by tests/install-sqlite-dropin.php) and registers the plugin
 * so its hooks/classes load before tests run.
 */

declare( strict_types=1 );

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/vendor/autoload.php';

$_tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';

// tests_add_filter() comes from wp-phpunit/includes/functions.php.
require_once $_tests_dir . '/includes/functions.php';

// Tell wp-phpunit where our wp-tests-config.php lives.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

// Load the plugin once muplugins_loaded fires, ahead of WP's normal plugin
// boot — at this point all WP APIs are available but tests haven't started.
//
// Yoast SEO is loaded BEFORE gk-block-api so the bridge's REST routes
// register correctly and Yoast's meta-key contracts are in place when
// Yoast_Bridge writes to them in tests/Integrations/YoastBridgeTest.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		// WordPress 6.8+ flags wp_is_block_theme() as "called incorrectly" when
		// $wp_theme_directories is still empty (theme.php). A test-only dependency
		// (Yoast, loaded below) calls it during plugin load — before wp-phpunit
		// registers its test theme directory — and under error_reporting=E_ALL that
		// E_USER notice fails the @runInSeparateProcess tests. The test build is a
		// no-content WordPress, so WP_CONTENT_DIR/themes does not exist; register
		// wp-phpunit's bundled test theme directory (which does exist, so the
		// registration takes) up front to satisfy the guard. gk-block-api never
		// calls wp_is_block_theme itself. register_theme_directory de-dupes, so
		// wp-phpunit registering the same path later is a harmless no-op.
		$test_theme_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit/data/themedir1';
		if ( function_exists( 'register_theme_directory' ) && is_dir( $test_theme_dir ) ) {
			register_theme_directory( $test_theme_dir );
		}

		// Yoast is only needed for the single-site Yoast_Bridge integration tests.
		// Skip it under multisite: that config runs only the ms-required group (no
		// Yoast tests), and loading Yoast there triggers per-blog Yoast migrations
		// whose CREATE INDEX statements the SQLite drop-in cannot run, flooding the
		// output with non-fatal print_error noise that would mask real failures.
		$is_multisite_run = defined( 'WP_TESTS_MULTISITE' ) && WP_TESTS_MULTISITE;
		$yoast            = $plugin_root . '/vendor/wordpress/wordpress/wp-content/plugins/wordpress-seo/wp-seo.php';
		if ( ! $is_multisite_run && is_file( $yoast ) ) {
			require $yoast;
		}
		require dirname( __DIR__ ) . '/gk-block-api.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// Shared test base classes. Loaded AFTER wp-phpunit's bootstrap so
// WP_UnitTestCase is defined. RestControllerTestCase extends
// BlockApiTestCase, so order matters.
require __DIR__ . '/BlockApiTestCase.php';
require __DIR__ . '/RestControllerTestCase.php';
