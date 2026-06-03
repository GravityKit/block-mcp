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
// Yoast SEO loads here ONLY when GK_LOAD_YOAST=1, which tests/phpunit/yoast.xml
// sets for the dedicated Yoast bridge run. It is the sole consumer of a loaded
// Yoast. Loading Yoast in the general suite is harmful in two ways: its DB
// migrations issue CREATE INDEX statements the SQLite drop-in cannot execute,
// and its boot calls wp_is_block_theme() — which WordPress 6.8+ flags via
// _doing_it_wrong when the call lands inside a @runInSeparateProcess child,
// where CI's error_reporting=E_ALL turns the notice into a test failure.
// Scoping Yoast to its one run keeps every other process clean. The gate is an
// env var, not a constant, because PHPUnit re-runs this bootstrap in each
// separate-process child without inheriting parent constants — but the child
// process does inherit the parent's environment.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		// wp-phpunit empties $wp_theme_directories just before loading WordPress
		// and only repopulates it from its bundled test-theme directory when that
		// exists — the no-content build used here has none. Restore a real themes
		// path so core theme-directory functions don't fire _doing_it_wrong.
		if ( empty( $GLOBALS['wp_theme_directories'] ) ) {
			$GLOBALS['wp_theme_directories'] = array( WP_CONTENT_DIR . '/themes' );
		}

		$load_yoast = '1' === getenv( 'GK_LOAD_YOAST' );
		$yoast      = $plugin_root . '/vendor/wordpress/wordpress/wp-content/plugins/wordpress-seo/wp-seo.php';
		if ( $load_yoast && is_file( $yoast ) ) {
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
