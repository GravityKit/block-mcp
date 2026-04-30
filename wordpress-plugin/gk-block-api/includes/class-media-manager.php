<?php
/**
 * Media library uploads.
 *
 * Three input modes (mutually exclusive — exactly one required):
 *  1. multipart form-data — caller sets $args['file_field'] to the form-data
 *     field name; the file appears in $_FILES.
 *  2. URL sideload — caller passes $args['url']. Server downloads via WP HTTP
 *     and writes to uploads.
 *  3. Base64 inline — caller passes $args['data_base64'] (and required
 *     $args['filename']). Decoded server-side, written to a temp file,
 *     then sideloaded.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Manager {

	/** Default size cap for URL sideloads (25 MB). */
	const URL_DOWNLOAD_MAX_BYTES = 26214400;

	/**
	 * Reserved IP ranges to block on URL sideload (SSRF defense).
	 * Includes link-local, loopback, multicast, RFC1918 private ranges,
	 * and IPv6 unique-local + link-local. Overrideable by site admins
	 * via the `gk_block_api_url_sideload_blocked_ranges` filter.
	 */
	const SSRF_BLOCKED_IPV4_RANGES = array(
		array( '0.0.0.0',     '0.255.255.255' ),       // "this network"
		array( '10.0.0.0',    '10.255.255.255' ),      // RFC1918
		array( '127.0.0.0',   '127.255.255.255' ),     // loopback
		array( '169.254.0.0', '169.254.255.255' ),     // link-local (AWS/GCP/Azure metadata)
		array( '172.16.0.0',  '172.31.255.255' ),      // RFC1918
		array( '192.0.0.0',   '192.0.0.255' ),         // IETF reserved
		array( '192.168.0.0', '192.168.255.255' ),     // RFC1918
		array( '198.18.0.0',  '198.19.255.255' ),      // benchmark
		array( '224.0.0.0',   '255.255.255.255' ),     // multicast + reserved
	);

	/**
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.4.
	 * @return array|\WP_Error
	 */
	public function upload( array $args ) {
		$this->require_admin_includes();

		$has_multipart = ! empty( $args['file_field'] )
			&& isset( $_FILES[ $args['file_field'] ] )
			&& ! empty( $_FILES[ $args['file_field'] ] );
		$has_url       = ! empty( $args['url'] ) && is_string( $args['url'] );
		$has_base64    = ! empty( $args['data_base64'] ) && is_string( $args['data_base64'] );

		$mode_count = (int) $has_multipart + (int) $has_url + (int) $has_base64;
		if ( 0 === $mode_count ) {
			return new \WP_Error(
				'missing_file',
				__( 'Provide one of: multipart "file" field, "url", or "data_base64".', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}
		if ( $mode_count > 1 ) {
			return new \WP_Error(
				'multiple_inputs',
				__( 'Only one of "file", "url", or "data_base64" may be supplied.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_multipart ) {
			$attachment_id = $this->handle_multipart( $args );
		} elseif ( $has_url ) {
			$attachment_id = $this->handle_url( $args );
		} else {
			$attachment_id = $this->handle_base64( $args );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$this->apply_metadata( (int) $attachment_id, $args );

		return $this->format_attachment( (int) $attachment_id );
	}

	/**
	 * @param array $args
	 * @return int|\WP_Error
	 */
	private function handle_multipart( array $args ) {
		$field       = $args['file_field'];
		$post_parent = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$file = $_FILES[ $field ];

		// Enforce site upload-size limit before move/copy. media_handle_upload
		// will also enforce, but failing fast keeps us out of the WP error path.
		$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $max > 0 && isset( $file['size'] ) && (int) $file['size'] > $max ) {
			@unlink( $file['tmp_name'] );
			return new \WP_Error(
				'file_too_large',
				__( 'Uploaded file exceeds the site upload limit.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		// Defensive MIME check before media_handle_upload runs its own. Catches
		// disallowed types early so the temp file isn't moved into uploads.
		$mime = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $mime['type'] ) ) {
			@unlink( $file['tmp_name'] );
			return new \WP_Error(
				'disallowed_mime',
				sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-api' ), sanitize_file_name( $file['name'] ) ),
				array( 'status' => 400 )
			);
		}

		return media_handle_upload( $field, $post_parent );
	}

	/**
	 * @param array $args
	 * @return int|\WP_Error
	 */
	private function handle_url( array $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'URL is not valid or not allowed.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		// SSRF defense: resolve host and reject reserved/private/link-local IPs.
		// `wp_http_validate_url()` only catches loopback/0.0.0.0; cloud metadata
		// endpoints (169.254.169.254) and RFC1918 private ranges sail past it.
		$ssrf_check = $this->guard_ssrf( $url );
		if ( is_wp_error( $ssrf_check ) ) {
			return $ssrf_check;
		}

		// Use a tighter timeout than core's 300s default. Slow-source amplification
		// drops to ~10s of resource hold per request.
		$tmp = download_url( $url, 10 );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'url_fetch_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}

		if ( filesize( $tmp ) > self::URL_DOWNLOAD_MAX_BYTES ) {
			@unlink( $tmp );
			return new \WP_Error( 'file_too_large', __( 'Downloaded file exceeds size cap.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $path ? basename( (string) $path ) : 'remote-file';
		$filename = isset( $args['filename'] )
			? sanitize_file_name( (string) $args['filename'] )
			: sanitize_file_name( $basename );

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-api' ), $filename ), array( 'status' => 400 ) );
		}

		$post_parent = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$file        = array( 'name' => $filename, 'tmp_name' => $tmp );
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	/**
	 * @param array $args
	 * @return int|\WP_Error
	 */
	private function handle_base64( array $args ) {
		if ( empty( $args['filename'] ) ) {
			return new \WP_Error( 'invalid_filename', __( '"filename" is required for base64 uploads.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		// Bound the encoded payload BEFORE decoding to limit memory consumption.
		// Base64 expands 3 bytes → 4 bytes, so the encoded length cap matches the
		// decoded size cap (URL_DOWNLOAD_MAX_BYTES, 25 MB).
		$encoded_max = (int) ceil( self::URL_DOWNLOAD_MAX_BYTES * 4 / 3 );
		if ( strlen( (string) $args['data_base64'] ) > $encoded_max ) {
			return new \WP_Error(
				'file_too_large',
				__( 'data_base64 exceeds size cap before decoding.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$decoded = base64_decode( $args['data_base64'], true );
		if ( false === $decoded || '' === $decoded ) {
			return new \WP_Error( 'invalid_base64', __( 'data_base64 is not valid base64.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		// Enforce both the URL-mode cap and the site upload limit on the decoded
		// payload before any disk write.
		$max     = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		$cap     = $max > 0 ? min( $max, self::URL_DOWNLOAD_MAX_BYTES ) : self::URL_DOWNLOAD_MAX_BYTES;
		if ( strlen( $decoded ) > $cap ) {
			return new \WP_Error( 'file_too_large', __( 'Decoded data exceeds size cap.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		$filename = sanitize_file_name( (string) $args['filename'] );
		$tmp      = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new \WP_Error( 'sideload_failed', __( 'Could not create temp file.', 'gk-block-api' ), array( 'status' => 500 ) );
		}
		$bytes_written = file_put_contents( $tmp, $decoded );
		if ( false === $bytes_written ) {
			@unlink( $tmp );
			return new \WP_Error( 'sideload_failed', __( 'Could not write temp file.', 'gk-block-api' ), array( 'status' => 500 ) );
		}

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( /* translators: %s: filename */ __( 'Disallowed file type for "%s".', 'gk-block-api' ), $filename ), array( 'status' => 400 ) );
		}

		$post_parent   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$file          = array( 'name' => $filename, 'tmp_name' => $tmp );
		$attachment_id = media_handle_sideload( $file, $post_parent );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}
		return $attachment_id;
	}

	/**
	 * @param int   $attachment_id
	 * @param array $args
	 */
	private function apply_metadata( $attachment_id, array $args ) {
		$updates = array( 'ID' => $attachment_id );
		if ( isset( $args['title'] ) ) {
			$updates['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$updates['post_excerpt'] = sanitize_text_field( (string) $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$updates['post_content'] = wp_kses_post( (string) $args['description'] );
		}
		if ( count( $updates ) > 1 ) {
			wp_update_post( $updates );
		}
		if ( isset( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}
	}

	/**
	 * @param int $attachment_id
	 * @return array|\WP_Error
	 */
	private function format_attachment( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return new \WP_Error( 'attachment_missing', __( 'Attachment not found after upload.', 'gk-block-api' ), array( 'status' => 500 ) );
		}
		$meta     = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		$src      = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		$filename = function_exists( 'get_attached_file' )
			? wp_basename( (string) get_attached_file( $attachment_id ) )
			: '';

		$out = array(
			'success'     => true,
			'id'          => (int) $attachment_id,
			'title'       => $post->post_title,
			'filename'    => $filename,
			'url'         => $src,
			'source_url'  => $src,
			'mime_type'   => $post->post_mime_type,
			'alt_text'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'post_parent' => (int) $post->post_parent,
		);

		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			$out['width']  = (int) $meta['width'];
			$out['height'] = (int) $meta['height'];
			$out['sizes']  = array();
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( array_keys( $meta['sizes'] ) as $size_name ) {
					$src_arr = function_exists( 'wp_get_attachment_image_src' )
						? wp_get_attachment_image_src( $attachment_id, $size_name )
						: false;
					if ( $src_arr ) {
						$out['sizes'][ $size_name ] = array(
							'url'    => $src_arr[0],
							'width'  => (int) $src_arr[1],
							'height' => (int) $src_arr[2],
						);
					}
				}
			}
			$full = function_exists( 'wp_get_attachment_image_src' )
				? wp_get_attachment_image_src( $attachment_id, 'full' )
				: false;
			if ( $full ) {
				$out['sizes']['full'] = array(
					'url'    => $full[0],
					'width'  => (int) $full[1],
					'height' => (int) $full[2],
				);
			}
		}

		return $out;
	}

	/**
	 * SSRF defense for URL sideload. Rejects URLs whose host resolves to a
	 * reserved/private/link-local IP. Cloud metadata endpoints (AWS/GCP/Azure
	 * `169.254.169.254`) and RFC1918 ranges are explicitly blocked.
	 *
	 * Site admins can extend the block list via the
	 * `gk_block_api_url_sideload_blocked_ranges` filter (returns array of
	 * `[start, end]` pairs in IPv4 dotted notation).
	 *
	 * @param string $url
	 * @return true|\WP_Error
	 */
	private function guard_ssrf( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return new \WP_Error( 'invalid_url', __( 'URL has no host.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		// Resolve A and AAAA records — both IPv4 and IPv6 must be checked.
		// A host that resolves to both a public IPv4 and link-local IPv6
		// could otherwise bypass the guard (cURL may pick IPv6 by default).
		$ipv4 = array();
		$ipv6 = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$a_records = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a_records ) ) {
				foreach ( $a_records as $r ) {
					if ( ! empty( $r['ip'] ) ) {
						$ipv4[] = $r['ip'];
					}
				}
			}
			$aaaa_records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $aaaa_records ) ) {
				foreach ( $aaaa_records as $r ) {
					if ( ! empty( $r['ipv6'] ) ) {
						$ipv6[] = $r['ipv6'];
					}
				}
			}
		}
		// Last-resort IPv4 fallback (gethostbyname is IPv4-only).
		if ( empty( $ipv4 ) && empty( $ipv6 ) ) {
			$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $resolved && filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ipv4[] = $resolved;
			}
		}
		if ( empty( $ipv4 ) && empty( $ipv6 ) ) {
			return new \WP_Error(
				'invalid_url',
				sprintf(
					/* translators: %s: hostname */
					__( 'Could not resolve host "%s".', 'gk-block-api' ),
					$host
				),
				array( 'status' => 400 )
			);
		}

		// IPv4 ranges (filterable).
		$v4_ranges = self::SSRF_BLOCKED_IPV4_RANGES;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'gk_block_api_url_sideload_blocked_ranges', $v4_ranges );
			if ( is_array( $filtered ) ) {
				$v4_ranges = $filtered;
			}
		}

		foreach ( $ipv4 as $ip ) {
			$ip_long = ip2long( $ip );
			if ( false === $ip_long ) {
				return new \WP_Error(
					'invalid_url',
					sprintf(
						/* translators: 1: IP address, 2: hostname */
						__( 'Could not validate IP "%1$s" for "%2$s".', 'gk-block-api' ),
						$ip,
						$host
					),
					array( 'status' => 400 )
				);
			}
			foreach ( $v4_ranges as $range ) {
				$start = ip2long( $range[0] );
				$end   = ip2long( $range[1] );
				if ( false !== $start && false !== $end && $ip_long >= $start && $ip_long <= $end ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: 1: hostname, 2: IPv4 address */
							__( 'URL host "%1$s" resolves to a reserved or private IPv4 (%2$s).', 'gk-block-api' ),
							$host,
							$ip
						),
						array( 'status' => 400 )
					);
				}
			}
		}

		// IPv6 reserved-range check. CIDRs cover the same classes as IPv4:
		//   ::/128             unspecified
		//   ::1/128            loopback
		//   fc00::/7           unique-local (private)
		//   fe80::/10          link-local
		//   ::ffff:0:0/96      IPv4-mapped (catch the IPv4 ranges via wrapper too)
		//   100::/64           discard-only
		//   2001::/23          IETF protocol assignments
		//   ff00::/8           multicast
		// Filterable via `gk_block_api_url_sideload_blocked_ipv6_cidrs`.
		$v6_cidrs = array(
			'::/128',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
			'::ffff:0:0/96',
			'100::/64',
			'2001::/23',
			'ff00::/8',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'gk_block_api_url_sideload_blocked_ipv6_cidrs', $v6_cidrs );
			if ( is_array( $filtered ) ) {
				$v6_cidrs = $filtered;
			}
		}

		foreach ( $ipv6 as $ip ) {
			$ip_packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $ip_packed ) {
				return new \WP_Error(
					'invalid_url',
					sprintf(
						/* translators: 1: IPv6 address, 2: hostname */
						__( 'Could not validate IPv6 "%1$s" for "%2$s".', 'gk-block-api' ),
						$ip,
						$host
					),
					array( 'status' => 400 )
				);
			}
			foreach ( $v6_cidrs as $cidr ) {
				if ( $this->ipv6_in_cidr( $ip_packed, $cidr ) ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: 1: hostname, 2: IPv6 address, 3: CIDR */
							__( 'URL host "%1$s" resolves to a reserved or private IPv6 (%2$s, in %3$s).', 'gk-block-api' ),
							$host,
							$ip,
							$cidr
						),
						array( 'status' => 400 )
					);
				}
			}
		}
		return true;
	}

	/**
	 * Test whether a packed-binary IPv6 address falls inside a CIDR block.
	 *
	 * @param string $ip_packed 16-byte binary IPv6 from `inet_pton()`.
	 * @param string $cidr      e.g. `fe80::/10`.
	 * @return bool
	 */
	private function ipv6_in_cidr( $ip_packed, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$net_packed = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$prefix     = (int) $parts[1];
		if ( false === $net_packed || $prefix < 0 || $prefix > 128 ) {
			return false;
		}
		// Build a 16-byte mask with `$prefix` MSBs set.
		$mask     = str_repeat( "\xff", intdiv( $prefix, 8 ) );
		$rem_bits = $prefix % 8;
		if ( $rem_bits ) {
			$mask .= chr( 0xff << ( 8 - $rem_bits ) & 0xff );
		}
		$mask = str_pad( $mask, 16, "\x00" );
		return ( $ip_packed & $mask ) === ( $net_packed & $mask );
	}

	private function require_admin_includes() {
		if ( defined( 'ABSPATH' ) && is_dir( ABSPATH . 'wp-admin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
