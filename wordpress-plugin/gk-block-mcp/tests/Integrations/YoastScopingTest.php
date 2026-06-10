<?php
/**
 * Pins the test-harness contract that Yoast SEO loads ONLY for the dedicated
 * Yoast run, never in the general suite.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare(strict_types=1);

/**
 * Yoast-scoping bootstrap contract.
 */
class YoastScopingTest extends WP_UnitTestCase {

	/**
	 * The general suite must boot without Yoast SEO.
	 *
	 * Loading Yoast in the general run is doubly harmful: its migrations issue
	 * CREATE INDEX statements the SQLite drop-in cannot execute, and its boot
	 * calls wp_is_block_theme(), which WordPress 6.8+ flags via _doing_it_wrong
	 * when the call lands in a process-isolated child — and CI's
	 * error_reporting=E_ALL turns that notice into a hard test failure. The
	 * bootstrap therefore gates the Yoast require behind GK_LOAD_YOAST, set
	 * only by tests/phpunit/yoast.xml. WPSEO_FILE is Yoast's own load marker;
	 * its absence here proves Yoast did not load in this process.
	 *
	 * This test carries no group tag, so it runs in the general single-site
	 * config (tests/phpunit.xml) and not in the yoast/ms-required runs. If the
	 * gate regresses and Yoast loads suite-wide again, WPSEO_FILE becomes
	 * defined and this fails — locally, before CI ever sees the notice.
	 */
	public function test_general_suite_runs_without_yoast_loaded() {
		$this->assertFalse(
			defined( 'WPSEO_FILE' ),
			'Yoast SEO must not load in the general suite — it belongs to the GK_LOAD_YOAST-gated yoast run only.'
		);
	}
}
