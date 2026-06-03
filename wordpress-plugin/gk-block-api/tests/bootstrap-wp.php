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

// WordPress 6.8+ flags wp_is_block_theme() as called incorrectly when
// $wp_theme_directories is empty. wp-phpunit's bootstrap (above) resets that
// global and only repopulates it from its bundled test theme directory when
// that directory is present — in the no-content/dist test build it can be
// absent, leaving the global empty. A test-only dependency then trips the guard
// during the @runInSeparateProcess tests, and CI's error_reporting=E_ALL turns
// the notice into a failure (the PHPUnit convert setting is not inherited by
// separate-process children, so it cannot be suppressed there). gk-block-api
// never calls wp_is_block_theme. Guarantee a non-empty value after the bootstrap;
// WP_UnitTestCase does not reset this global, so it persists across the suite.
if ( empty( $GLOBALS['wp_theme_directories'] ) ) {
	$GLOBALS['wp_theme_directories'] = array( WP_CONTENT_DIR . '/themes' );
}

// Shared test base classes. Loaded AFTER wp-phpunit's bootstrap so
// WP_UnitTestCase is defined. RestControllerTestCase extends
// BlockApiTestCase, so order matters.
require __DIR__ . '/BlockApiTestCase.php';
require __DIR__ . '/RestControllerTestCase.php';
