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
		$yoast = $plugin_root . '/vendor/wordpress/wordpress/wp-content/plugins/wordpress-seo/wp-seo.php';
		if ( is_file( $yoast ) ) {
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
