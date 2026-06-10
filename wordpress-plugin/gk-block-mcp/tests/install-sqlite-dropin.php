<?php
/**
 * Install the WordPress/sqlite-database-integration plugin and its db.php
 * drop-in into the composer-managed WP install. Idempotent: skips clone if
 * the plugin directory already exists.
 *
 * Run automatically by composer post-install / post-update.
 */

declare( strict_types=1 );

$root           = dirname( __DIR__ );
$wp_dir         = $root . '/vendor/wordpress/wordpress';
$wp_content     = $wp_dir . '/wp-content';
$plugins_dir    = $wp_content . '/plugins';
$sqlite_plugin  = $plugins_dir . '/sqlite-database-integration';
$db_dropin      = $wp_content . '/db.php';
$plugin_repo    = 'https://github.com/WordPress/sqlite-database-integration.git';
$plugin_version = 'main';

if ( ! is_dir( $wp_dir ) ) {
	fwrite( STDERR, "✘ WP install not found at $wp_dir — did composer install run?\n" );
	exit( 1 );
}

if ( ! is_dir( $wp_content ) ) {
	mkdir( $wp_content, 0755, true );
}
if ( ! is_dir( $plugins_dir ) ) {
	mkdir( $plugins_dir, 0755, true );
}

if ( ! is_dir( $sqlite_plugin ) ) {
	echo "→ Cloning sqlite-database-integration into $sqlite_plugin\n";
	$cmd = sprintf(
		'git clone --depth 1 --branch %s %s %s',
		escapeshellarg( $plugin_version ),
		escapeshellarg( $plugin_repo ),
		escapeshellarg( $sqlite_plugin )
	);
	passthru( $cmd, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "✘ Failed to clone sqlite-database-integration\n" );
		exit( $code );
	}
} else {
	echo "✓ sqlite-database-integration already present, skipping clone\n";
}

$db_copy = $sqlite_plugin . '/db.copy';
if ( ! is_file( $db_copy ) ) {
	fwrite( STDERR, "✘ Expected $db_copy after clone, not found.\n" );
	exit( 1 );
}

$contents = file_get_contents( $db_copy );
// db.copy uses two placeholders that the plugin's own installer would normally
// rewrite during activation. We do the same rewrite here so the drop-in is
// self-contained and doesn't need WP plugin-activation hooks to run first.
$contents = strtr(
	$contents,
	array(
		'{SQLITE_IMPLEMENTATION_FOLDER_PATH}' => $sqlite_plugin,
		'{SQLITE_PLUGIN}'                     => 'sqlite-database-integration/load.php',
	)
);

file_put_contents( $db_dropin, $contents );
echo "✓ Installed db.php drop-in at $db_dropin\n";
