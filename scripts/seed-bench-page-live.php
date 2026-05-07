<?php
/**
 * Bench seed for the LIVE-PAGE benchmark dimension.
 *
 * Reads scripts/fixtures/gk-real-page.html (a snapshot of a real published
 * page from www.gravitykit.com) and creates it as a draft on the target
 * site. Pairs with mcp-agent-bench.mjs's live-page scenarios, which target
 * specific blocks present in this snapshot (the comparison table, the
 * "Frequently asked questions" heading, the "GravityImport when..." list, etc).
 *
 * The synthetic seed (seed-bench-page.php) gives controlled, hand-crafted
 * content for clean apples-to-apples comparisons. This live seed gives
 * production-grade complexity — Yoast FAQ blocks, an 11-row table with
 * formatted headers, real-world heading/list/paragraph mix.
 */

$fixture_path = __DIR__ . '/fixtures/gk-real-page.html';
if ( ! file_exists( $fixture_path ) ) {
  fwrite( STDERR, "Fixture not found: $fixture_path\n" );
  exit( 1 );
}
$content = file_get_contents( $fixture_path );

// Resolve a sensible author. wp eval-file --user=<id> populates
// get_current_user_id(); fall back to the first administrator so the
// script stays portable across installations.
$author_id = get_current_user_id();
if ( ! $author_id ) {
  $admins    = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
  $author_id = $admins ? (int) $admins[0] : 1;
}

$pid = wp_insert_post( array(
  'post_title'   => 'MCP Benchmark — Live GK Page',
  'post_status'  => 'draft',
  'post_type'    => 'page',
  'post_author'  => $author_id,
  'post_content' => $content,
), true );

if ( is_wp_error( $pid ) ) {
  fwrite( STDERR, $pid->get_error_message() . "\n" );
  exit( 1 );
}
echo $pid;
