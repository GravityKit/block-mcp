<?php
/**
 * Registers Block MCP tools with the WordPress Abilities API.
 *
 * Tool schemas are exported from the npm MCP server into
 * includes/abilities/tools.manifest.json (see scripts/export-abilities-manifest.mjs).
 * Execution delegates to Tool_Executor so abilities reuse the same REST handlers
 * as the standalone MCP server.
 *
 * @package GravityKit\BlockMCP
 * @since   2.1.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities API registration for all Block MCP tools.
 */
class Abilities_Registry {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'gk-block-mcp';

	/**
	 * Tool executor wired to the block service graph.
	 *
	 * @var Tool_Executor
	 */
	private $executor;

	/**
	 * REST controller used for permission checks.
	 *
	 * @var REST_Controller
	 */
	private $controller;

	/**
	 * Parsed tool manifest.
	 *
	 * @var array<string, mixed>|null
	 */
	private $manifest;

	/**
	 * Constructor.
	 *
	 * @param Tool_Executor   $executor   Tool runner.
	 * @param REST_Controller $controller REST controller for permission callbacks.
	 */
	public function __construct( Tool_Executor $executor, REST_Controller $controller ) {
		$this->executor   = $executor;
		$this->controller = $controller;
	}

	/**
	 * Register the ability category (call on wp_abilities_api_categories_init).
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Block MCP', 'gk-block-mcp' ),
				'description' => __( 'Block-level WordPress content discovery, reads, writes, and structural edits for AI agents.', 'gk-block-mcp' ),
			)
		);
	}

	/**
	 * Register every exported tool as an ability (call on wp_abilities_api_init).
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$manifest = $this->load_manifest();
		if ( null === $manifest || empty( $manifest['tools'] ) || ! is_array( $manifest['tools'] ) ) {
			return;
		}

		foreach ( $this->registrable_tools( $manifest ) as $tool ) {
			$this->register_tool_ability( $tool );
		}
	}

	/**
	 * Register a custom MCP Adapter server that exposes each ability as its own tool.
	 *
	 * On the default MCP Adapter server, public abilities are invoked through
	 * discover-abilities / execute-ability. A dedicated server lists the Block MCP
	 * tools directly — matching the npm block-mcp server's tool surface.
	 *
	 * @return void
	 */
	public function register_mcp_server() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return;
		}

		$manifest = $this->load_manifest();
		if ( null === $manifest || empty( $manifest['tools'] ) || ! is_array( $manifest['tools'] ) ) {
			return;
		}

		$ability_ids = array();
		foreach ( $this->registrable_tools( $manifest ) as $tool ) {
			if ( ! empty( $tool['ability'] ) && is_string( $tool['ability'] ) ) {
				$ability_ids[] = $tool['ability'];
			}
		}

		if ( empty( $ability_ids ) ) {
			return;
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			'gk-block-mcp',
			'gk-block-mcp',
			'mcp',
			'Block MCP',
			__( 'Block-level WordPress content CRUD with preference-aware guidance.', 'gk-block-mcp' ),
			GK_BLOCK_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$ability_ids,
			array(),
			array()
		);
	}

	/**
	 * Return every registered ability id from the manifest.
	 *
	 * @return string[]
	 */
	public function get_ability_ids() {
		$manifest = $this->load_manifest();
		if ( null === $manifest || empty( $manifest['tools'] ) || ! is_array( $manifest['tools'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( $this->registrable_tools( $manifest ) as $tool ) {
			if ( ! empty( $tool['ability'] ) && is_string( $tool['ability'] ) ) {
				$ids[] = $tool['ability'];
			}
		}
		return $ids;
	}

	/**
	 * Manifest tools this site can actually run.
	 *
	 * Yoast tools (`yoast_*`) call into Tool_Executor's Yoast bridge, which
	 * hard-fails with `yoast_unavailable` when Yoast SEO isn't active — the
	 * same gate `Yoast_Bridge::register_routes()` applies to the REST routes.
	 * Excluding them here keeps Abilities/MCP-Adapter discovery from
	 * advertising abilities that always error on this site.
	 *
	 * @param array<string, mixed> $manifest Parsed manifest (from load_manifest()).
	 * @return array<int, array<string, mixed>>
	 */
	private function registrable_tools( array $manifest ) {
		$tools = isset( $manifest['tools'] ) && is_array( $manifest['tools'] ) ? $manifest['tools'] : array();
		if ( Yoast_Bridge::is_yoast_active() ) {
			return $tools;
		}

		return array_values(
			array_filter(
				$tools,
				static function ( $tool ) {
					$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
					return 0 !== strpos( $name, 'yoast_' );
				}
			)
		);
	}

	/**
	 * Register a single manifest tool as a WordPress ability.
	 *
	 * @param array<string, mixed> $tool Tool definition from the manifest.
	 * @return void
	 */
	private function register_tool_ability( array $tool ) {
		$ability_id = isset( $tool['ability'] ) ? (string) $tool['ability'] : '';
		$tool_name  = isset( $tool['name'] ) ? (string) $tool['name'] : '';
		if ( '' === $ability_id || '' === $tool_name ) {
			return;
		}

		$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
		$permission  = isset( $tool['permission'] ) ? (string) $tool['permission'] : 'edit_post';
		$executor    = $this->executor;

		wp_register_ability(
			$ability_id,
			array(
				'label'               => isset( $tool['label'] ) ? (string) $tool['label'] : $tool_name,
				'description'         => isset( $tool['description'] ) ? (string) $tool['description'] : $tool_name,
				'category'            => self::CATEGORY,
				'input_schema'        => isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] ) ? $tool['input_schema'] : array( 'type' => 'object' ),
				'output_schema'       => isset( $tool['output_schema'] ) && is_array( $tool['output_schema'] ) ? $tool['output_schema'] : array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) use ( $executor, $tool_name ) {
					$payload = is_array( $input ) ? $input : array();
					return $executor->execute( $tool_name, $payload );
				},
				'permission_callback' => function ( $input ) use ( $permission ) {
					return $this->check_tool_permission( $permission, is_array( $input ) ? $input : array() );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => ! empty( $annotations['readonly'] ),
						'destructive' => ! empty( $annotations['destructive'] ),
						'idempotent'  => ! empty( $annotations['idempotent'] ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission gate aligned with REST route expectations.
	 *
	 * @param string               $permission Permission key from the manifest.
	 * @param array<string, mixed> $input      Validated ability input.
	 * @return true|\WP_Error
	 */
	private function check_tool_permission( string $permission, array $input ) {
		switch ( $permission ) {
			case 'read':
				return $this->controller_check( array( $this->controller, 'check_permissions' ) );
			case 'upload_files':
				return $this->controller_check( array( $this->controller, 'check_upload_permissions' ) );
			case 'manage_options':
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				return new \WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to run this operation.', 'gk-block-mcp' ),
					array( 'status' => 403 )
				);
			case 'create_post':
				return $this->controller_check( array( $this->controller, 'check_edit_permissions' ) );
			case 'edit_post':
			default:
				$base = $this->controller_check( array( $this->controller, 'check_edit_permissions' ) );
				if ( is_wp_error( $base ) ) {
					return $base;
				}
				if ( isset( $input['post_id'] ) ) {
					$post_id = absint( $input['post_id'] );
					if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to edit this post.', 'gk-block-mcp' ),
							array( 'status' => 403 )
						);
					}
				}
				return true;
		}
	}

	/**
	 * Normalize a REST_Controller permission-check result to true or a WP_Error.
	 *
	 * @param callable $callback REST_Controller permission method.
	 * @return true|\WP_Error
	 */
	private function controller_check( callable $callback ) {
		$result = call_user_func( $callback );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true === $result ? true : new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this endpoint.', 'gk-block-mcp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Load and cache the exported tool manifest.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load_manifest() {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}

		$path = GK_BLOCK_MCP_PLUGIN_DIR . 'includes/abilities/tools.manifest.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$this->manifest = $decoded;
		return $this->manifest;
	}
}
