<?php
/**
 * Read-only Full Site Editing (FSE) template + template-part discovery.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Template_Manager
 *
 * Thin wrapper around WordPress's block-template APIs (`get_block_templates()`,
 * `get_block_template()`) so agents can discover a block theme's templates and
 * template parts, and read one's block content, the same way the Site Editor
 * does. Read-only: writing/overriding a template is a separate, gated surface.
 *
 * @since 2.2.0
 */
class Template_Manager {

	/**
	 * Template post types this class ever queries. `wp_global_styles` and
	 * `wp_navigation` are also `wp_theme`-tagged block-editor post types, but
	 * are out of scope here — they aren't "templates" an agent edits page
	 * layout through.
	 *
	 * @since 2.2.0
	 * @var string[]
	 */
	const TEMPLATE_TYPES = array( 'wp_template', 'wp_template_part' );

	/**
	 * Option name for the "let the assistant edit theme templates" toggle.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	const ALLOW_TEMPLATE_EDITS_OPTION = 'gk_block_api_template_edits';

	/**
	 * Block CRUD service, used to format raw block markup the same way
	 * get_page_blocks() formats a post's content, and to write it back
	 * through the standard block-write validation/save pipeline.
	 *
	 * @since 2.2.0
	 * @var Block_CRUD
	 */
	private $block_crud;

	/**
	 * Constructor.
	 *
	 * @since 2.2.0
	 *
	 * @param Block_CRUD $block_crud Block CRUD service (read-path formatting).
	 */
	public function __construct( Block_CRUD $block_crud ) {
		$this->block_crud = $block_crud;
	}

