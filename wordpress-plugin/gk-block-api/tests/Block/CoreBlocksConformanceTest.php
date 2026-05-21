<?php
/**
 * Conformance tests against every core block in `tests/fixtures/core-blocks/`.
 *
 * The fixtures mirror the Gutenberg `packages/block-library/src/{block}/block.json`
 * tree at the trunk snapshot listed in `tests/fixtures/core-blocks/README.md`.
 * Refresh with `scripts/refresh-core-blocks.sh`.
 *
 * The two assertions this file pins:
 *
 *  1. Blocks whose attribute schema includes any field with
 *     `source: rich-text|html|children` MUST be rejected by `insert_blocks`
 *     with `inner_html_required` when the caller sends that attribute alone
 *     (no innerHTML, no innerBlocks). Pins the source-bound allow-list.
 *  2. Blocks whose attribute schema has NO HTML-sourced fields MUST pass
 *     through attribute-only inserts cleanly (the guard must not become
 *     over-broad).
 *
 * @package GravityKit\BlockAPI\Tests
 */

class CoreBlocksConformanceTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	/**
	 * Sources that store data inside DOM content (not attributes). These are
	 * the ones the `require_inner_html_for_source_bound_attrs` guard targets.
	 *
	 * Must stay aligned with the allow-list in
	 * `Block_Writer::require_inner_html_for_source_bound_attrs()`. The set
	 * is the meta-schema enum from
	 * `tests/fixtures/core-blocks/block-schema.json` minus `meta` (the only
	 * source that doesn't read from the DOM).
	 */
	private const HTML_SOURCES = array( 'rich-text', 'html', 'children', 'text', 'raw', 'attribute', 'query' );

	/**
	 * Core block names that need extra setup or that legitimately fail this
	 * test for reasons unrelated to the guard:
	 *
	 *  - `core/block`: reusable-block reference. Its `ref` attribute is
	 *    required and points at a `wp_block` CPT — registry check fires.
	 *  - `core/missing`: placeholder for unknown blocks during parsing,
	 *    never inserted by the agent.
	 *  - `core/freeform`: classic-editor block; uses `content: html` but is
	 *    legacy migration glue not exercised by MCP agents.
	 *
	 * Anything else that needs to be excluded should have a one-line
	 * comment explaining why.
	 */
	private const SKIP_BLOCKS = array(
		'core/block',
		'core/missing',
		'core/freeform',
	);

	/** @var int|null Saved error reporting level, restored in tear_down. */
	private $saved_error_reporting = null;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();

		// Some core blocks (notably core/calendar) render through the bundled
		// WordPress copy in vendor/ during the wp_update_post() filter chain,
		// and that bundled version has known PHP 8.1+ deprecations
		// (str_replace passed null subject in calendar.php:66, etc.). The
		// deprecations are upstream-fixed in newer WP releases but echo
		// noisily here. We silence E_DEPRECATED for the duration of the
		// conformance run — this file only exercises our guard's behaviour,
		// not WP core's render output.
		$this->saved_error_reporting = error_reporting();
		error_reporting( $this->saved_error_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED );

		// Saving content with core/heading (and possibly other source-bound
		// blocks) triggers a WP-core `_doing_it_wrong()` notice from
		// `rest_validate_value_from_schema` flagging the `rich-text`
		// attribute type as not built-in. We can't use
		// `setExpectedIncorrectUsage()` because most blocks in the corpus
		// don't trigger it — and the expectation fails when not observed.
		// Instead, filter the notice at source for this specific function
		// only, so the WP test framework never sees it.
		add_filter( 'doing_it_wrong_trigger_error', array( $this, 'suppress_schema_validation_notice' ), 10, 2 );
	}

	/**
	 * Suppress the `rest_validate_value_from_schema` _doing_it_wrong notice
	 * during conformance runs. Returns false to prevent WP from triggering
	 * the error; returns the original value unchanged for every other
	 * caller so we don't blanket-suppress legitimate notices.
	 *
	 * @param bool   $trigger      Whether the error should fire.
	 * @param string $function_name The function name that called `_doing_it_wrong`.
	 *
	 * @return bool Filtered trigger value.
	 */
	public function suppress_schema_validation_notice( $trigger, $function_name ) {
		if ( 'rest_validate_value_from_schema' === $function_name ) {
			return false;
		}
		return $trigger;
	}

	public function tear_down(): void {
		remove_filter( 'doing_it_wrong_trigger_error', array( $this, 'suppress_schema_validation_notice' ), 10 );
		if ( null !== $this->saved_error_reporting ) {
			error_reporting( $this->saved_error_reporting );
		}
		parent::tear_down();
	}

	/**
	 * Strip the WP-core schema-validator entry from the captured
	 * `_doing_it_wrong` list before PHPUnit runs its post-condition check.
	 *
	 * WP_UnitTestCase records every `_doing_it_wrong()` call via the
	 * `doing_it_wrong_run` action — separate from the trigger_error filter.
	 * Its `expectedDeprecated()` assertion runs in `assert_post_conditions`,
	 * which fires AFTER the test body but BEFORE `tear_down()`. Removing
	 * the entry here (rather than in tear_down) lets the assertion see an
	 * empty list and pass.
	 *
	 * We only remove `rest_validate_value_from_schema` — every other
	 * notice still fails the test as it should.
	 */
	protected function assert_post_conditions(): void {
		if ( isset( $this->caught_doing_it_wrong['rest_validate_value_from_schema'] ) ) {
			unset( $this->caught_doing_it_wrong['rest_validate_value_from_schema'] );
		}
		parent::assert_post_conditions();
	}

	/**
	 * Iterate every core block.json the test corpus knows about.
	 *
	 * Walks two sources and yields the union, deduped by block name:
	 *
	 *   1. `tests/fixtures/core-blocks/` — Gutenberg trunk snapshot
	 *      (forward-looking, refreshed by `composer refresh-core-blocks`).
	 *   2. `vendor/wordpress/wordpress/wp-includes/blocks/` — the bundled
	 *      WordPress copy used by the PHPUnit harness at runtime; this is
	 *      what `WP_Block_Type_Registry` actually returns on any site
	 *      pinned to the same WP version.
	 *
	 * Trunk takes precedence on duplicates so the test exercises the
	 * latest schema for shared blocks, while vendor-only blocks (legacy
	 * blocks no longer in trunk) still get exercised.
	 *
	 * @return iterable<string, array{0: string, 1: array}> Yields
	 *         `[$block_name, $schema]` per known block.
	 */
	private static function iterate_core_blocks(): iterable {
		$sources = array(
			__DIR__ . '/../fixtures/core-blocks',
			__DIR__ . '/../../vendor/wordpress/wordpress/wp-includes/blocks',
		);

		$seen = array();
		foreach ( $sources as $source_dir ) {
			if ( ! is_dir( $source_dir ) ) {
				continue;
			}
			$dirs = glob( $source_dir . '/*', GLOB_ONLYDIR );
			if ( ! is_array( $dirs ) ) {
				continue;
			}
			sort( $dirs );
			foreach ( $dirs as $dir ) {
				$json_path = $dir . '/block.json';
				if ( ! is_file( $json_path ) ) {
					continue;
				}
				$raw = file_get_contents( $json_path );
				if ( false === $raw ) {
					continue;
				}
				$schema = json_decode( $raw, true );
				if ( ! is_array( $schema ) || empty( $schema['name'] ) ) {
					continue;
				}
				$name = $schema['name'];
				if ( isset( $seen[ $name ] ) ) {
					continue;
				}
				$seen[ $name ] = true;
				yield $name => array( $name, $schema );
			}
		}
	}

	/**
	 * Data provider: blocks whose attribute schema contains at least one
	 * html-sourced attribute (the rejection contract applies).
	 */
	public static function provide_html_sourced_blocks(): iterable {
		foreach ( self::iterate_core_blocks() as $key => $tuple ) {
			if ( in_array( $tuple[0], self::SKIP_BLOCKS, true ) ) {
				continue;
			}
			if ( ! empty( self::html_sourced_attrs( $tuple[1] ) ) ) {
				yield $key => $tuple;
			}
		}
	}

	/**
	 * Data provider: blocks whose attribute schema has NO html-sourced
	 * attributes (the pass-through contract applies).
	 */
	public static function provide_non_html_sourced_blocks(): iterable {
		foreach ( self::iterate_core_blocks() as $key => $tuple ) {
			if ( in_array( $tuple[0], self::SKIP_BLOCKS, true ) ) {
				continue;
			}
			if ( empty( self::html_sourced_attrs( $tuple[1] ) ) ) {
				yield $key => $tuple;
			}
		}
	}

	/**
	 * Find attribute names whose `source` value is in HTML_SOURCES.
	 *
	 * @param array $schema The decoded block.json contents.
	 * @return array<int, string> List of attribute names.
	 */
	private static function html_sourced_attrs( array $schema ): array {
		$out = array();
		$attrs = isset( $schema['attributes'] ) && is_array( $schema['attributes'] ) ? $schema['attributes'] : array();
		foreach ( $attrs as $name => $def ) {
			if ( ! is_array( $def ) || empty( $def['source'] ) ) {
				continue;
			}
			if ( in_array( $def['source'], self::HTML_SOURCES, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Register a block type from its block.json schema for the duration of
	 * the test. The base set_up wires the plugin's filter graph but does
	 * not register the full core block library — many of these blocks are
	 * not registered by default in wp-phpunit's test bootstrap.
	 *
	 * @param array $schema Decoded block.json.
	 */
	private function ensure_block_registered( array $schema ): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( $schema['name'] ) ) {
			return;
		}
		$args = array();
		if ( isset( $schema['attributes'] ) && is_array( $schema['attributes'] ) ) {
			$args['attributes'] = $schema['attributes'];
		}
		if ( isset( $schema['supports'] ) && is_array( $schema['supports'] ) ) {
			$args['supports'] = $schema['supports'];
		}
		$registry->register( $schema['name'], $args );
	}

	/**
	 * Build a sample value matching the attribute's declared type so the
	 * insert payload is realistic. The actual value is irrelevant to the
	 * guard — it only checks whether the attribute key is present.
	 *
	 * @param array $attr_def The attribute definition from block.json.
	 * @return mixed
	 */
	private function sample_value( array $attr_def ) {
		$type = isset( $attr_def['type'] ) ? $attr_def['type'] : 'string';
		switch ( $type ) {
			case 'integer':
			case 'number':
				return 1;
			case 'boolean':
				return true;
			case 'array':
				return array();
			case 'object':
				return new \stdClass();
			default:
				return 'sample';
		}
	}

	/**
	 * For every block with at least one html-sourced attribute, sending that
	 * attribute alone (no innerHTML, no innerBlocks) must produce
	 * `inner_html_required`.
	 *
	 * @dataProvider provide_html_sourced_blocks
	 */
	public function test_html_sourced_attribute_only_insert_is_rejected( $block_name, $schema ) {
		$html_attrs = self::html_sourced_attrs( $schema );
		$this->ensure_block_registered( $schema );

		$attrs = array();
		foreach ( $html_attrs as $attr_name ) {
			$attrs[ $attr_name ] = $this->sample_value( $schema['attributes'][ $attr_name ] );
		}

		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => $block_name, 'attributes' => $attrs ) )
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			sprintf( 'Expected inner_html_required for %s with html-sourced attrs [%s] but insert succeeded.', $block_name, implode( ',', $html_attrs ) )
		);
		$this->assertEquals(
			'inner_html_required',
			$result->get_error_code(),
			sprintf( 'Expected error code inner_html_required for %s but got %s.', $block_name, $result->get_error_code() )
		);
		$data = $result->get_error_data();
		$this->assertEquals( $block_name, $data['block'] );
		foreach ( $html_attrs as $attr_name ) {
			$this->assertContains(
				$attr_name,
				$data['source_bound_attributes'],
				sprintf( 'Expected source_bound_attributes to include %s for %s.', $attr_name, $block_name )
			);
		}
	}

	/**
	 * Round-trip: for every block in the corpus, an insert with a canonical
	 * payload survives parse_blocks → serialize_blocks → parse_blocks
	 * byte-identically (the editor's invalid-content trigger). For html-
	 * sourced blocks we build the innerHTML from the source attribute's
	 * selector so the markup matches what save() would emit; for the rest
	 * we send attributes only.
	 *
	 * This is the strongest available guarantee that the guard's contract,
	 * the empty-class normaliser, and the rest of the write pipeline don't
	 * accidentally produce a non-idempotent serialise/parse cycle on any
	 * core block — without re-implementing each block's JS save() in PHP.
	 *
	 * @dataProvider provide_round_trip_blocks
	 */
	public function test_block_round_trips_through_serialize_parse( $block_name, $schema ) {
		$this->ensure_block_registered( $schema );

		$payload = $this->build_round_trip_payload( $schema );

		$result = $this->crud->insert_blocks( $this->post_id, null, array( $payload ) );

		// `inner_html_required` rejection means the payload builder failed to
		// match the source-bound contract for this block — surface a clear
		// failure instead of swallowing it as "round-trip passed".
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$result,
			sprintf( 'Insert failed for %s: %s', $block_name, is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertTrue( $result['success'] );

		$post_content   = (string) get_post_field( 'post_content', $this->post_id );
		$parsed_once    = parse_blocks( $post_content );
		$reserialized   = serialize_blocks( $parsed_once );
		$parsed_twice   = parse_blocks( $reserialized );

		$this->assertSame(
			$post_content,
			$reserialized,
			sprintf( 'serialize_blocks(parse_blocks(content)) is not byte-identical for %s — block does not round-trip cleanly.', $block_name )
		);
		$this->assertEquals(
			$parsed_once,
			$parsed_twice,
			sprintf( 'parse_blocks is not idempotent across a serialize cycle for %s.', $block_name )
		);
	}

	/**
	 * Data provider: every block in the corpus minus SKIP_BLOCKS and minus
	 * blocks whose canonical innerHTML can't be synthesised from the schema
	 * alone (complex multi-selector sources, container blocks with nested
	 * innerBlocks requirements).
	 */
	public static function provide_round_trip_blocks(): iterable {
		foreach ( self::iterate_core_blocks() as $key => $tuple ) {
			if ( in_array( $tuple[0], self::SKIP_BLOCKS, true ) ) {
				continue;
			}
			if ( in_array( $tuple[0], self::ROUND_TRIP_SKIP, true ) ) {
				continue;
			}
			yield $key => $tuple;
		}
	}

	/**
	 * Blocks whose canonical innerHTML cannot be synthesised from the
	 * schema alone — they need real innerBlocks, real media, or a
	 * multi-selector wrapper this test fixture isn't equipped to produce.
	 * Each entry should explain why it's here.
	 */
	private const ROUND_TRIP_SKIP = array(
		// Container blocks whose canonical save() output is shaped by
		// innerBlocks composition, not flat attributes:
		'core/buttons',
		'core/columns',
		'core/column',
		'core/group',
		'core/list',
		'core/list-item', // requires parent core/list
		'core/quote',     // citation lives in a footer innerBlock since WP 5.4
		'core/pullquote', // similar to quote, mixed innerBlocks + citation
		'core/cover',
		'core/media-text',
		'core/navigation',
		'core/navigation-link',
		'core/navigation-submenu',
		'core/navigation-overlay-close',
		'core/social-links',
		'core/social-link',
		'core/page-list',
		'core/page-list-item',
		'core/comments',
		'core/comments-pagination',
		'core/comment-template',
		'core/post-template',
		'core/post-comments-form',
		'core/query',
		'core/query-no-results',
		'core/query-pagination',
		'core/template-part',
		'core/pattern',
		'core/footnotes',
		'core/accordion',
		'core/accordion-item',
		'core/accordion-panel',
		'core/accordion-heading',
		'core/tab',
		'core/tab-list',
		'core/tab-panel',
		'core/tab-panels',
		'core/tabs',
		'core/playlist',
		'core/playlist-track',
		'core/form',
		'core/form-input',
		'core/form-submit-button',
		'core/form-submission-notification',
		'core/terms-query',
		'core/term-template',
		// Multi-selector or attribute-on-nested-element sources (e.g.,
		// core/embed.url lives on an iframe inside a figure):
		'core/embed',
		'core/audio',
		'core/video',
		'core/image',
		'core/gallery',
		'core/file',
		'core/site-logo',
		'core/cover', // duplicate harmless — entry guards future renames
	);

	/**
	 * Build a minimal valid block payload for the round-trip test.
	 *
	 * For html-sourced attributes (rich-text / html / children) the helper
	 * pairs the attribute with a small innerHTML built from the schema's
	 * declared selector so the guard accepts the payload and save() output
	 * matches what's stored. For blocks with no html-sourced attributes,
	 * the helper returns attributes only (or an empty attrs object).
	 *
	 * @param array $schema Decoded block.json.
	 * @return array Block definition: { name, attributes, innerHTML? }.
	 */
	private function build_round_trip_payload( array $schema ): array {
		$name       = $schema['name'];
		$attrs      = isset( $schema['attributes'] ) && is_array( $schema['attributes'] ) ? $schema['attributes'] : array();
		$html_attrs = self::html_sourced_attrs( $schema );

		if ( empty( $html_attrs ) ) {
			return array( 'name' => $name );
		}

		// Use the first html-sourced attribute and its selector to scaffold
		// minimal markup. Heading defaults to <h2> when the selector lists
		// multiple tags.
		$attr_name = $html_attrs[0];
		$def       = $attrs[ $attr_name ];
		$selector  = isset( $def['selector'] ) && is_string( $def['selector'] ) ? trim( $def['selector'] ) : '';
		$value     = 'sample text';
		$tag       = $this->canonical_tag_for_selector( $name, $selector );

		return array(
			'name'       => $name,
			'attributes' => array( $attr_name => $value ),
			'innerHTML'  => sprintf( '<%1$s>%2$s</%1$s>', $tag, $value ),
		);
	}

	/**
	 * Choose a canonical HTML tag from a schema selector string.
	 *
	 * Selectors in block.json are CSS-like. For the round-trip test we
	 * just need the tag — descendant combinators and class qualifiers are
	 * dropped, multi-tag groups (h1,h2,...) default to a safe pick.
	 *
	 * @param string $block_name Block type name (for default heading level).
	 * @param string $selector   Selector string from block.json.
	 * @return string Canonical tag name (lowercase, no attributes).
	 */
	private function canonical_tag_for_selector( $block_name, $selector ) {
		if ( '' === $selector ) {
			return 'div';
		}
		// Pick the LAST simple-selector segment (descendant combinator).
		$parts = preg_split( '/\s+/', $selector );
		$last  = end( $parts );
		// Strip class/id/attr qualifiers.
		$tag = preg_replace( '/[\[.#].*/', '', $last );
		// Multi-tag group — first option, plus heading default.
		if ( strpos( $tag, ',' ) !== false ) {
			$first = trim( explode( ',', $tag )[0] );
			if ( 'core/heading' === $block_name ) {
				return 'h2';
			}
			return $first !== '' ? $first : 'div';
		}
		return $tag !== '' ? $tag : 'div';
	}

	/**
	 * For every block with NO html-sourced attributes, attribute-only inserts
	 * must pass through cleanly (or fail for an unrelated reason — but not
	 * inner_html_required).
	 *
	 * @dataProvider provide_non_html_sourced_blocks
	 */
	public function test_non_html_sourced_block_is_not_rejected_by_inner_html_required( $block_name, $schema ) {
		$this->ensure_block_registered( $schema );

		// Build a minimal attribute payload — one non-html attribute if any
		// are declared, otherwise empty. The guard only consults the schema,
		// so this is enough to exercise the source-source code path.
		$attrs = array();
		if ( isset( $schema['attributes'] ) && is_array( $schema['attributes'] ) ) {
			foreach ( $schema['attributes'] as $name => $def ) {
				$attrs[ $name ] = $this->sample_value( is_array( $def ) ? $def : array() );
				break;
			}
		}

		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => $block_name, 'attributes' => $attrs ) )
		);

		// Either success, or a WP_Error with a code OTHER than inner_html_required.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals(
				'inner_html_required',
				$result->get_error_code(),
				sprintf(
					'Block %s has no html-sourced attrs in its schema but was rejected with inner_html_required. Guard is over-broad.',
					$block_name
				)
			);
		} else {
			$this->assertTrue( $result['success'] );
		}
	}
}
