<?php
/**
 * Read-only term listing for taxonomy lookup.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

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
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.3.
	 * @return array|\WP_Error
	 */
	public function list_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf( /* translators: %s: taxonomy slug */ __( 'Taxonomy "%s" does not exist.', 'gk-block-api' ), $taxonomy ),
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
		$order = isset( $args['order'] ) && 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';

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
			'link'        => function_exists( 'get_term_link' ) ? (string) get_term_link( $term ) : '',
		);
	}
}
