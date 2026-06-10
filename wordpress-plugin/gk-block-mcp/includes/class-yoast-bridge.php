<?php
/**
 * Yoast SEO REST bridge — read and write post SEO metadata for Yoast SEO.
 *
 * Registers (only when Yoast SEO is active):
 *   GET|PATCH  /gk-block-api/v1/yoast/{post_id}
 *   PATCH      /gk-block-api/v1/yoast/bulk
 *
 * Storage formats match Yoast's own conventions
 * (see wordpress-seo/inc/class-wpseo-meta.php).
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO bridge — Yoast post-meta read/write over REST.
 *
 * No external plugin dependencies beyond Yoast SEO itself. Routes only
 * register when WPSEO_FILE is defined, so a clean install of gk-block-mcp
 * without Yoast SEO contributes zero routes.
 */
class Yoast_Bridge {

	/**
	 * REST namespace shared with the rest of gk-block-mcp.
	 */
	const REST_NAMESPACE = 'gk-block-api/v1';

	/**
	 * Register REST routes if Yoast SEO is active. Called from the plugin
	 * bootstrap on rest_api_init.
	 */
	public function register_routes() {
		if ( ! self::is_yoast_active() ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/yoast/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_seo' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'post_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_seo' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => self::patch_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/yoast/bulk',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'bulk_update_seo' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'posts' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'object' ),
					),
				),
			)
		);
	}

	/**
	 * Permission check — must be able to edit the post (or any post for bulk).
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return bool
	 */
	public function check_permissions( \WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		}

		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /yoast/{post_id} — return all Yoast SEO fields for a post.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $this->read_fields( $post_id ), 200 );
	}

	/**
	 * PATCH /yoast/{post_id} — update Yoast fields on a single post.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_seo( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();

		if ( ! is_array( $params ) || empty( $params ) ) {
			return new \WP_Error(
				'invalid_body',
				__( 'Request body must be a JSON object with fields to update.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->write_fields( $post_id, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $this->read_fields( $post_id ), 200 );
	}

	/**
	 * PATCH /yoast/bulk — update SEO fields for multiple posts.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error WP_Error returned when the batch
	 *                                    exceeds MAX_BATCH_SIZE; otherwise the
	 *                                    per-post results envelope.
	 */
	public function bulk_update_seo( \WP_REST_Request $request ) {
		$posts = (array) $request->get_param( 'posts' );

		// Cap batch size. An authenticated `edit_posts` user without per-post
		// permission would still be allowed to send an unbounded `posts` array
		// (each entry generates DB queries via write_fields + read_fields and
		// fires Yoast hooks), enabling cheap resource amplification. Match
		// Block_CRUD::MAX_BATCH_SIZE for parity with batch-update.
		if ( count( $posts ) > \GravityKit\BlockMCP\Block_CRUD::MAX_BATCH_SIZE ) {
			return new \WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: 1: actual batch size, 2: maximum batch size */
					__( 'Bulk SEO batch contains %1$d items; maximum is %2$d.', 'gk-block-mcp' ),
					count( $posts ),
					\GravityKit\BlockMCP\Block_CRUD::MAX_BATCH_SIZE
				),
				array(
					'status'         => 400,
					'max_batch_size' => \GravityKit\BlockMCP\Block_CRUD::MAX_BATCH_SIZE,
				)
			);
		}

		$results = array();

		foreach ( $posts as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['post_id'] ) ) {
				$results[] = array( 'error' => __( 'Missing post_id.', 'gk-block-mcp' ) );
				continue;
			}

			$post_id = (int) $entry['post_id'];

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'error'   => __( 'Permission denied.', 'gk-block-mcp' ),
				);
				continue;
			}

			if ( ! get_post( $post_id ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'error'   => __( 'Post not found.', 'gk-block-mcp' ),
				);
				continue;
			}

			$fields = $entry;
			unset( $fields['post_id'] );

			$write = $this->write_fields( $post_id, $fields );
			if ( is_wp_error( $write ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'error'   => $write->get_error_message(),
				);
				continue;
			}

			$results[] = $this->read_fields( $post_id );
		}

		return new \WP_REST_Response( $results, 200 );
	}

	/**
	 * Read all Yoast SEO fields for a post and return normalized values.
	 *
	 * Tri-state noindex: null = post-type default, true = noindex, false = explicit index.
	 * Boolean nofollow: true = nofollow, false = follow (default).
	 * Boolean is_cornerstone: true/false.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, mixed>
	 */
	protected function read_fields( $post_id ) {
		$post_id      = (int) $post_id;
		$field_map    = self::field_map();
		$array_fields = self::array_fields();
		$data         = array( 'post_id' => $post_id );

		foreach ( $field_map as $field => $meta_key ) {
			$raw = get_post_meta( $post_id, $meta_key, true );

			if ( 'noindex' === $field ) {
				if ( '1' === $raw ) {
					$data[ $field ] = true;
				} elseif ( '2' === $raw ) {
					$data[ $field ] = false;
				} else {
					$data[ $field ] = null;
				}
			} elseif ( 'nofollow' === $field ) {
				$data[ $field ] = ( '1' === $raw );
			} elseif ( 'is_cornerstone' === $field ) {
				$data[ $field ] = ( '1' === $raw );
			} elseif ( in_array( $field, $array_fields, true ) ) {
				$data[ $field ] = ( '' === $raw || '-' === $raw )
					? array()
					: array_values( array_filter( explode( ',', $raw ) ) );
			} elseif ( in_array( $field, array( 'og_image_id', 'twitter_image_id' ), true ) ) {
				$data[ $field ] = ( '' !== $raw ) ? (int) $raw : null;
			} else {
				$data[ $field ] = (string) $raw;
			}
		}

		// Read-only score fields (Yoast's content analysis output).
		foreach ( self::readonly_fields() as $field => $meta_key ) {
			$raw            = get_post_meta( $post_id, $meta_key, true );
			$data[ $field ] = ( '' !== $raw ) ? (int) $raw : null;
		}

		// Primary terms (taxonomy-dependent).
		$post_type     = get_post_type( $post_id );
		$taxonomies    = $post_type ? get_object_taxonomies( $post_type, 'names' ) : array();
		$primary_terms = array();

		foreach ( $taxonomies as $taxonomy ) {
			$meta_key = '_yoast_wpseo_primary_' . $taxonomy;
			$value    = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $value ) {
				$primary_terms[ $taxonomy ] = (int) $value;
			}
		}
		$data['primary_terms'] = $primary_terms;

		return $data;
	}

	/**
	 * Write Yoast SEO fields to post meta.
	 *
	 * Storage formats match Yoast's own conventions:
	 * - noindex: "1" (noindex), "2" (explicit index), delete meta for default
	 * - nofollow: "1" (nofollow), delete meta for follow
	 * - is_cornerstone: "1" (true); meta is DELETED to disable. The literal
	 *   string "false" is truthy in PHP, so the disable path must remove
	 *   the meta key rather than write any string value.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $fields  Field name => value pairs.
	 *
	 * @return true|\WP_Error
	 */
	protected function write_fields( $post_id, array $fields ) {
		$post_id      = (int) $post_id;
		$field_map    = self::field_map();
		$array_fields = self::array_fields();

		// primary_terms is a taxonomy => term_id object, handled separately.
		if ( isset( $fields['primary_terms'] ) && is_array( $fields['primary_terms'] ) ) {
			$post_type   = get_post_type( $post_id );
			$valid_taxes = $post_type ? get_object_taxonomies( $post_type, 'names' ) : array();

			foreach ( $fields['primary_terms'] as $taxonomy => $term_id ) {
				$taxonomy = sanitize_key( (string) $taxonomy );

				if ( ! in_array( $taxonomy, $valid_taxes, true ) ) {
					continue;
				}

				$term = get_term( absint( $term_id ), $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}

				update_post_meta( $post_id, '_yoast_wpseo_primary_' . $taxonomy, $term->term_id );
			}
			unset( $fields['primary_terms'] );
		}

		// Read-only fields can never be written through this endpoint.
		$readonly = array_keys( self::readonly_fields() );

		foreach ( $fields as $field => $value ) {
			if ( ! isset( $field_map[ $field ] ) || in_array( $field, $readonly, true ) ) {
				continue;
			}

			$meta_key = $field_map[ $field ];

			if ( 'noindex' === $field ) {
				if ( true === $value ) {
					update_post_meta( $post_id, $meta_key, '1' );
				} elseif ( false === $value ) {
					update_post_meta( $post_id, $meta_key, '2' );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
				continue;
			}

			if ( 'nofollow' === $field ) {
				if ( $value ) {
					update_post_meta( $post_id, $meta_key, '1' );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
				continue;
			}

			if ( 'is_cornerstone' === $field ) {
				// Yoast convention: meta = '1' to enable, MISSING (or empty)
				// to disable. The previous code stored the literal string
				// 'false' on disable, which PHP treats as truthy — so
				// toggling cornerstone off via this API silently left it
				// enabled in Yoast's view. Match the same pattern nofollow
				// uses above: write '1' to enable, delete to disable.
				if ( $value ) {
					update_post_meta( $post_id, $meta_key, '1' );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
				continue;
			}

			if ( in_array( $field, $array_fields, true ) ) {
				if ( is_array( $value ) ) {
					$allowed = self::robots_advanced_allowed();
					$value   = implode( ',', array_intersect( $value, $allowed ) );
				}
				$value = sanitize_text_field( (string) $value );
			} elseif ( in_array( $field, array( 'canonical', 'og_image', 'twitter_image', 'redirect' ), true ) ) {
				$value = esc_url_raw( (string) $value );
			} elseif ( in_array( $field, array( 'og_image_id', 'twitter_image_id' ), true ) ) {
				if ( empty( $value ) ) {
					delete_post_meta( $post_id, $meta_key );
					continue;
				}
				$value = absint( $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		// Mirror Yoast's own metabox-save action so downstream listeners (sitemap
		// rebuild, indexable update, etc.) fire as if the field changed in wp-admin.
		// Yoast owns the `wpseo_` prefix; the unprefixed-hookname warning is
		// expected and intentional.
		if ( self::is_yoast_active() ) {
			do_action( 'wpseo_saved_postdata' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-emitting Yoast's own hook on its behalf.
		}

		return true;
	}

	/**
	 * True iff the Yoast SEO plugin is loaded.
	 */
	public static function is_yoast_active() {
		return defined( 'WPSEO_FILE' );
	}

	/**
	 * Public field name → Yoast meta key.
	 *
	 * @return array<string, string>
	 */
	protected static function field_map() {
		return array(
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'canonical'           => '_yoast_wpseo_canonical',
			'focus_keyword'       => '_yoast_wpseo_focuskw',
			'noindex'             => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'            => '_yoast_wpseo_meta-robots-nofollow',
			'robots_advanced'     => '_yoast_wpseo_meta-robots-adv',
			'og_title'            => '_yoast_wpseo_opengraph-title',
			'og_description'      => '_yoast_wpseo_opengraph-description',
			'og_image'            => '_yoast_wpseo_opengraph-image',
			'og_image_id'         => '_yoast_wpseo_opengraph-image-id',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image'       => '_yoast_wpseo_twitter-image',
			'twitter_image_id'    => '_yoast_wpseo_twitter-image-id',
			'schema_page_type'    => '_yoast_wpseo_schema_page_type',
			'schema_article_type' => '_yoast_wpseo_schema_article_type',
			'is_cornerstone'      => '_yoast_wpseo_is_cornerstone',
			'breadcrumb_title'    => '_yoast_wpseo_bctitle',
			'redirect'            => '_yoast_wpseo_redirect',
		);
	}

	/**
	 * Fields stored as comma-separated strings in the DB but exposed as arrays.
	 *
	 * @return string[]
	 */
	protected static function array_fields() {
		return array( 'robots_advanced' );
	}

	/**
	 * Allowed values for robots_advanced — mirror of
	 * WPSEO_Meta::$meta_fields['advanced']['meta-robots-adv']['options'].
	 *
	 * @return string[]
	 */
	protected static function robots_advanced_allowed() {
		return array( 'noimageindex', 'noarchive', 'nosnippet' );
	}

	/**
	 * Valid schema page types from Yoast's Schema_Types helper.
	 *
	 * @return string[]
	 */
	protected static function schema_page_types() {
		return array(
			'WebPage',
			'ItemPage',
			'AboutPage',
			'FAQPage',
			'QAPage',
			'ProfilePage',
			'ContactPage',
			'MedicalWebPage',
			'CollectionPage',
			'CheckoutPage',
			'RealEstateListing',
			'SearchResultsPage',
		);
	}

	/**
	 * Valid schema article types from Yoast's Schema_Types helper.
	 *
	 * @return string[]
	 */
	protected static function schema_article_types() {
		return array(
			'Article',
			'BlogPosting',
			'SocialMediaPosting',
			'NewsArticle',
			'AdvertiserContentArticle',
			'SatiricalArticle',
			'ScholarlyArticle',
			'TechArticle',
			'Report',
			'None',
		);
	}

	/**
	 * Read-only score fields included in GET responses.
	 *
	 * @return array<string, string>
	 */
	protected static function readonly_fields() {
		return array(
			'seo_score'                => '_yoast_wpseo_linkdex',
			'readability_score'        => '_yoast_wpseo_content_score',
			'inclusive_language_score' => '_yoast_wpseo_inclusive_language_score',
		);
	}

	/**
	 * Argument schema for the PATCH endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function patch_args() {
		return array(
			'post_id'             => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'title'               => array( 'type' => 'string' ),
			'description'         => array( 'type' => 'string' ),
			'canonical'           => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'focus_keyword'       => array( 'type' => 'string' ),
			'noindex'             => array( 'type' => array( 'boolean', 'null' ) ),
			'nofollow'            => array( 'type' => 'boolean' ),
			'robots_advanced'     => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => self::robots_advanced_allowed(),
				),
			),
			'og_title'            => array( 'type' => 'string' ),
			'og_description'      => array( 'type' => 'string' ),
			'og_image'            => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'og_image_id'         => array( 'type' => 'integer' ),
			'twitter_title'       => array( 'type' => 'string' ),
			'twitter_description' => array( 'type' => 'string' ),
			'twitter_image'       => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'twitter_image_id'    => array( 'type' => 'integer' ),
			'schema_page_type'    => array(
				'type' => 'string',
				'enum' => self::schema_page_types(),
			),
			'schema_article_type' => array(
				'type' => 'string',
				'enum' => self::schema_article_types(),
			),
			'is_cornerstone'      => array( 'type' => 'boolean' ),
			'breadcrumb_title'    => array( 'type' => 'string' ),
			'redirect'            => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'primary_terms'       => array(
				'type'                 => 'object',
				'additionalProperties' => array( 'type' => 'integer' ),
			),
		);
	}
}
