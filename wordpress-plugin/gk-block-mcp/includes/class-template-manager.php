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
	 * Block CRUD service, used to format raw block markup the same way
	 * get_page_blocks() formats a post's content.
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

		if ( ! wp_is_block_theme() ) {
			return array(
				'templates' => array(),
				'count'     => 0,
				'note'      => __( 'Active theme is not a block theme; no block templates exist.', 'gk-block-mcp' ),
			);
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

		return array(
			'templates' => $formatted,
			'count'     => count( $formatted ),
		);
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
		// Defensive: WP_Block_Template::$title is documented as a plain
		// string, but a filter (get_block_templates / get_block_template)
		// could hand back a REST-shaped { raw, rendered } array instead.
		$title = $template->title;
		if ( is_array( $title ) ) {
			$title = isset( $title['rendered'] ) ? $title['rendered'] : ( isset( $title['raw'] ) ? $title['raw'] : '' );
		}

		$data = array(
			'id'             => (string) $template->id,
			'slug'           => (string) $template->slug,
			'theme'          => (string) $template->theme,
			'type'           => (string) $template->type,
			'title'          => (string) $title,
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
}
