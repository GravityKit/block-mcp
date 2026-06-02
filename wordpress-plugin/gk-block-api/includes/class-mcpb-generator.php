<?php
/**
 * MCPB_Generator — builds pre-configured Claude Desktop extension bundles.
 *
 * A .mcpb file is a zip archive understood by Claude Desktop's extension
 * installer. It contains manifest.json (schema version 0.3) and the
 * self-contained MCP server binary. The manifest pre-fills user_config fields
 * with the credentials issued at Connect time so the user only has to click
 * "Enable" — no copy-pasting required. The password field carries
 * sensitive:true so Claude Desktop stores it in the OS keychain on enable and
 * substitutes ${user_config.*} tokens into the server process environment at
 * launch.
 *
 * @package GravityKit\BlockAPI
 * @since   1.9.0
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates .mcpb extension bundles for Claude Desktop.
 *
 * @since 1.9.0
 */
class MCPB_Generator {

	/**
	 * Build the manifest array for the given credentials.
	 *
	 * Returns the PHP array that will be JSON-encoded into manifest.json inside
	 * the .mcpb zip. All three user_config fields are pre-filled with the
	 * supplied credential values. The password field is marked sensitive so
	 * Claude Desktop routes it through the OS keychain, and required so the
	 * extension cannot be enabled without it.
	 *
	 * Callers may mutate the manifest before it reaches the zip via the
	 * `gk_block_api_mcpb_manifest` filter.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string,string> $creds {
	 *     Credential set produced by Agent_Provisioner.
	 *
	 *     @type string $url      WordPress site URL.
	 *     @type string $user     Application Password username.
	 *     @type string $password Application Password (plaintext, one-time).
	 *     @type string $client   Display name for the connecting client.
	 * }
	 * @return array<string,mixed> Manifest array ready for wp_json_encode().
	 */
	public function manifest( array $creds ) {
		$manifest = array(
			'manifest_version' => '0.3',
			'name'             => 'block-mcp',
			'display_name'     => 'Block MCP — ' . $creds['client'],
			'version'          => GK_BLOCK_API_VERSION,
			'description'      => 'Block-level WordPress CRUD for AI agents. Pre-configured for ' . $creds['url'] . '.',
			'author'           => array(
				'name' => 'GravityKit',
				'url'  => 'https://www.gravitykit.com',
			),
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.cjs',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/index.cjs' ),
					'env'     => array(
						'WORDPRESS_URL'          => '${user_config.wordpress_url}',
						'WORDPRESS_USER'         => '${user_config.wordpress_user}',
						'WORDPRESS_APP_PASSWORD' => '${user_config.wordpress_app_password}',
					),
				),
			),
			'user_config'      => array(
				'wordpress_url'          => array(
					'type'     => 'string',
					'title'    => 'WordPress Site URL',
					'required' => true,
					'default'  => $creds['url'],
				),
				'wordpress_user'         => array(
					'type'     => 'string',
					'title'    => 'WordPress Username',
					'required' => true,
					'default'  => $creds['user'],
				),
				'wordpress_app_password' => array(
					'type'      => 'string',
					'title'     => 'WordPress Application Password',
					'required'  => true,
					'sensitive' => true,
					'default'   => $creds['password'],
				),
			),
		);

		/**
		 * Filters the .mcpb manifest array before it is encoded and written
		 * into the zip archive.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string,mixed>  $manifest The generated manifest array.
		 * @param array<string,string> $creds    The credentials used to build it.
		 */
		return apply_filters( 'gk_block_api_mcpb_manifest', $manifest, $creds );
	}

	/**
	 * Return a writable temporary file path for the .mcpb archive.
	 *
	 * Extracted so tests can subclass and override this method to simulate
	 * temp-file creation failures without touching the filesystem.
	 *
	 * @since 1.9.0
	 * @return string|false Absolute path to the new empty temp file, or false on failure.
	 */
	protected function make_temp_path() {
		return wp_tempnam( 'block-mcp.mcpb' );
	}

	/**
	 * Build a .mcpb zip archive and return its filesystem path.
	 *
	 * The returned path points to a temporary file created by wp_tempnam().
	 * The caller is responsible for deleting it after the HTTP response has
	 * been sent (e.g. via register_shutdown_function + unlink).
	 *
	 * Returns WP_Error when the server bundle is absent or unreadable, when the
	 * zip file cannot be created, or when either entry cannot be written into
	 * the archive. Callers must check is_wp_error() before treating the return
	 * value as a path.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string,string> $creds       Credential set — passed through to manifest().
	 * @param string               $server_path Absolute path to the pre-built MCP server bundle
	 *                                          (assets/mcp-server/index.cjs inside the plugin dir).
	 * @return string|\WP_Error Absolute path to the generated .mcpb temp file, or WP_Error on failure.
	 */
	public function build( array $creds, $server_path ) {
		if ( ! is_readable( $server_path ) ) {
			return new \WP_Error(
				'mcpb_server_missing',
				__( 'The Block MCP server bundle is missing. Rebuild the plugin (npm run build) so assets/mcp-server/index.cjs is present.', 'gk-block-api' )
			);
		}

		$path = $this->make_temp_path();

		if ( ! $path ) {
			return new \WP_Error(
				'mcpb_tempfile_failed',
				__( 'Could not create a temporary file for the installer.', 'gk-block-api' )
			);
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_zip_open_failed',
				__( 'Could not create the installer file.', 'gk-block-api' )
			);
		}

		if ( false === $zip->addFromString( 'manifest.json', (string) wp_json_encode( $this->manifest( $creds ) ) ) ) {
			$zip->close();
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_manifest_add_failed',
				__( 'Could not write the manifest into the installer.', 'gk-block-api' )
			);
		}

		if ( ! $zip->addFile( $server_path, 'server/index.cjs' ) ) {
			$zip->close();
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_server_add_failed',
				__( 'Could not bundle the server into the installer.', 'gk-block-api' )
			);
		}

		$zip->close();

		return $path;
	}
}
