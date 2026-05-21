<?php
/**
 * HTML transformation logic for block attribute-to-markup synchronization.
 *
 * Pure functions that update innerHTML when block attributes change,
 * and rebuild innerContent arrays preserving child block placeholders.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HTML_Transformer
 *
 * Automatically transforms block innerHTML when attribute changes imply
 * structural HTML changes. Also rebuilds innerContent arrays for container
 * blocks when innerHTML is replaced.
 */
class HTML_Transformer {

	/**
	 * Auto-transform innerHTML when attribute changes imply HTML structure changes.
	 *
	 * Uses WP_HTML_Tag_Processor for safe attribute manipulation (no regex for
	 * attributes). Falls back to regex only for tag name swaps where the
	 * processor has no set_tag() support.
	 *
	 * Categories:
	 * 1. Tag name swaps (regex — processor can't change tag names)
	 * 2. HTML attribute transforms (WP_HTML_Tag_Processor)
	 * 3. CSS inline style transforms (WP_HTML_Tag_Processor)
	 * 4. Text content transforms (regex for inner text replacement)
	 *
	 * @param string $block_name    Block type name.
	 * @param array  $changed_attrs The attributes being set (key => value).
	 * @param string $current_html  Current innerHTML of the block.
	 *
	 * @return string|null Transformed HTML, or null if no transform applies.
	 */
	public function auto_transform_html( $block_name, $changed_attrs, $current_html ) {
		try {
			$html = $current_html;

			// ── 1. Tag name swaps (regex — WP_HTML_Tag_Processor has no set_tag) ──

			// core/list: `ordered` toggles <ul> ↔ <ol>.
			// Only swaps the FIRST opening and LAST closing tag to avoid corrupting nested lists.
			if ( 'core/list' === $block_name && array_key_exists( 'ordered', $changed_attrs ) ) {
				if ( $changed_attrs['ordered'] ) {
					$html = preg_replace( '/<ul(\s|>)/i', '<ol$1', $html, 1 ); // First only.
					$html = preg_replace( '/<\/ul>(?!.*<\/ul>)/is', '</ol>', $html );   // Last only.
				} else {
					$html = preg_replace( '/<ol(\s|>)/i', '<ul$1', $html, 1 ); // First only.
					$html = preg_replace( '/<\/ol>(?!.*<\/ol>)/is', '</ul>', $html );   // Last only.
				}
			}

			// core/heading, core/accordion-heading: `level` changes <hN> tag.
			if ( in_array( $block_name, array( 'core/heading', 'core/accordion-heading' ), true )
			&& array_key_exists( 'level', $changed_attrs )
			) {
				$new_level = (int) $changed_attrs['level'];
				if ( $new_level >= 1 && $new_level <= 6 ) {
					$html = preg_replace( '/<h[1-6](\s|>)/i', '<h' . $new_level . '$1', $html );
					$html = preg_replace( '/<\/h[1-6]>/i', '</h' . $new_level . '>', $html );
				}
			}

			// core/group: `tagName` swaps the wrapper element among
			// container (non-void) tags. We rewrite both opening and closing
			// tags so the block stays a well-formed container.
			if ( 'core/group' === $block_name && array_key_exists( 'tagName', $changed_attrs ) ) {
				$container_tags = array( 'div', 'section', 'aside', 'main', 'header', 'footer', 'article' );
				$new_tag        = sanitize_key( $changed_attrs['tagName'] );
				if ( in_array( $new_tag, $container_tags, true ) ) {
					$html = preg_replace(
						'/^(\s*)<(div|section|aside|main|header|footer|article)(\s|>)/i',
						'$1<' . $new_tag . '$3',
						$html
					);
					$html = preg_replace(
						'/<\/(div|section|aside|main|header|footer|article)>(\s*)$/i',
						'</' . $new_tag . '>$2',
						$html
					);
				}
			}

			// core/separator: `tagName` only meaningfully picks among void
			// elements (the editor exposes <hr> today). Keep the rewrite to
			// void tags so we never emit `<hr>…</hr>` (invalid HTML) or
			// `</hr>` (browsers parse it as a stray end tag).
			if ( 'core/separator' === $block_name && array_key_exists( 'tagName', $changed_attrs ) ) {
				$void_tags = array( 'hr' );
				$new_tag   = sanitize_key( $changed_attrs['tagName'] );
				if ( in_array( $new_tag, $void_tags, true ) ) {
					// Normalize to self-closing form so serialization stays
					// deterministic regardless of how the source was authored.
					$html = preg_replace(
						'#^(\s*)<hr\b([^/>]*)/?>(\s*)$#i',
						'$1<' . $new_tag . '$2 />$3',
						$html
					);
				}
			}

			// ── 2. HTML attribute transforms (WP_HTML_Tag_Processor) ─────

			// Map block attrs → HTML attrs to set on the first matching tag.
			// Note: WP_HTML_Tag_Processor::set_attribute() handles escaping internally.
			// Do NOT pass values through esc_attr() — that would double-escape.
			$attr_map = array(
				'url'     => array(
					'tags'  => array( 'a', 'img', 'audio', 'video', 'source', 'iframe', 'embed' ),
					'attrs' => array( 'href', 'src' ), // Try href first, then src.
				),
				'src'     => array(
					'tags'  => array( 'img', 'audio', 'video', 'source', 'iframe' ),
					'attrs' => array( 'src' ),
				),
				'alt'     => array(
					'tags'  => array( 'img' ),
					'attrs' => array( 'alt' ),
				),
				'preload' => array(
					'tags'  => array( 'audio', 'video' ),
					'attrs' => array( 'preload' ),
				),
			);

			foreach ( $attr_map as $block_attr => $config ) {
				if ( ! array_key_exists( $block_attr, $changed_attrs ) ) {
					continue;
				}

				$new_val    = $changed_attrs[ $block_attr ];
				$tags       = $config['tags'];
				$html_attrs = $config['attrs'];
				$found      = false;

				// Reset processor to scan from the start for each attribute.
				$processor = new \WP_HTML_Tag_Processor( $html );

				while ( $processor->next_tag() ) {
					// Filter by allowed tags if specified.
					if ( null !== $tags && ! in_array( strtolower( $processor->get_tag() ), $tags, true ) ) {
						continue;
					}

					// Try each candidate HTML attribute.
					foreach ( $html_attrs as $html_attr ) {
						if ( null !== $processor->get_attribute( $html_attr ) ) {
							$processor->set_attribute( $html_attr, $new_val );
							$found = true;
							break 2; // Set on first match only.
						}
					}
				}

				if ( $found ) {
					$html = $processor->get_updated_html();
				}
			}

			// Boolean HTML attributes (autoplay, loop) on audio/video.
			$bool_attrs = array( 'autoplay', 'loop' );

			foreach ( $bool_attrs as $attr ) {
				if ( ! array_key_exists( $attr, $changed_attrs ) ) {
					continue;
				}

				$processor = new \WP_HTML_Tag_Processor( $html );
				// filter_var with FILTER_VALIDATE_BOOLEAN understands real bools, "true"/"false",
				// "1"/"0", "yes"/"no", "on"/"off". A plain `if ( $x )` would treat the literal
				// string "false" (any non-empty string) as truthy and incorrectly set autoplay.
				$enable = filter_var( $changed_attrs[ $attr ], FILTER_VALIDATE_BOOLEAN );
				while ( $processor->next_tag() ) {
					$tag = strtolower( $processor->get_tag() );
					if ( ! in_array( $tag, array( 'audio', 'video' ), true ) ) {
						continue;
					}

					if ( $enable ) {
						$processor->set_attribute( $attr, true );
					} else {
						$processor->remove_attribute( $attr );
					}
					break; // First match only.
				}
				$html = $processor->get_updated_html();
			}

			// core/details: `showContent` toggles the `open` attribute.
			if ( 'core/details' === $block_name && array_key_exists( 'showContent', $changed_attrs ) ) {
				$processor = new \WP_HTML_Tag_Processor( $html );
				if ( $processor->next_tag( 'details' ) ) {
					// Same FILTER_VALIDATE_BOOLEAN handling as autoplay/loop — string
					// "false" must disable, not enable.
					if ( filter_var( $changed_attrs['showContent'], FILTER_VALIDATE_BOOLEAN ) ) {
						$processor->set_attribute( 'open', true );
					} else {
						$processor->remove_attribute( 'open' );
					}
					$html = $processor->get_updated_html();
				}
			}

			// ── 3. CSS inline style transforms (WP_HTML_Tag_Processor) ───

			$css_prop_map = array(
				'height' => 'height',
				'width'  => 'width',
			);

			foreach ( $css_prop_map as $block_attr => $css_prop ) {
				if ( ! array_key_exists( $block_attr, $changed_attrs ) ) {
					continue;
				}

				$new_val   = sanitize_text_field( $changed_attrs[ $block_attr ] );
				$processor = new \WP_HTML_Tag_Processor( $html );

				if ( $processor->next_tag() ) {
					$style = $processor->get_attribute( 'style' );
					if ( null !== $style && false !== strpos( $style, $css_prop ) ) {
						// Negative lookbehind on [-\w] ensures we don't match inside
						// compound properties like line-height, min-width, max-height.
						// preg_replace_callback (not preg_replace) — $new_val comes from
						// caller-controlled input. A literal "$1" inside it would be
						// interpreted as a backreference, producing corrupted CSS.
						$new_style = preg_replace_callback(
							'/(?<![-\w])' . preg_quote( $css_prop, '/' ) . '\s*:\s*[^;"]+(;?)/',
							static function ( $matches ) use ( $css_prop, $new_val ) {
								return $css_prop . ':' . $new_val . $matches[1];
							},
							$style
						);
						$processor->set_attribute( 'style', $new_style );
						$html = $processor->get_updated_html();
					}
				}
			}

			// ── 4. Text content transforms (regex — processor can't edit text) ──

			// core/heading, core/paragraph, core/button, core/code, core/preformatted,
			// core/verse: `content` attr replaces the element's inner text.
			// The content may contain inline HTML (links, bold, etc.) so we use wp_kses_post.
			$content_blocks = array( 'core/heading', 'core/paragraph', 'core/code', 'core/preformatted', 'core/verse' );
			if ( in_array( $block_name, $content_blocks, true )
			&& array_key_exists( 'content', $changed_attrs )
			) {
				$new_content = wp_kses_post( $changed_attrs['content'] );
				// Replace inner text between the first opening tag and last closing tag.
				$html = preg_replace_callback(
					'/^(\s*<[^>]+>)(.*?)(<\/[^>]+>\s*)$/is',
					function ( $matches ) use ( $new_content ) {
						return $matches[1] . $new_content . $matches[3];
					},
					$html
				);
			}

			// core/button: `text` attr replaces the <a> inner text.
			if ( 'core/button' === $block_name && array_key_exists( 'text', $changed_attrs ) ) {
				$new_text = wp_kses_post( $changed_attrs['text'] );
				$html     = preg_replace_callback(
					'/(<a[^>]*>)(.*?)(<\/a>)/is',
					function ( $matches ) use ( $new_text ) {
						return $matches[1] . $new_text . $matches[3];
					},
					$html
				);
			}

			// core/quote, core/pullquote: `citation` updates <cite> text.
			// Uses preg_replace_callback to avoid backreference injection if citation
			// contains $ characters (e.g., "Price is $100").
			if ( in_array( $block_name, array( 'core/quote', 'core/pullquote' ), true )
			&& array_key_exists( 'citation', $changed_attrs )
			) {
				$new_citation = wp_kses_post( $changed_attrs['citation'] );
				if ( preg_match( '/<cite[^>]*>.*?<\/cite>/is', $html ) ) {
					$html = preg_replace_callback(
						'/(<cite[^>]*>).*?(<\/cite>)/is',
						function ( $matches ) use ( $new_citation ) {
							return $matches[1] . $new_citation . $matches[2];
						},
						$html
					);
				} elseif ( ! empty( $new_citation ) ) {
					$html = preg_replace_callback(
						'/(<\/blockquote>\s*$)/i',
						function ( $matches ) use ( $new_citation ) {
							return '<cite>' . $new_citation . '</cite>' . $matches[1];
						},
						$html
					);
				}
			}

			// kevinbatdorf/code-block-pro: `codeHTML` replaces the <pre class="shiki">
			// block embedded in innerHTML; `code` updates the copy-button <textarea>.
			// CBP is dual-storage — attributes hold codeHTML for the editor, innerHTML
			// holds the full rendered widget. Syncing both here lets callers pass only
			// attributes without needing to rebuild the wrapper div.
			if ( 'kevinbatdorf/code-block-pro' === $block_name ) {
				if ( array_key_exists( 'codeHTML', $changed_attrs ) ) {
					// codeHTML is the Shiki-rendered <pre> block embedded in
					// innerHTML. It contains styled spans / inline-style attributes
					// that wp_kses_post permits, but kses also strips <script>,
					// onload-style event attributes, and other XSS vectors so a
					// malicious caller can't smuggle JS into the editor preview.
					$new_pre = wp_kses_post( (string) $changed_attrs['codeHTML'] );
					// preg_replace_callback (not preg_replace) — $new_pre is user-supplied
					// and a literal "$1" inside it would otherwise be interpreted as a
					// backreference, producing corrupted output.
					$html = preg_replace_callback(
						'/<pre class="shiki[\s\S]*?<\/pre>/',
						static function () use ( $new_pre ) {
							return $new_pre;
						},
						$html,
						1
					);
				}
				if ( array_key_exists( 'code', $changed_attrs ) ) {
					$raw_code = $changed_attrs['code'];
					$html     = preg_replace_callback(
						'/(<textarea[^>]*>)([\s\S]*?)(<\/textarea>)/i',
						function ( $matches ) use ( $raw_code ) {
							return $matches[1] . esc_html( $raw_code ) . $matches[3];
						},
						$html,
						1
					);
				}
			}

			// Return transformed HTML if anything changed.
			if ( $html !== $current_html ) {
				return $html;
			}

			return null;

		} catch ( \Throwable $e ) {
			// Transform failed — return null (no transform applied, safety warning will fire instead).
			if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
				error_log( 'GK Block API auto_transform error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return null;
		}
	}

	/**
	 * Strip empty class attributes from innerHTML.
	 *
	 * Removes `class=""`, `class=''`, and class attributes whose value is
	 * pure whitespace. These never round-trip cleanly through Gutenberg:
	 * `useBlockProps.save()` omits the class attribute entirely when there
	 * are no classes to emit, so any empty class in the stored DOM creates
	 * a save-output mismatch and triggers "Block contains unexpected or
	 * invalid content" on next edit. Stripping is information-preserving —
	 * `class=""` and no class attribute are semantically identical in HTML.
	 *
	 * The regex only matches the attribute when it's preceded by whitespace
	 * inside a tag (so we never touch text that happens to contain the
	 * literal string `class=""`). The closing whitespace is normalised so
	 * the resulting tag stays well-formed.
	 *
	 * @param string $html innerHTML to normalise.
	 *
	 * @return string Normalised innerHTML.
	 */
	public function strip_empty_class_attributes( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Match: whitespace, class=, quote, optional whitespace-only value,
		// matching quote. Captures both single- and double-quoted forms.
		return preg_replace( '/\s+class=(["\'])\s*\1/', '', $html );
	}

	/**
	 * Rebuild innerContent when innerHTML is replaced on a container block.
	 *
	 * WordPress innerContent is an array like ['<div>', null, '</div>'] where
	 * null entries are placeholders for innerBlocks. When innerHTML is updated,
	 * we need to replace the string portions (wrapper HTML) while preserving
	 * the null placeholders so serialize_blocks() correctly outputs children.
	 *
	 * Strategy: the new innerHTML contains the wrapper markup. We split it
	 * into fragments around child positions by counting the existing nulls
	 * and distributing the new HTML across the same structure.
	 *
	 * For simple cases (1 null = 1 child), the result is:
	 *   [opening_html, null, closing_html]
	 *
	 * @param array  $old_inner_content The existing innerContent array.
	 * @param string $new_inner_html    The new innerHTML for the block.
	 *
	 * @return array Updated innerContent preserving null positions.
	 */
	public function rebuild_inner_content( $old_inner_content, $new_inner_html ) {
		try {
			// Count how many null placeholders exist (one per innerBlock).
			$null_count = 0;
			foreach ( $old_inner_content as $piece ) {
				if ( null === $piece ) {
					++$null_count;
				}
			}

			if ( 0 === $null_count ) {
				// No children — just a leaf block.
				return array( $new_inner_html );
			}

			// For container blocks, innerHTML typically looks like:
			// "\n<div class=\"wp-block-group\">\n</div>\n"
			// We need to split this into opening wrapper + closing wrapper
			// and place nulls between them (one per child).
			//
			// Simple heuristic: find the split point where the opening wrapper ends.
			// The innerHTML has the inner content stripped, so it's effectively:
			// opening_html + closing_html
			// We insert nulls between opening and closing.

			// Use WP_HTML_Tag_Processor to find the end of the first opening tag.
			$processor = new \WP_HTML_Tag_Processor( $new_inner_html );
			if ( $processor->next_tag() ) {
				// Get position after the first tag's > character.
				// The processor doesn't expose offset, so use a simpler approach:
				// find the first > in the HTML.
				$first_close = strpos( $new_inner_html, '>' );
				if ( false !== $first_close ) {
					$opening = substr( $new_inner_html, 0, $first_close + 1 );
					$closing = substr( $new_inner_html, $first_close + 1 );

					$result = array( $opening );
					for ( $i = 0; $i < $null_count; $i++ ) {
						$result[] = null;
					}
					$result[] = $closing;

					return $result;
				}
			}

			// Fallback: preserve old structure, just update non-null entries
			// by splitting new HTML evenly across them.
			return array_map(
				function ( $piece ) use ( $new_inner_html ) {
					return null === $piece ? null : $new_inner_html;
				},
				$old_inner_content
			);

		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
				error_log( 'GK Block API rebuild_inner_content error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			// Fallback: simple array with the new HTML.
			return array( $new_inner_html );
		}
	}
}