	/**
	 * Whether the assistant is allowed to edit theme templates and parts.
	 *
	 * Off by default. Unlike trashing, this has no matching capability gap to
	 * close after the toggle: the agent's `edit_posts` is already enough to
	 * write these post types, and the actual template-editing REST routes
	 * additionally require this toggle (or `edit_theme_options`) via
	 * `gk/block-mcp/templates/allow-edits`. Site-editable in Settings.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function edits_enabled() {
		$enabled = (bool) get_option( self::ALLOW_TEMPLATE_EDITS_OPTION, false );

		/**
		 * Control whether the AI assistant may edit theme templates and
		 * template parts.
		 *
		 * Off by default. A template edit creates a database override —
		 * the theme file itself is never touched, and Appearance → Editor
		 * (or reset_template) reverts it. Return true to permit editing,
		 * false to forbid it regardless of the stored option.
		 *
		 * @since 2.2.0
		 *
		 * @example
		 * // Let the assistant edit templates only on the staging site.
		 * add_filter( 'gk/block-mcp/templates/allow-edits', function ( $enabled ) {
		 *     return wp_get_environment_type() === 'staging' ? true : $enabled;
		 * } );
		 *
		 * @param bool $enabled Whether editing is currently allowed by the stored option.
		 */
		return (bool) apply_filters( 'gk/block-mcp/templates/allow-edits', $enabled );
	}

	/**
	 * List templates or template parts for the active theme.
	 *
	 * @since 2.2.0
	 *
	 * @param array $args {
	 *     Optional. Listing filters.
	 *
	 *     @type string $type      'wp_template' or 'wp_template_part'. Default 'wp_template'.
	 *     @type string $area      Template-part area (parts only, e.g. 'header').
	 *     @type string $post_type Scope 'wp_template' results to templates usable by this post type.
	 *     @type string $slug      Comma-separated slugs to match exactly.
	 *     @type string $source    'theme' | 'plugin' | 'custom'. Post-filter (get_block_templates() doesn't accept it).
	 * }
	 * @return array|\WP_Error
	 */
	public function get_templates( array $args ) {
		$type = $this->sanitize_type( isset( $args['type'] ) ? $args['type'] : null );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$query = array();

		if ( 'wp_template_part' === $type && ! empty( $args['area'] ) ) {
			$query['area'] = sanitize_key( $args['area'] );
		}

		if ( ! empty( $args['post_type'] ) ) {
			$query['post_type'] = sanitize_key( $args['post_type'] );
		}

		if ( ! empty( $args['slug'] ) ) {
			$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $args['slug'] ) ) );
			if ( ! empty( $slugs ) ) {
				$query['slug__in'] = array_values( $slugs );
			}
		}

		$templates = get_block_templates( $query, $type );

		$source = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : '';
		if ( '' !== $source ) {
			$templates = array_values(
				array_filter(
					$templates,
					static function ( $template ) use ( $source ) {
						return $source === $template->source;
					}
				)
			);
		}

		$formatted = array_map( array( $this, 'format_template_summary' ), $templates );

		$result = array(
			'templates' => $formatted,
			'count'     => count( $formatted ),
		);

		// A hybrid theme (wp_is_block_theme() false, e.g. no templates/index.html)
		// can still have real templates/parts get_block_templates() finds via
		// theme files or DB overrides, so an empty result alone doesn't mean
		// "not a block theme" — only note that when it's also actually true.
		$has_no_results        = empty( $formatted );
		$theme_is_not_fse_full = ! wp_is_block_theme();
		if ( $has_no_results && $theme_is_not_fse_full ) {
			$result['note'] = __( 'Active theme is not a full block theme; only registered block templates/parts are listed.', 'gk-block-mcp' );
		}

		return $result;
	}

	/**
	 * Get a single template or template part, including its raw content and
	 * parsed blocks.
	 *
	 * @since 2.2.0
	 *
	 * @param string $id   Template ID (`theme_slug//template_slug`).
	 * @param string $type 'wp_template' or 'wp_template_part'. Default 'wp_template'.
	 * @return array|\WP_Error
	 */
	public function get_template( $id, $type = 'wp_template' ) {
		$type = $this->sanitize_type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$id = is_string( $id ) ? sanitize_text_field( $id ) : '';
		if ( '' === $id ) {
			return new \WP_Error(
				'missing_id',
				__( 'A template "id" is required (e.g. "twentytwentyfive//index").', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$template = get_block_template( $id, $type );
		if ( ! $template ) {
			return new \WP_Error(
				'not_found',
				sprintf( /* translators: %s: template id */ __( 'Template "%s" not found.', 'gk-block-mcp' ), $id ),
				array( 'status' => 404 )
			);
		}

		$data            = $this->format_template_summary( $template );
		$data['content'] = (string) $template->content;
		$data['blocks']  = $this->block_crud->format_content_blocks( $template->content );

		return $data;
	}

	/**
	 * Replace a template or template part's entire content — whole-template
	 * replacement, the same semantics as rewrite_post_blocks, not a
	 * per-block edit.
	 *
	 * When a database override already exists (`wp_id` set), its post is
	 * updated in place. Otherwise an override is created the way the Site
	 * Editor does: `wp_insert_post()` followed by the `wp_theme` taxonomy
	 * term (mandatory — the override does not attach to the active theme
	 * without it) and, for parts, the `wp_template_part_area` term. If
	 * applying the new content then fails, a freshly-created override is
	 * rolled back so a rejected write never leaves an empty shell behind.
	 *
	 * `blocks` input goes through the same registry/tier/dual-storage
	 * validation as every other structured-block write (legacy-tier blocks
	 * are rejected). `content` (raw markup) is sanitized with
	 * `wp_kses_post()` — for a caller without `unfiltered_html`, WordPress's
	 * own `content_save_pre` filter chain applies the same sanitization a
	 * second time on save, so script/embed-heavy markup may come back
	 * stripped even when this call reports success.
	 *
	 * @since 2.2.0
	 *
	 * @param string $id   Template ID (`theme_slug//template_slug`).
	 * @param string $type 'wp_template' or 'wp_template_part'. Default 'wp_template'.
	 * @param array  $args {
	 *     Exactly one of `content` or `blocks`.
	 *
	 *     @type string $content Raw block markup to replace the template with.
	 *     @type array  $blocks  Structured blocks to replace the template with.
	 * }
	 * @return array|\WP_Error
	 */
	public function update_template( $id, $type, array $args ) {
		$gate_check = $this->check_edits_enabled();
		if ( is_wp_error( $gate_check ) ) {
			return $gate_check;
		}

		$type = $this->sanitize_type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$id = is_string( $id ) ? sanitize_text_field( $id ) : '';
		if ( '' === $id ) {
			return new \WP_Error(
				'missing_id',
				__( 'A template "id" is required (e.g. "twentytwentyfive//index").', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$has_content = isset( $args['content'] ) && is_string( $args['content'] );
		$has_blocks  = isset( $args['blocks'] ) && is_array( $args['blocks'] );
		if ( $has_content === $has_blocks ) {
			return new \WP_Error(
				'invalid_input',
				__( 'Provide exactly one of "content" or "blocks".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Gate on whether the id actually resolves, not wp_is_block_theme():
		// a hybrid theme (no templates/index.html) can still have real,
		// resolvable templates/parts, and those are meaningful to edit.
		$template = get_block_template( $id, $type );
		if ( ! $template ) {
			// classic_theme is reserved for a genuinely classic theme (no
			// templates/parts at all) — a hybrid theme with real content
			// elsewhere just has the wrong id, which not_found says
			// accurately; classic_theme would falsely claim nothing exists.
			$is_genuinely_classic = ! wp_is_block_theme() && ! $this->theme_has_any_block_templates();
			if ( $is_genuinely_classic ) {
				return new \WP_Error(
					'classic_theme',
					__( 'Active theme is not a block theme; there are no block templates to edit.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			return new \WP_Error(
				'not_found',
				sprintf( /* translators: %s: template id */ __( 'Template "%s" not found.', 'gk-block-mcp' ), $id ),
				array( 'status' => 404 )
			);
		}

		$post_id          = ! empty( $template->wp_id ) ? (int) $template->wp_id : 0;
		$override_created = false;

		if ( ! $post_id ) {
			$insert = wp_insert_post(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_name'    => $template->slug,
					'post_title'   => $this->resolve_title( $template ),
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $insert ) ) {
				return $insert;
			}
			$post_id          = (int) $insert;
			$override_created = true;

			// Mandatory: without the wp_theme term the override is orphaned
			// and never shadows the theme file (get_block_templates() finds
			// it only via a tax_query on this term). If term assignment
			// fails, delete the freshly-created post rather than leaving
			// an untraceable orphan behind.
			$theme_term        = wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
			$theme_term_failed = is_wp_error( $theme_term );
			if ( $theme_term_failed ) {
				return $this->rollback_override( $post_id, $theme_term );
			}

			if ( 'wp_template_part' === $type ) {
				$area             = ! empty( $template->area ) ? (string) $template->area : WP_TEMPLATE_PART_AREA_UNCATEGORIZED;
				$area_term        = wp_set_object_terms( $post_id, _filter_block_template_part_area( $area ), 'wp_template_part_area' );
				$area_term_failed = is_wp_error( $area_term );
				if ( $area_term_failed ) {
					return $this->rollback_override( $post_id, $area_term );
				}
			}
		}

		if ( $has_blocks ) {
			$result = $this->block_crud->replace_all_blocks( $post_id, $args['blocks'] );
		} else {
			$result = $this->block_crud->save_post_content( $post_id, wp_kses_post( $args['content'] ) );
		}

		$content_write_failed = is_wp_error( $result );
		if ( $content_write_failed ) {
			if ( $override_created ) {
				return $this->rollback_override( $post_id, $result );
			}
			return $result;
		}

		return array(
			'success'            => true,
			'wp_id'              => $post_id,
			'override_created'   => $override_created,
			'revert_hint'        => __( 'Call reset_template to remove this override and revert to the theme file, or use Appearance → Editor → Reset in wp-admin.', 'gk-block-mcp' ),
			'warnings'           => isset( $result['warnings'] ) ? $result['warnings'] : array(),
			'before_revision_id' => isset( $result['before_revision_id'] ) ? $result['before_revision_id'] : null,
			'revision_id'        => isset( $result['revision_id'] ) ? $result['revision_id'] : null,
		);
	}

	/**
	 * Delete a freshly-created override post that a later step failed to
	 * complete, folding a deletion failure into the returned error so a
	 * stray, un-deletable post is surfaced to the caller instead of
	 * silently left behind with no error to explain it.
	 *
	 * @since 2.2.0
	 *
	 * @param int       $post_id The override post to delete.
	 * @param \WP_Error $cause   The error that triggered the rollback.
	 * @return \WP_Error
	 */
	private function rollback_override( $post_id, \WP_Error $cause ) {
		$deleted = wp_delete_post( $post_id, true );
		if ( ! $deleted ) {
			return new \WP_Error(
				'rollback_failed',
				sprintf(
					/* translators: 1: original error message, 2: orphaned post ID */
					__( '%1$s Additionally, the partially-created override (post ID %2$d) could not be automatically removed and may need manual cleanup.', 'gk-block-mcp' ),
					$cause->get_error_message(),
					$post_id
				),
				array( 'status' => 500 )
			);
		}
		return $cause;
	}

	/**
	 * Delete a template's database override, reverting it to the theme file.
	 *
	 * @since 2.2.0
	 *
	 * @param string $id   Template ID (`theme_slug//template_slug`).
	 * @param string $type 'wp_template' or 'wp_template_part'. Default 'wp_template'.
	 * @return array|\WP_Error
	 */
	public function reset_template( $id, $type = 'wp_template' ) {
		$gate_check = $this->check_edits_enabled();
		if ( is_wp_error( $gate_check ) ) {
			return $gate_check;
		}

		$type = $this->sanitize_type( $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$id = is_string( $id ) ? sanitize_text_field( $id ) : '';
		if ( '' === $id ) {
			return new \WP_Error(
				'missing_id',
				__( 'A template "id" is required (e.g. "twentytwentyfive//index").', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Same resolution-based gate as update_template() — see its comment.
		$template = get_block_template( $id, $type );
		if ( ! $template ) {
			$is_genuinely_classic = ! wp_is_block_theme() && ! $this->theme_has_any_block_templates();
			if ( $is_genuinely_classic ) {
				return new \WP_Error(
					'classic_theme',
					__( 'Active theme is not a block theme; there are no template overrides to reset.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			return new \WP_Error(
				'not_found',
				sprintf( /* translators: %s: template id */ __( 'Template "%s" not found.', 'gk-block-mcp' ), $id ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $template->wp_id ) ) {
			return new \WP_Error(
				'no_override',
				__( 'This template has no database override to reset — it already resolves to the theme file.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$wp_id   = (int) $template->wp_id;
		$deleted = wp_delete_post( $wp_id, true );
		if ( ! $deleted ) {
			return new \WP_Error(
				'delete_failed',
				__( 'The template override could not be removed.', 'gk-block-mcp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'id'      => $id,
			'wp_id'   => $wp_id,
		);
	}

	/**
	 * Gate check shared by update_template() and reset_template().
	 *
	 * Enforced here (not only in the REST permission callback) so a direct
	 * caller of this class gets the same 403 a disabled REST route would —
	 * matching Post_Manager::trashing_enabled()'s enforcement point.
	 *
	 * @since 2.2.0
	 *
	 * @return null|\WP_Error null when editing is allowed.
	 */
	private function check_edits_enabled() {
		if ( self::edits_enabled() ) {
			return null;
		}
		return new \WP_Error(
			'template_edits_disabled',
			__( 'Editing theme templates is turned off for this site. A site administrator can enable it under Block MCP → Settings.', 'gk-block-mcp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the active theme has ANY real template or part — theme file
	 * or DB override, either type. Distinct from wp_is_block_theme(),
	 * which is false for both a genuinely classic theme and a hybrid one.
	 * Used only to pick the more accurate error when an id fails to
	 * resolve: a hybrid theme with real content elsewhere gets
	 * "not_found" (this id is simply wrong); a genuinely classic theme
	 * with none gets "classic_theme" (there's nothing here to edit at all).
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	private function theme_has_any_block_templates() {
		return ! empty( get_block_templates( array(), 'wp_template' ) )
			|| ! empty( get_block_templates( array(), 'wp_template_part' ) );
	}

	/**
	 * Normalize + validate a requested template type.
	 *
	 * @since 2.2.0
	 *
	 * @param mixed $type Raw type value.
	 * @return string|\WP_Error 'wp_template' or 'wp_template_part'.
	 */
	private function sanitize_type( $type ) {
		if ( empty( $type ) ) {
			return 'wp_template';
		}
		$type = sanitize_key( (string) $type );
		if ( ! in_array( $type, self::TEMPLATE_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed types */
					__( 'Invalid template type. Use one of: %s.', 'gk-block-mcp' ),
					implode( ', ', self::TEMPLATE_TYPES )
				),
				array( 'status' => 400 )
			);
		}
		return $type;
	}

	/**
	 * Map a WP_Block_Template object to the response shape shared by
	 * get_templates() (list) and get_template() (single, plus content/blocks).
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Block_Template $template Template object.
	 * @return array
	 */
	private function format_template_summary( $template ) {
		$data = array(
			'id'             => (string) $template->id,
			'slug'           => (string) $template->slug,
			'theme'          => (string) $template->theme,
			'type'           => (string) $template->type,
			'title'          => $this->resolve_title( $template ),
			'description'    => (string) $template->description,
			'source'         => (string) $template->source,
			'origin'         => isset( $template->origin ) ? (string) $template->origin : null,
			'status'         => (string) $template->status,
			'has_theme_file' => ! empty( $template->has_theme_file ),
			'is_custom'      => ! empty( $template->is_custom ),
			// Present (non-null) only when a DB override row exists for this
			// template — the bridge to update_template / reset_template.
			'wp_id'          => isset( $template->wp_id ) ? (int) $template->wp_id : null,
		);

		if ( 'wp_template_part' === $data['type'] ) {
			$data['area'] = isset( $template->area ) ? (string) $template->area : '';
		}

		return $data;
	}

	/**
	 * Resolve a template's title as a plain string.
	 *
	 * Defensive: WP_Block_Template::$title is documented as a plain string,
	 * but a filter (get_block_templates / get_block_template) could hand
	 * back a REST-shaped { raw, rendered } array instead.
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_Block_Template $template Template object.
	 * @return string
	 */
	private function resolve_title( $template ) {
		$title = $template->title;
		if ( is_array( $title ) ) {
			$title = isset( $title['rendered'] ) ? $title['rendered'] : ( isset( $title['raw'] ) ? $title['raw'] : '' );
		}
		return (string) $title;
	}
}
