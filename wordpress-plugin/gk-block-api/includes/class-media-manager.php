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
				'Provide one of: multipart "file" field, "url", or "data_base64".',
				array( 'status' => 400 )
			);
		}
		if ( $mode_count > 1 ) {
			return new \WP_Error(
				'multiple_inputs',
				'Only one of "file", "url", or "data_base64" may be supplied.',
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
		$mime = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $mime['type'] ) ) {
			return new \WP_Error(
				'disallowed_mime',
				sprintf( 'Disallowed file type for "%s".', sanitize_file_name( $file['name'] ) ),
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
			return new \WP_Error( 'invalid_url', 'URL is not valid or not allowed.', array( 'status' => 400 ) );
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'url_fetch_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}

		if ( filesize( $tmp ) > self::URL_DOWNLOAD_MAX_BYTES ) {
			@unlink( $tmp );
			return new \WP_Error( 'file_too_large', 'Downloaded file exceeds size cap.', array( 'status' => 400 ) );
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $path ? basename( (string) $path ) : 'remote-file';
		$filename = isset( $args['filename'] )
			? sanitize_file_name( (string) $args['filename'] )
			: sanitize_file_name( $basename );

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( 'Disallowed file type for "%s".', $filename ), array( 'status' => 400 ) );
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
			return new \WP_Error( 'invalid_filename', '"filename" is required for base64 uploads.', array( 'status' => 400 ) );
		}
		$decoded = base64_decode( $args['data_base64'], true );
		if ( false === $decoded || '' === $decoded ) {
			return new \WP_Error( 'invalid_base64', 'data_base64 is not valid base64.', array( 'status' => 400 ) );
		}

		$filename = sanitize_file_name( (string) $args['filename'] );
		$tmp      = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new \WP_Error( 'sideload_failed', 'Could not create temp file.', array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, $decoded );

		$max = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $max > 0 && filesize( $tmp ) > $max ) {
			@unlink( $tmp );
			return new \WP_Error( 'file_too_large', 'Uploaded file exceeds the site upload limit.', array( 'status' => 400 ) );
		}

		$mime = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $mime['type'] ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'disallowed_mime', sprintf( 'Disallowed file type for "%s".', $filename ), array( 'status' => 400 ) );
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
			return new \WP_Error( 'attachment_missing', 'Attachment not found after upload.', array( 'status' => 500 ) );
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

	private function require_admin_includes() {
		if ( defined( 'ABSPATH' ) && is_dir( ABSPATH . 'wp-admin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
