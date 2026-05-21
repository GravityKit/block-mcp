<?php
/**
 * If tests/fixtures/core-blocks/ is missing or empty, run the refresh script.
 *
 * Invoked by composer `test` and `post-install-cmd` so a fresh checkout
 * pulls the fixtures automatically — no manual step.
 */

$plugin_dir   = dirname( __DIR__ );
$fixtures_dir = $plugin_dir . '/tests/fixtures/core-blocks';

if ( is_dir( $fixtures_dir ) && glob( $fixtures_dir . '/*/block.json' ) ) {
	exit( 0 );
}

echo "[core-blocks] fixtures missing, running refresh-core-blocks.sh\n";
passthru( 'bash ' . escapeshellarg( $plugin_dir . '/scripts/refresh-core-blocks.sh' ), $code );
exit( $code );
