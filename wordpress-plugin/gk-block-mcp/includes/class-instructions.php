<?php
/**
 * Per-site MCP server Instructions addendum.
 *
 * Stores an admin-editable string that the TypeScript MCP server appends to
 * its hard-coded baseline when constructing `serverInfo.instructions` at
 * initialize. Lets a site encode conventions (callout className mapping,
 * code-block theme, doc structure rules) once and have every connected
 * client receive them without re-discovery.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Instructions
 */
class Instructions {

	/**
	 * WP option key. Public read via the REST endpoint by design — the value
	 * reaches every connected MCP client at handshake. Admins MUST NOT put
	 * secrets in this field; UI copy warns about this.
	 */
	const OPTION_KEY = 'gk_block_api_instructions';

	/**
	 * Companion option tracking the last update timestamp (unix seconds).
	 * Stored separately so REST callers can `If-Modified-Since` without
	 * parsing the value, and so wipe-on-empty still emits a fresh timestamp.
	 */
	const UPDATED_AT_OPTION = 'gk_block_api_instructions_updated_at';

	/**
	 * Maximum allowed length, counted in UTF-8 characters (not bytes).
	 * Hard-enforced on save and again on the REST read path as defense in
	 * depth. All length checks and truncations use `mb_strlen` /
	 * `mb_substr` with the `'UTF-8'` encoding so multibyte input (emoji,
	 * CJK, accented Latin) isn't split mid-codepoint.
	 *
	 * Sized to roughly 500 tokens of English text — small enough to be a
	 * cheap addition to every LLM session, big enough for a useful rules
	 * list (callout mapping + code-block conventions + 5–10 bulleted rules
	 * fits comfortably).
	 */
	const MAX_LENGTH = 2000;

	/**
	 * Rate-limit window for the public REST endpoint (requests per minute
	 * per remote IP). Deters scraping without affecting legitimate clients,
	 * which cache at `max-age=60` and hit the endpoint once per session.
	 */
	const RATE_LIMIT_PER_MIN = 30;

	/**
	 * Return the stored addendum, sanitized at read time as belt-and-braces.
	 *
	 * Sanitizing on read AND write protects against options written by code
	 * that bypassed our sanitize_callback (direct `update_option` from a
	 * sibling plugin, a database restore from an older schema, etc.).
	 *
	 * @return string Empty string when no addendum is set.
	 */
	public static function get_addendum(): string {
		$raw = (string) get_option( self::OPTION_KEY, '' );
		if ( '' === $raw ) {
			return '';
		}
		return self::sanitize( $raw );
	}

	/**
	 * Return the timestamp (unix seconds) of the last successful save.
	 *
	 * Zero when no value has ever been saved. Independent of the addendum
	 * value so callers can distinguish "never set" (0) from "explicitly
	 * cleared" (>0 with empty addendum).
	 *
	 * @return int
	 */
	public static function get_updated_at(): int {
		return (int) get_option( self::UPDATED_AT_OPTION, 0 );
	}

	/**
	 * Save the addendum. Sanitizes input, enforces length cap, updates the
	 * companion timestamp atomically.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return true|\WP_Error True on success; WP_Error('addendum_too_long')
	 *                       when input exceeds MAX_LENGTH even after sanitize.
	 */
	public static function set_addendum( $value ) {
		$clean = self::sanitize( $value );

		// Length check fires after sanitize so HTML/shortcode stripping
		// doesn't accidentally push a 1990-char input over the limit; the
		// 2000-char budget is the post-sanitize character count that
		// reaches clients. `mb_strlen` counts UTF-8 codepoints so emoji
		// and CJK don't blow the cap on byte-length alone.
		if ( mb_strlen( $clean, 'UTF-8' ) > self::MAX_LENGTH ) {
			return new \WP_Error(
				'addendum_too_long',
				sprintf(
					/* translators: 1: max length, 2: submitted length */
					__( 'Instructions addendum is too long: %2$d characters (max %1$d).', 'gk-block-mcp' ),
					self::MAX_LENGTH,
					mb_strlen( $clean, 'UTF-8' )
				),
				array( 'status' => 400 )
			);
		}

		update_option( self::OPTION_KEY, $clean, false );
		update_option( self::UPDATED_AT_OPTION, time(), false );
		return true;
	}

