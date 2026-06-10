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
 * @package GravityKit\BlockMCP
 * @since   2.0.0
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates .mcpb extension bundles for Claude Desktop.
 *
 * @since 2.0.0
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
	 * `gk/block-mcp/mcpb/manifest` filter.
	 *
	 * @since 2.0.0
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
		$host = $this->site_host( isset( $creds['url'] ) ? $creds['url'] : '' );

		$manifest = array(
			'manifest_version' => '0.3',
			// Per-site name so each site installs as a DISTINCT Claude Desktop
			// extension (Claude Desktop keys extensions by manifest name; a fixed
			// name would make a second site's .mcpb replace the first). Mirrors
			// the connector's server name, block-mcp-<host-label>.
			'name'             => $this->server_name( isset( $creds['url'] ) ? $creds['url'] : '' ),
			'display_name'     => 'Block MCP — ' . ( '' !== $host ? $host : $creds['client'] ),
			'version'          => GK_BLOCK_MCP_VERSION,
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
					'type'        => 'string',
					'title'       => 'WordPress Site URL',
					'description' => 'The web address of your WordPress site.',
					'required'    => true,
					'default'     => $creds['url'],
				),
				'wordpress_user'         => array(
					'type'        => 'string',
					'title'       => 'WordPress Username',
					'description' => 'The WordPress account the assistant connects as.',
					'required'    => true,
					'default'     => $creds['user'],
				),
				'wordpress_app_password' => array(
					'type'        => 'string',
					'title'       => 'WordPress Application Password',
					'description' => 'The connection key used to reach your site. Stored securely in your system keychain.',
					'required'    => true,
					'sensitive'   => true,
					'default'     => $creds['password'],
				),
			),
		);

		/**
		 * Customize the Claude Desktop .mcpb bundle before it's packaged.
		 *
		 * This is the full manifest the user downloads and double-clicks to
		 * connect Claude Desktop. Hook in to make the connection your own: set a
		 * branded display name and description, add custom `user_config` fields,
		 * or pin a specific server entry for an enterprise deployment. The
		 * credentials that were just minted are passed alongside so you can tailor
		 * the bundle to the exact account it's being built for.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // White-label the bundle your users see in Claude Desktop.
		 * add_filter( 'gk/block-mcp/mcpb/manifest', function ( $manifest, $creds ) {
		 *     $manifest['display_name'] = 'Acme Content Assistant';
		 *     $manifest['description']  = 'Edit acme.com content from Claude.';
		 *     return $manifest;
		 * }, 10, 2 );
		 *
		 * @param array<string,mixed>  $manifest The generated .mcpb manifest array.
		 * @param array<string,string> $creds    The credentials the bundle is built for.
		 */
		return apply_filters( 'gk/block-mcp/mcpb/manifest', $manifest, $creds );
	}

	/**
	 * Return the host of a site URL, or '' when it has none.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $url Site URL.
	 * @return string Lowercased host, or '' if the URL has no parseable host.
	 */
	protected function site_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return ( is_string( $host ) && '' !== $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Derive the MCP server / extension name from a site URL.
	 *
	 * Returns `block-mcp-<sanitized-host>` using the URL's full host authority —
	 * hostname AND any non-default port — verbatim: lowercased, with each run of
	 * non-alphanumerics collapsed to a single hyphen and stray hyphens trimmed.
	 * Nothing is stripped, truncated, or collapsed, so every distinct host gets a
	 * distinct extension name and no two sites silently share one — including
	 * www vs apex (www.X and X are different hosts) and different subdomains or
	 * ports. This is the same scheme the connector's defaultServerName() uses, so
	 * a site keeps a consistent name whether connected via .mcpb or the CLI.
	 * Falls back to `block-mcp` when the URL has no host. Power users can still
	 * override the name via the `gk/block-mcp/mcpb/manifest` filter.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $url Site URL.
	 * @return string Extension/server name.
	 */
	protected function server_name( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'block-mcp';
		}

		$authority = strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$authority .= ':' . (int) $parts['port'];
		}

		$slug = preg_replace( '/[^a-z0-9]+/', '-', $authority );
		$slug = trim( (string) $slug, '-' );

		return '' !== $slug ? 'block-mcp-' . $slug : 'block-mcp';
	}

	/**
	 * Return a writable temporary file path for the .mcpb archive.
	 *
	 * Extracted so tests can subclass and override this method to simulate
	 * temp-file creation failures without touching the filesystem.
	 *
	 * @since 2.0.0
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
	 * @since 2.0.0
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
				__( 'The Block MCP server bundle is missing. Rebuild the plugin (npm run build) so assets/mcp-server/index.cjs is present.', 'gk-block-mcp' )
			);
		}

		$path = $this->make_temp_path();

		if ( ! $path ) {
			return new \WP_Error(
				'mcpb_tempfile_failed',
				__( 'Could not create a temporary file for the installer.', 'gk-block-mcp' )
			);
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_zip_open_failed',
				__( 'Could not create the installer file.', 'gk-block-mcp' )
			);
		}

		$json = wp_json_encode( $this->manifest( $creds ) );

		if ( false === $json ) {
			$zip->close();
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_manifest_encode_failed',
				sprintf(
					/* translators: %s: JSON encoding error message. */
					__( 'Could not encode the installer manifest: %s', 'gk-block-mcp' ),
					json_last_error_msg()
				)
			);
		}

		if ( false === $zip->addFromString( 'manifest.json', $json ) ) {
			$zip->close();
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_manifest_add_failed',
				__( 'Could not write the manifest into the installer.', 'gk-block-mcp' )
			);
		}

		if ( ! $zip->addFile( $server_path, 'server/index.cjs' ) ) {
			$zip->close();
			wp_delete_file( $path );
			return new \WP_Error(
				'mcpb_server_add_failed',
				__( 'Could not bundle the server into the installer.', 'gk-block-mcp' )
			);
		}

		$zip->close();

		return $path;
	}
}
