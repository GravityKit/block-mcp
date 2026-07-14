<?php
/**
 * Rank Math SEO REST bridge — read and write post SEO metadata for Rank Math.
 *
 * The RankMath counterpart to Yoast_Bridge. Registers (only when Rank Math is
 * active):
 *   GET|PATCH  /gk-block-api/v1/rank-math/{post_id}
 *   PATCH      /gk-block-api/v1/rank-math/bulk
 *
 * Storage formats and meta keys match Rank Math's own conventions (verified
 * against its importer field map and Rest\Sanitize). Values are sanitized
 * through Rank Math's own Sanitize service when available, so writes are
 * indistinguishable from a metabox save.
 *
 * When Rank Math ships a native write ability (rank-math/update-post-seo-meta),
 * update paths delegate to it; until then this bridge owns the meta logic.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank Math SEO bridge — Rank Math post-meta read/write over REST.
 *
 * No external plugin dependencies beyond Rank Math itself. Routes only
 * register when RANK_MATH_FILE is defined, so a clean install of gk-block-mcp
 * without Rank Math contributes zero routes.
 */
class Rank_Math_Bridge {

	/**
	 * REST namespace shared with the rest of gk-block-mcp.
	 */
	const REST_NAMESPACE = 'gk-block-api/v1';

