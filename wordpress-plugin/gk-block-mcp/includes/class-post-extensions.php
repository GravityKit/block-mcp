<?php
/**
 * Extension points that let other plugins tell an agent about a post, and
 * stop an agent's write to a post before it happens.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Post notices and the pre-write check.
 *
 * Both run inside REST_Controller and Post_Manager, so they apply to every
 * transport: the REST API the MCP server calls, and the Abilities API / MCP
 * Adapter path that calls the controller directly.
 *
 * @since 2.3.0
 */
class Post_Extensions {

	/**
	 * Notes other plugins want an agent to read about a post, such as "a
	 * staged copy of this page is waiting; edit that instead".
	 *
	 * A filter that throws is skipped and logged, so a broken extension never
	 * breaks a read.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id Post the agent is looking at.
	 * @param string $context Where the notes will appear: `blocks`, `post_info` or `resolve_url`.
	 *
	 * @return string[] Plain-text notes, possibly empty.
	 */
	public static function notices( $post_id, $context ) {
		try {
			/**
			 * Add notes an agent sees when it reads a post through Block MCP.
			 *
			 * Notes appear as `notices` on get_page_blocks, get_post_info and
			 * resolve_url. Use them for facts the agent needs before editing,
			 * and say what to do instead. Keep each note to one or two
			 * sentences of plain text.
			 *
			 * @since 2.3.0
			 *
			 * @example
			 * // Point agents away from pages that are frozen for review.
			 * add_filter( 'gk/block-mcp/post/notices', function ( $notices, $post_id ) {
			 *     if ( get_post_meta( $post_id, 'frozen_for_review', true ) ) {
			 *         $notices[] = 'This page is frozen for legal review. Do not edit it.';
			 *     }
			 *     return $notices;
			 * }, 10, 2 );
			 *
			 * @param string[] $notices Notes so far.
			 * @param int      $post_id Post being read.
			 * @param string   $context `blocks`, `post_info` or `resolve_url`.
			 */
			$notices = apply_filters( 'gk/block-mcp/post/notices', array(), (int) $post_id, (string) $context );
		} catch ( \Throwable $e ) {
			self::log( 'gk/block-mcp/post/notices', $e );

			return array();
		}

		if ( ! is_array( $notices ) ) {
			return array();
		}

		$clean = array();
		foreach ( $notices as $notice ) {
			$text = is_scalar( $notice ) ? sanitize_text_field( (string) $notice ) : '';
			if ( '' !== $text ) {
				$clean[] = $text;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Ask other plugins whether an agent may write to a post.
	 *
	 * Runs after the capability check, before anything is written. A filter
	 * that throws is treated as a refusal, so a broken guard fails closed.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $post_id   Post about to be written.
	 * @param string $operation What is being written: `blocks` for any block
	 *                          edit, `post` for update_post fields, `seo` for
	 *                          Yoast fields.
	 *
	 * @return true|\WP_Error True to allow, or the refusal to return to the agent.
	 */
	public static function check_write( $post_id, $operation ) {
		try {
			/**
			 * Stop an agent's write to a post before it happens.
			 *
			 * Return a WP_Error to refuse the write; its message is what the
			 * agent sees, so say what to do instead. Without a status in the
			 * error data, the response is HTTP 409. Return null to allow.
			 *
			 * @since 2.3.0
			 *
			 * @example
			 * // Keep agents off pages that are frozen for review.
			 * add_filter( 'gk/block-mcp/post/pre-write', function ( $result, $post_id ) {
			 *     if ( get_post_meta( $post_id, 'frozen_for_review', true ) ) {
			 *         return new WP_Error( 'frozen', 'This page is frozen for legal review. Do not edit it.' );
			 *     }
			 *     return $result;
			 * }, 10, 2 );
			 *
			 * @param null|\WP_Error $result    Null to allow, or a refusal.
			 * @param int            $post_id   Post about to be written.
			 * @param string         $operation `blocks`, `post` or `seo`.
			 */
			$result = apply_filters( 'gk/block-mcp/post/pre-write', null, (int) $post_id, (string) $operation );
		} catch ( \Throwable $e ) {
			self::log( 'gk/block-mcp/post/pre-write', $e );

			return new \WP_Error(
				'write_check_failed',
				__( 'A site plugin could not confirm this edit is allowed, so it was not made. Try again, or ask a site administrator.', 'gk-block-mcp' ),
				array( 'status' => 500 )
			);
		}

		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		$data = $result->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 409 ) ) );
		}

		return $result;
	}

	/**
	 * Log an extension that threw.
	 *
	 * @param string     $hook Hook name.
	 * @param \Throwable $e    What it threw.
	 *
	 * @return void
	 */
	private static function log( $hook, \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaces a broken third-party extension to the site owner.
			error_log( sprintf( '[gk-block-mcp] A callback on %s threw %s: %s', $hook, get_class( $e ), $e->getMessage() ) );
		}
	}
}
