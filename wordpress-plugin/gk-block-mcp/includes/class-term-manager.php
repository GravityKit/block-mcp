<?php
/**
 * Read-only term listing for taxonomy lookup.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Term_Manager
 *
 * Thin wrapper around get_terms() and wp_count_terms() for taxonomy
 * discovery — primarily so agents can resolve category/tag names to IDs
 * before passing them to create_post or update_post.
 */
class Term_Manager {

	const MAX_PER_PAGE = 200;

	/**
	 * List taxonomy terms with pagination and filtering.
	 *
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.3.
	 * @return array|\WP_Error
	 */
	public function list_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf( /* translators: %s: taxonomy slug */ __( 'Taxonomy "%s" does not exist.', 'gk-block-mcp' ), $taxonomy ),
				array( 'status' => 400 )
			);
		}

		// Only surface taxonomies the site has opted into REST exposure.
		// Plugins commonly register internal-state taxonomies with
		// `public: false` and `show_in_rest: false` for their own
		// bookkeeping (workflow state, license keys, etc.). Without this
		// gate, /terms?taxonomy=<that_internal_slug> would list every term
		// in that taxonomy to any edit_posts caller. Matches the
		// invariant WordPress's own /wp/v2/taxonomies endpoint enforces.
		//
		// Override via the `gk/block-mcp/term/allow-taxonomy` filter:
		// agents editing a CPT with a deliberately-private taxonomy
		// (workflow state, internal department) still need to discover
		// term IDs to assign them — site admins can grant per-taxonomy
		// access by returning true from the filter without globally
		// flipping show_in_rest.
		$tax_object  = get_taxonomy( $taxonomy );
		$rest_listed = $tax_object && ! empty( $tax_object->show_in_rest );

		/**
		 * Decide which taxonomies the AI can browse terms from.
		 *
		 * By default the assistant can only list terms from taxonomies you've
		 * already exposed to the REST API — the same line WordPress draws for its
		 * own endpoints, so private bookkeeping taxonomies stay private. Use this
		 * filter to open a single deliberately-internal taxonomy (a workflow
		 * status, an editorial desk) just for the agent, so it can assign those
		 * terms — without flipping `show_in_rest` and exposing it everywhere. You
		 * can also return false to hide a normally-listable taxonomy.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Let the assistant assign terms from a private "editorial_status" taxonomy.
		 * add_filter( 'gk/block-mcp/term/allow-taxonomy', function ( $allow, $taxonomy ) {
		 *     return 'editorial_status' === $taxonomy ? true : $allow;
		 * }, 10, 2 );
		 *
		 * @param bool                    $allow      Whether the taxonomy is listable (defaults to its show_in_rest value).
		 * @param string                  $taxonomy   Sanitized taxonomy slug.
		 * @param \WP_Taxonomy|null|false $tax_object Taxonomy object, or null/false if not registered.
		 */
		$allow = apply_filters(
			'gk/block-mcp/term/allow-taxonomy',
			$rest_listed,
			$taxonomy,
			$tax_object
		);

		if ( ! $tax_object || ! $allow ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf( /* translators: %s: taxonomy slug */ __( 'Taxonomy "%s" does not exist.', 'gk-block-mcp' ), $taxonomy ),
				array( 'status' => 400 )
			);
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 100;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$orderby_allowed = array( 'name', 'count', 'term_id', 'slug' );
		$orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], $orderby_allowed, true )
			? $args['orderby']
			: 'name';
		$order           = isset( $args['order'] ) && 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';

		$query_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $args['hide_empty'] ) ? (bool) $args['hide_empty'] : false,
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => $orderby,
			'order'      => $order,
		);
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$query_args['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( isset( $args['parent'] ) ) {
			$query_args['parent'] = (int) $args['parent'];
		}
		if ( isset( $args['slug'] ) && '' !== $args['slug'] ) {
			$query_args['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( ! empty( $args['include'] ) && is_array( $args['include'] ) ) {
			$query_args['include'] = array_map( 'absint', $args['include'] );
		}

		$terms = get_terms( $query_args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = $query_args;
		unset( $count_args['number'], $count_args['offset'] );
		$total = (int) wp_count_terms( $count_args );

		$formatted = array_map( array( $this, 'format_term' ), is_array( $terms ) ? $terms : array() );

		return array(
			'taxonomy' => $taxonomy,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'terms'    => $formatted,
		);
	}

	/**
	 * Format a WP_Term object into a response array.
	 *
	 * @param object $term WP_Term-shaped object.
	 * @return array
	 */
	private function format_term( $term ) {
		return array(
			'id'          => isset( $term->term_id ) ? (int) $term->term_id : 0,
			'name'        => isset( $term->name ) ? (string) $term->name : '',
			'slug'        => isset( $term->slug ) ? (string) $term->slug : '',
			'description' => isset( $term->description ) ? (string) $term->description : '',
			'parent'      => isset( $term->parent ) ? (int) $term->parent : 0,
			'count'       => isset( $term->count ) ? (int) $term->count : 0,
			'taxonomy'    => isset( $term->taxonomy ) ? (string) $term->taxonomy : '',
			// get_term_link() returns WP_Error for invalid terms / taxonomies;
			// casting that to string would inject "Object of class WP_Error..."
			// into the response. Resolve once and downgrade errors to ''.
			'link'        => $this->get_term_link_safe( $term ),
		);
	}

	/**
	 * Resolve a term's permalink, returning an empty string on failure.
	 *
	 * Returns an empty string on failure because get_term_link() returns WP_Error
	 * for invalid terms / taxonomies, and casting that to string would inject
	 * "Object of class WP_Error..." into the response.
	 *
	 * @param object $term WP_Term-shaped object.
	 * @return string Permalink URL or empty string.
	 */
	private function get_term_link_safe( $term ) {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return '';
		}
		return (string) $link;
	}
}
