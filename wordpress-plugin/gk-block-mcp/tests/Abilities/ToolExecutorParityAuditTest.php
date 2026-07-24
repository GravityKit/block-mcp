<?php
/**
 * Structural audit: every manifest-declared input property for a tool must
 * be referenced by that tool's Tool_Executor::execute_<name>() method — the
 * exact bug class the Codex review's findings #4 and #5 caught
 * (list_block_types' `include_supports` and list_patterns' `category` were
 * declared in the manifest and handled by the REST/npm-MCP paths but never
 * read by the Abilities path, so an ability caller's argument was silently
 * dropped).
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Tool_Executor;

class ToolExecutorParityAuditTest extends WP_UnitTestCase {

	/**
	 * Tools whose execute_*() method forwards the caller's $input wholesale
	 * (as the REST request's params or JSON body, verbatim or with only a
	 * route-level key like post_id stripped) rather than naming each
	 * property individually. For these, "does the property name appear as a
	 * literal in the method body" is the wrong question — by construction,
	 * every input key not explicitly unset() reaches the REST handler, and
	 * the unset() call itself is a visible, deliberate exclusion, not a
	 * silent gap. Verified by reading each method; re-verify this list when
	 * one of these methods changes shape.
	 *
	 * @var string[]
	 */
	const WHOLESALE_FORWARD_TOOLS = array(
		'create_post',
		'create_pattern',
		'upload_media',
		'list_terms',
		'list_posts',
		'get_post_info',
		'edit_block_tree',
		'update_post',
		'yoast_update_seo',
	);

	/**
	 * Individual properties that exist only for the npm MCP server's
	 * client-side enrichment layer (src/enrichers.ts) and have no PHP
	 * equivalent: update_block's `block_name` selects which enricher runs
	 * (e.g. deriving CBP's codeHTML) on the *client*, before only the
	 * already-enriched attributes/innerHTML are sent over the wire — the
	 * REST endpoint itself never accepts a `block_name` parameter, so
	 * Tool_Executor has nothing to forward it to.
	 *
	 * This is a real, standing gap (an Abilities-path caller updating an
	 * enricher-eligible block doesn't get automatic computed-field
	 * derivation the way an npm-MCP caller does), but porting enrichment to
	 * PHP is a separate feature, not a wiring fix this test polices.
	 * Documented here instead of silently exempted.
	 *
	 * @var array<string, string[]>
	 */
	const CLIENT_SIDE_ONLY_PROPERTIES = array(
		'update_block' => array( 'block_name' ),
	);

	/**
	 * For every non-wholesale-forwarding tool, every property the manifest
	 * declares in its input_schema must appear, verbatim, somewhere in that
	 * tool's Tool_Executor::execute_<name>() method body.
	 *
	 * A string-containment check rather than a parser: this codebase always
	 * accesses tool input via a literal `$input['prop_name']`, so
	 * containment reliably proxies "is this read" without parsing PHP. It
	 * cannot prove the value is actually forwarded, not just read and
	 * discarded — see ListToolsAbilityParityTest for the behavioral checks
	 * that cover that for list_block_types/list_patterns specifically.
	 */
	public function test_every_manifest_input_property_is_referenced_by_its_tool_executor_method() {
		$manifest_path = GK_BLOCK_MCP_PLUGIN_DIR . 'includes/abilities/tools.manifest.json';
		$raw           = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest      = json_decode( $raw, true );
		$this->assertIsArray( $manifest, 'tools.manifest.json must parse as an array' );
		$this->assertNotEmpty( $manifest['tools'] );

		$executor_path   = GK_BLOCK_MCP_PLUGIN_DIR . 'includes/class-tool-executor.php';
		$executor_source = file_get_contents( $executor_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $executor_source );
		$lines = explode( "\n", $executor_source );

		$problems = array();

		foreach ( $manifest['tools'] as $tool ) {
			$name   = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			$method = 'execute_' . $name;

			// A manifest tool with no matching Tool_Executor method at all is
			// a total-non-execution gap, not a partial-argument gap — a
			// different bug class, already pinned elsewhere (e.g.
			// AbilitiesRegistryTest's manifest-count assertion catches a
			// tool that was never wired up at all). Skip rather than
			// double-report here.
			if ( ! method_exists( Tool_Executor::class, $method ) ) {
				continue;
			}

			if ( in_array( $name, self::WHOLESALE_FORWARD_TOOLS, true ) ) {
				continue;
			}

			$properties = ( isset( $tool['input_schema']['properties'] ) && is_array( $tool['input_schema']['properties'] ) )
				? array_keys( $tool['input_schema']['properties'] )
				: array();

			if ( empty( $properties ) ) {
				continue;
			}

			$reflection = new \ReflectionMethod( Tool_Executor::class, $method );
			$body       = implode(
				"\n",
				array_slice(
					$lines,
					$reflection->getStartLine() - 1,
					$reflection->getEndLine() - $reflection->getStartLine() + 1
				)
			);

			$exempt_properties = isset( self::CLIENT_SIDE_ONLY_PROPERTIES[ $name ] ) ? self::CLIENT_SIDE_ONLY_PROPERTIES[ $name ] : array();

			foreach ( $properties as $property ) {
				if ( in_array( $property, $exempt_properties, true ) ) {
					continue;
				}
				if ( false === strpos( $body, $property ) ) {
					$problems[] = sprintf(
						'%s: manifest declares input property "%s" but Tool_Executor::%s() never references it — the argument is silently dropped on the Abilities/MCP-Adapter path.',
						$name,
						$property,
						$method
					);
				}
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}
}