	/**
	 * Register REST routes if Rank Math is active. Called from the plugin
	 * bootstrap on rest_api_init.
	 */
	public function register_routes() {
		if ( ! self::is_rank_math_active() ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/rank-math/(?P<post_id>\d+)',
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
			'/rank-math/bulk',
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
	 * GET /rank-math/{post_id} — return all Rank Math SEO fields for a post.
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
	 * PATCH /rank-math/{post_id} — update Rank Math fields on a single post.
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
	 * PATCH /rank-math/bulk — update SEO fields for multiple posts.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error WP_Error returned when the batch
	 *                                    exceeds MAX_BATCH_SIZE; otherwise the
	 *                                    per-post results envelope.
	 */
	public function bulk_update_seo( \WP_REST_Request $request ) {
		$posts = (array) $request->get_param( 'posts' );

		// Cap batch size — parity with Yoast_Bridge and Block_CRUD::MAX_BATCH_SIZE
		// to prevent unbounded resource amplification by an edit_posts user.
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
	 * Read all Rank Math SEO fields for a post.
	 *
	 * Robots is stored as an array of directive strings (index, noindex,
	 * nofollow, …). All other fields are plain strings.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<string, mixed>
	 */
	protected function read_fields( $post_id ) {
		$post_id   = (int) $post_id;
		$field_map = self::field_map();
		$data      = array( 'post_id' => $post_id );

		foreach ( $field_map as $field => $meta_key ) {
			$raw = get_post_meta( $post_id, $meta_key, true );

			if ( 'robots' === $field ) {
				$data[ $field ] = is_array( $raw ) ? array_values( $raw ) : array();
			} else {
				$data[ $field ] = (string) $raw;
			}
		}

		// Read-only score (Rank Math's content analysis output).
		$score              = get_post_meta( $post_id, 'rank_math_seo_score', true );
		$data['seo_score']  = ( '' !== $score ) ? (int) $score : null;

		return $data;
	}

	/**
	 * Write Rank Math SEO fields to post meta.
	 *
	 * Values are sanitized through Rank Math's own Sanitize service (the same
	 * path its REST metadata endpoint uses) so stored values match a metabox
	 * save. An empty/null value clears the meta.
	 *
	 * When Rank Math's native update ability is present, delegate to it so we
	 * inherit any future behavior it adds.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $fields  Field name => value pairs.
	 *
	 * @return true|\WP_Error
	 */
	protected function write_fields( $post_id, array $fields ) {
		$post_id   = (int) $post_id;
		$field_map = self::field_map();
		$readonly  = array_keys( self::readonly_fields() );

		// Prefer Rank Math's native write ability for the fields it covers (once
		// its PR lands on this site), then direct-write any remaining fields so
		// nothing is silently dropped. Delegation is best-effort: on error we
		// surface it; otherwise we still direct-write the fields the native
		// ability doesn't map, guaranteeing every supplied field is persisted.
		$fields = $this->maybe_delegate_native( $post_id, $fields );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$sanitizer = self::sanitizer();

		foreach ( $fields as $field => $value ) {
			if ( ! isset( $field_map[ $field ] ) || in_array( $field, $readonly, true ) ) {
				continue;
			}

			$meta_key = $field_map[ $field ];

			// Empty value clears the meta, matching Rank Math's REST save behavior.
			if ( '' === $value || null === $value || ( is_array( $value ) && empty( $value ) ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			$clean = $sanitizer ? $sanitizer->sanitize( $meta_key, $value ) : self::fallback_sanitize( $field, $value );
			update_post_meta( $post_id, $meta_key, $clean );
		}

		// Direct meta writes bypass save_post, so Rank Math's sitemap cache
		// watcher (hooked on save_post) never invalidates. Clear the post cache
		// and re-emit save_post so the sitemap + other listeners refresh as if
		// the fields changed in wp-admin.
		if ( self::is_rank_math_active() ) {
			clean_post_cache( $post_id );
			$post = get_post( $post_id );
			if ( $post ) {
				do_action( 'save_post', $post_id, $post, true );
			}
		}

		return true;
	}

	/**
	 * Best-effort delegation to Rank Math's native update ability.
	 *
	 * When the native ability is present, hand it the subset of fields it maps
	 * and — regardless of outcome — RETURN THE REMAINING fields so the caller
	 * always direct-writes anything the ability didn't handle. This guarantees
	 * every supplied field is persisted: the native ability is an optimization,
	 * never the sole writer, so a divergent or partial native contract can never
	 * silently drop data. A hard error from the ability is surfaced as WP_Error.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $fields  Field name => value pairs.
	 *
	 * @return array<string, mixed>|\WP_Error Remaining fields to direct-write, or WP_Error.
	 */
	protected function maybe_delegate_native( $post_id, array $fields ) {
		if ( ! function_exists( 'wp_get_ability' ) || ! self::native_update_ability_available() ) {
			return $fields;
		}

		$ability = wp_get_ability( 'rank-math/update-post-seo-meta' );
		if ( ! $ability ) {
			return $fields;
		}

		$native_map = self::native_field_map();
		$input      = array( 'post_id' => $post_id );
		$delegated  = array();

		foreach ( $native_map as $ours => $theirs ) {
			if ( array_key_exists( $ours, $fields ) ) {
				$input[ $theirs ]    = $fields[ $ours ];
				$delegated[ $ours ]  = true;
			}
		}

		// Nothing the native ability can take — direct-write everything.
		if ( count( $input ) === 1 ) {
			return $fields;
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			return new \WP_Error( 'rank_math_ability_error', (string) $result['error'], array( 'status' => 400 ) );
		}

		// Re-read to confirm the native ability actually persisted each delegated
		// field; anything it silently skipped stays in the return set for a
		// direct write. No trust in the native contract — verify by storage.
		$field_map = self::field_map();
		$remaining = array();
		foreach ( $fields as $field => $value ) {
			if ( ! isset( $delegated[ $field ] ) || ! isset( $field_map[ $field ] ) ) {
				$remaining[ $field ] = $value;
				continue;
			}
			$stored   = get_post_meta( $post_id, $field_map[ $field ], true );
			$expected = ( '' === $value || null === $value || ( is_array( $value ) && empty( $value ) ) );
			$missing  = ( '' === $stored || array() === $stored || null === $stored );
			// If clearing was requested but meta still set, or a value was
			// requested but nothing stored, direct-write it ourselves.
			if ( $expected !== $missing ) {
				$remaining[ $field ] = $value;
			}
		}

		return $remaining;
	}

	/**
	 * True iff the Rank Math plugin is loaded.
	 */
	public static function is_rank_math_active() {
		return defined( 'RANK_MATH_FILE' );
	}

	/**
	 * True iff Rank Math's native update ability is registered.
	 */
	protected static function native_update_ability_available() {
		return function_exists( 'wp_has_ability' ) && wp_has_ability( 'rank-math/update-post-seo-meta' );
	}

	/**
	 * Rank Math's own Sanitize service, or null if unavailable.
	 *
	 * @return object|null
	 */
	protected static function sanitizer() {
		$class = '\RankMath\Rest\Sanitize';
		if ( class_exists( $class ) && method_exists( $class, 'get' ) ) {
			return $class::get();
		}
		return null;
	}

	/**
	 * Fallback sanitizer used only if Rank Math's Sanitize is unreachable.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 *
	 * @return mixed
	 */
	protected static function fallback_sanitize( $field, $value ) {
		if ( 'robots' === $field ) {
			return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
		}
		if ( 'canonical' === $field ) {
			return esc_url_raw( (string) $value );
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Public field name → Rank Math meta key.
	 *
	 * Keys verified against Rank Math's importer field map
	 * (includes/admin/importers/class-aioseo.php) and Singular::get_seo_meta().
	 *
	 * @return array<string, string>
	 */
	protected static function field_map() {
		return array(
			'title'               => 'rank_math_title',
			'description'         => 'rank_math_description',
			'focus_keyword'       => 'rank_math_focus_keyword',
			'canonical'           => 'rank_math_canonical_url',
			'robots'              => 'rank_math_robots',
			'og_title'            => 'rank_math_facebook_title',
			'og_description'      => 'rank_math_facebook_description',
			'twitter_title'       => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
		);
	}

	/**
	 * Subset of fields the native update ability accepts, mapped to its input
	 * names. (The native ability uses the same names, but keep this explicit so
	 * the delegation contract is auditable and can diverge safely.)
	 *
	 * @return array<string, string>
	 */
	protected static function native_field_map() {
		return array(
			'title'               => 'title',
			'description'         => 'description',
			'focus_keyword'       => 'focus_keyword',
			'canonical'           => 'canonical',
			'robots'              => 'robots',
			'og_title'            => 'og_title',
			'og_description'      => 'og_description',
			'twitter_title'       => 'twitter_title',
			'twitter_description' => 'twitter_description',
		);
	}

	/**
	 * Read-only score fields included in GET responses.
	 *
	 * @return array<string, string>
	 */
	protected static function readonly_fields() {
		return array(
			'seo_score' => 'rank_math_seo_score',
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
			'focus_keyword'       => array( 'type' => 'string' ),
			'canonical'           => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'robots'              => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'og_title'            => array( 'type' => 'string' ),
			'og_description'      => array( 'type' => 'string' ),
			'twitter_title'       => array( 'type' => 'string' ),
			'twitter_description' => array( 'type' => 'string' ),
		);
	}
}
