<?php
$content = <<<'HTML'
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">A long sample page for benchmarking</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This page exists purely as a fixture for comparing how different WordPress MCP servers handle real block content.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Read it once with each MCP, then try to make a single targeted edit to compare the round-trip shape and size.</p>
<!-- /wp:paragraph -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Introduction</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>WordPress content is stored as a stream of blocks — each one a comment-delimited fragment with a name, attributes, and inner HTML.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item --><li>Posts and pages are stored as block-comment markup in <code>post_content</code></li><!-- /wp:list-item --><!-- wp:list-item --><li>Each block can have nested innerBlocks</li><!-- /wp:list-item --><!-- wp:list-item --><li>Attributes hold structured data; innerHTML holds rendered markup</li><!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Why the format matters for AI</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>An agent editing a page through a REST-flattening MCP has to round-trip the entire post HTML to make any change. A block-aware MCP can target one block by ID and write a single-block update.</p>
<!-- /wp:paragraph -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>The format you expose to the agent shapes the kinds of edits it can make safely.</p><!-- /wp:paragraph --><cite>An author who has edited too much WordPress content with regex</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Code samples</h2>
<!-- /wp:heading -->

<!-- wp:code -->
<pre class="wp-block-code"><code>// Read a page's blocks as a structured tree
const blocks = await client.getPageBlocks(postId);
console.log(blocks.length, 'blocks');</code></pre>
<!-- /wp:code -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Pre-formatted output</h3>
<!-- /wp:heading -->

<!-- wp:preformatted -->
<pre class="wp-block-preformatted">$ npm test
PASS  src/__tests__/refs.test.ts (29 tests)
PASS  src/__tests__/client-refs.test.ts (18 tests)
Test Files  13 passed (13)
Tests       230 passed (230)</pre>
<!-- /wp:preformatted -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Two-column comparison</h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Approach A</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Read the whole post, edit HTML in your head, write the whole post back.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Approach B</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Read once, capture stable refs for the blocks you'll touch, fire targeted updates against those refs.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">A pullquote for emphasis</h2>
<!-- /wp:heading -->

<!-- wp:pullquote -->
<figure class="wp-block-pullquote"><blockquote><p>Surgical edits beat whole-post rewrites once you have more than one change to make.</p></blockquote></figure>
<!-- /wp:pullquote -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Grouped section</h2>
<!-- /wp:heading -->

<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Inside a group</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This block is wrapped in a core/group so the test page exercises the wrap-in-group / unwrap-group code paths too.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Call to action</h2>
<!-- /wp:heading -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Learn more</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#">View pricing</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Conclusion</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>This page contains a representative mix of block types for benchmarking.</p>
<!-- /wp:paragraph -->
HTML;

// Resolve a sensible author. `wp eval-file --user=<id>` populates
// get_current_user_id(); fall back to the first administrator so the
// script stays portable across installations.
$author_id = get_current_user_id();
if ( ! $author_id ) {
  $admins    = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
  $author_id = $admins ? (int) $admins[0] : 1;
}

$pid = wp_insert_post( array(
  'post_title'   => 'MCP Benchmark Test Page',
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