	/**
	 * Sanitize an addendum value.
	 *
	 * Strips HTML tags, PHP, shortcodes, and ASCII control characters
	 * (except newline/tab — kept so markdown bullets and indentation
	 * survive). Truncates to MAX_LENGTH as defense in depth.
	 *
	 * What this does NOT do:
	 *
	 * - Render markdown. Output is sent verbatim to MCP clients which
	 *   handle their own rendering.
	 * - Strip unicode control characters beyond the C0/C1 ASCII ranges.
	 *   The TypeScript side does an additional pass for those (Bidi marks,
	 *   zero-width chars) where they have higher prompt-injection signal.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return string Sanitized string (may be empty).
	 */
	public static function sanitize( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$str = (string) $value;
		if ( '' === $str ) {
			return '';
		}

		// Strip HTML/PHP tags first — `sanitize_textarea_field()` does this
		// internally but also nukes newlines we want to keep, so do it
		// manually. `wp_strip_all_tags( $str, false )` does NOT collapse
		// whitespace (the second arg defaults true and we override).
		$str = wp_strip_all_tags( $str, false );

		// Strip WordPress shortcodes. Defense against an admin pasting
		// `[do_something]` and being surprised when it doesn't execute.
		// It cannot — we never call `do_shortcode()` on this value — but
		// stripping eliminates the question.
		$str = strip_shortcodes( $str );

		// Strip C0 control characters except \t (0x09), \n (0x0A), \r (0x0D)
		// — those are needed for markdown indentation and bullet lists.
		// Also strips the DEL character (0x7F).
		$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str );

		// Normalize CRLF / CR line endings to LF. Saves one branch on the
		// TypeScript side and matches MCP client expectations.
		$str = str_replace( array( "\r\n", "\r" ), "\n", (string) $str );

		// Trim outer whitespace — leading/trailing newlines from a paste
		// don't carry meaning and waste budget.
		$str = trim( $str );

		// Length cap last so it operates on the post-sanitize size. Use
		// the multibyte variants so a truncation never lands inside a
		// UTF-8 codepoint sequence (which would produce a mojibake tail
		// and could break a downstream client's JSON parser).
		if ( mb_strlen( $str, 'UTF-8' ) > self::MAX_LENGTH ) {
			$str = mb_substr( $str, 0, self::MAX_LENGTH, 'UTF-8' );
		}

		return $str;
	}

	/**
	 * Sanitize callback for `register_setting()`.
	 *
	 * Wraps `sanitize()` + length cap so the Settings API does the right
	 * thing on form submit without the caller needing to know about
	 * `set_addendum()`. Over-length input is silently truncated here (no
	 * way to surface a WP_Error through the Settings API on a per-field
	 * basis without `add_settings_error()`, which the rest of this plugin
	 * doesn't use).
	 *
	 * @param mixed $value Raw input from $_POST.
	 *
	 * @return string
	 */
	public static function sanitize_callback( $value ): string {
		$clean = self::sanitize( $value );

		// Touch the timestamp option so REST consumers see the save even
		// when the value didn't change (admins re-saving to refresh).
		update_option( self::UPDATED_AT_OPTION, time(), false );

		return $clean;
	}

	/**
	 * Check (and record) the per-IP rate limit for the public read endpoint.
	 *
	 * Uses a 60-second sliding-window transient keyed by the remote IP. Each
	 * call records the current timestamp; when the count within the last 60s
	 * exceeds RATE_LIMIT_PER_MIN, returns false (caller should respond 429).
	 *
	 * @param string $ip Remote IP from REST_Server::get_raw_data() context
	 *                   (caller passes `$_SERVER['REMOTE_ADDR']`).
	 *
	 * @return bool True when the request is permitted (rate budget remains);
	 *              false when the budget is exhausted.
	 */
	public static function check_rate_limit( string $ip ): bool {
		// IP is opaque to us — only used as a transient key. Hash so the
		// raw IP doesn't sit in the options table (PII minimization), and
		// to keep the key short and bounded (IPv6 strings can hit 39
		// chars; the hash is 12).
		$key = 'gk_block_api_instr_rl_' . substr( hash( 'sha256', $ip ), 0, 12 );

		$now    = time();
		$window = 60;
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}

		// Drop entries outside the rolling window.
		$bucket = array_values(
			array_filter(
				$bucket,
				static function ( $ts ) use ( $now, $window ) {
					return is_numeric( $ts ) && ( $now - (int) $ts ) < $window;
				}
			)
		);

		if ( count( $bucket ) >= self::RATE_LIMIT_PER_MIN ) {
			return false;
		}

		$bucket[] = $now;
		// Slightly longer TTL than the window so the bucket survives until
		// every entry has aged out, even if no further request lands.
		set_transient( $key, $bucket, $window * 2 );
		return true;
	}
}
