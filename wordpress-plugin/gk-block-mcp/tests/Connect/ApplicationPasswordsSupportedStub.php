<?php
/**
 * Namespace-scoped stub for wp_is_application_passwords_supported().
 *
 * wp_get_environment_type() caches its result in a function-static on its
 * first call, which happens during WP's own bootstrap before any test runs,
 * so no test in this process can make it report 'local'. PHP resolves an
 * unqualified function call to a function in the calling namespace before
 * falling back to the global one; Connect_Page::connection_state() is the
 * only production call site for wp_is_application_passwords_supported(), so
 * defining it here shadows core's version for that call site only, and
 * transparently proxies to core unless a test opts in via
 * $GLOBALS['GK_TEST_APP_PASSWORDS_SUPPORTED_OVERRIDE'].
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

namespace GravityKit\BlockMCP;

if ( ! function_exists( __NAMESPACE__ . '\wp_is_application_passwords_supported' ) ) {
	/**
	 * @return bool
	 */
	function wp_is_application_passwords_supported() {
		if ( array_key_exists( 'GK_TEST_APP_PASSWORDS_SUPPORTED_OVERRIDE', $GLOBALS ) ) {
			return $GLOBALS['GK_TEST_APP_PASSWORDS_SUPPORTED_OVERRIDE'];
		}

		return \wp_is_application_passwords_supported();
	}
}
