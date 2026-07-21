<?php
/**
 * MCP annotation conformance sweep for every Block MCP tool.
 *
 * MCP clients read a tool's `readonly` / `destructive` hints to decide whether
 * to auto-run it or ask the user to confirm. A wrong `destructive:false` on a
 * write tool means a client silently overwrites content without confirmation:
 * exactly the bug that shipped for update_block/update_blocks. This sweeps all
 * 24 non-Yoast tools against a hardcoded intent table so any single regression
 * is caught, not just the spot-checked handful in AbilitiesRegistryTest.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

class AbilityAnnotationTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the Abilities API registry's one-shot init.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * Intended annotations per tool: [ability_id, readonly, destructive, idempotent].
	 * Reads are readonly + idempotent; adds (insert/create/upload) are neither
	 * readonly nor destructive; overwrites and removals are destructive.
	 *
	 * @return array<string, array{0:string,1:bool,2:bool,3:bool}>
	 */
	public function annotation_provider(): array {
		return array(
			'list-block-types'    => array( 'gk-block-mcp/list-block-types', true, false, true ),
			'list-patterns'       => array( 'gk-block-mcp/list-patterns', true, false, true ),
			'get-pattern'         => array( 'gk-block-mcp/get-pattern', true, false, true ),
			'get-site-usage'      => array( 'gk-block-mcp/get-site-usage', true, false, true ),
			'resolve-url'         => array( 'gk-block-mcp/resolve-url', true, false, true ),
			'list-posts'          => array( 'gk-block-mcp/list-posts', true, false, true ),
			'get-post-info'       => array( 'gk-block-mcp/get-post-info', true, false, true ),
			'get-page-blocks'     => array( 'gk-block-mcp/get-page-blocks', true, false, true ),
			'get-block'           => array( 'gk-block-mcp/get-block', true, false, true ),
			'list-terms'          => array( 'gk-block-mcp/list-terms', true, false, true ),
			'site-editor-context' => array( 'gk-block-mcp/site-editor-context', true, false, true ),
			'scan-storage-modes'  => array( 'gk-block-mcp/scan-storage-modes', false, false, true ),
			'insert-blocks'       => array( 'gk-block-mcp/insert-blocks', false, false, false ),
			'insert-pattern'      => array( 'gk-block-mcp/insert-pattern', false, false, false ),
			'create-post'         => array( 'gk-block-mcp/create-post', false, false, false ),
			'upload-media'        => array( 'gk-block-mcp/upload-media', false, false, false ),
			'update-block'        => array( 'gk-block-mcp/update-block', false, true, false ),
			'update-blocks'       => array( 'gk-block-mcp/update-blocks', false, true, false ),
			'delete-block'        => array( 'gk-block-mcp/delete-block', false, true, false ),
			'replace-block-range' => array( 'gk-block-mcp/replace-block-range', false, true, false ),
			'rewrite-post-blocks' => array( 'gk-block-mcp/rewrite-post-blocks', false, true, false ),
			'edit-block-tree'     => array( 'gk-block-mcp/edit-block-tree', false, true, false ),
			'update-post'         => array( 'gk-block-mcp/update-post', false, true, false ),
			'revert-to-revision'  => array( 'gk-block-mcp/revert-to-revision', false, true, true ),
		);
	}

	/**
	 * Each tool's registered readonly / destructive / idempotent annotation must
	 * match its intent, so an MCP client's confirm-before-write behavior is
	 * driven by the correct hints.
	 *
	 * @dataProvider annotation_provider
	 */
	public function test_ability_annotations_match_intent( string $id, bool $readonly, bool $destructive, bool $idempotent ) {
		$annotations = wp_get_ability( $id )->get_meta()['annotations'];

		$this->assertSame( $readonly, (bool) $annotations['readonly'], $id . ' readonly annotation' );
		$this->assertSame( $destructive, (bool) $annotations['destructive'], $id . ' destructive annotation' );
		$this->assertSame( $idempotent, (bool) $annotations['idempotent'], $id . ' idempotent annotation' );
	}

	/**
	 * Every tool is exposed over MCP: an ability without meta.mcp.public === true
	 * is invisible to the MCP Adapter's server (7 abilities were hidden before
	 * the 2.1.0 fix). A single false here silently drops a tool from every
	 * MCP client.
	 *
	 * @dataProvider annotation_provider
	 */
	public function test_every_tool_is_mcp_public( string $id ) {
		$meta = wp_get_ability( $id )->get_meta();
		$this->assertTrue(
			isset( $meta['mcp']['public'] ) && true === $meta['mcp']['public'],
			$id . ' must declare meta.mcp.public = true to be visible over MCP'
		);
	}
}
