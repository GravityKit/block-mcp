/**
 * Agent-friendly translations of WordPress REST error codes.
 *
 * The WP REST stack returns error codes like `rest_post_invalid_id` or
 * `rest_no_route`. Those are useful to a developer reading server logs but
 * useless to an LLM trying to recover and continue. This module maps the
 * codes we actually see in the wild to short, actionable messages that
 * suggest the agent's next step.
 *
 * The original code is always preserved on the thrown Error (`wpCode`) so
 * agents that *do* want to pattern-match on the raw code still can.
 */

interface ErrorContextHints {
  /** WordPress post ID involved in the error, when applicable. */
  post_id?: number;
  /** gk_ref UUID that was rejected, when applicable. */
  ref?: string;
  /** Block path (e.g. [0, 2, 1]) that was rejected, when applicable. */
  path?: number[];
  /** Block name involved in a legacy/avoid rejection. */
  block?: string;
  /** Replacement block name suggested by the preference engine. */
  suggested_replacement?: string;
  /** Status from the data envelope, sometimes present even on success. */
  status?: number;
  /** Some endpoints return `block_name` instead of `block`. */
  block_name?: string;
  /** Allow extra fields without typing every one. */
  [key: string]: unknown;
}

/**
 * Pull a small set of well-known fields out of an arbitrary WP error data
 * payload. Yoast/REST/our plugin all use slightly different shapes; this
 * normalizer just looks for the keys we care about.
 */
function extractHints(data: unknown): ErrorContextHints {
  if (!data || typeof data !== 'object') return {};
  const d = data as Record<string, unknown>;
  const hints: ErrorContextHints = { ...d };

  if (typeof d.post_id === 'number')             hints.post_id = d.post_id;
  if (typeof d.ref === 'string')                 hints.ref = d.ref;
  if (Array.isArray(d.path) && d.path.every((p): p is number => typeof p === 'number' && Number.isFinite(p))) {
    hints.path = d.path;
  }
  if (typeof d.block === 'string')               hints.block = d.block;
  if (typeof d.block_name === 'string')          hints.block_name = d.block_name;
  if (typeof d.suggested_replacement === 'string') hints.suggested_replacement = d.suggested_replacement;
  if (typeof d.status === 'number')              hints.status = d.status;

  return hints;
}

/**
 * Translate a WordPress REST error into an agent-actionable hint.
 *
 * Returns null when we don't have a translation for this code — callers
 * should fall back to the raw `message` from the response body.
 */
export function translateWpError(code: string | undefined, data: unknown): string | null {
  if (!code) return null;
  const hints = extractHints(data);
  const blockName = hints.block ?? hints.block_name;

  switch (code) {
    // ── Routing / auth ─────────────────────────────────────────────
    case 'rest_no_route':
      return 'REST route not found at this site. Confirm the gk-block-api plugin is active and the WORDPRESS_URL is correct.';

    case 'rest_forbidden':
    case 'rest_cannot_edit':
    case 'rest_cannot_create':
      return 'Permission denied. The Application Password\'s user lacks the required capability (typically `edit_posts`, or `edit_post` on this specific post).';

    case 'rest_cookie_invalid_nonce':
    case 'rest_authentication_required':
      return 'Authentication failed. Confirm WORDPRESS_USER and WORDPRESS_APP_PASSWORD are set to a valid Application Password (not a regular login password).';

    // ── Post lookup ────────────────────────────────────────────────
    case 'rest_post_invalid_id':
    case 'invalid_post_id': {
      const target = hints.post_id !== undefined ? `Post ${hints.post_id}` : 'Post';
      return `${target} not found. List pages with \`list_posts\` to find the right ID.`;
    }

    case 'not_found':
      // Generic 404 from our own handlers (post / pattern / media missing).
      return hints.post_id
        ? `Post ${hints.post_id} not found. It may have been deleted, or the ID is wrong.`
        : 'Resource not found. It may have been deleted, or the ID is wrong.';

    // ── Block ref / path resolution ────────────────────────────────
    case 'gk_block_api_invalid_ref':
    case 'invalid_ref':
      return `Block ref \`${hints.ref ?? '?'}\` not found in post ${hints.post_id ?? '?'}. The post may have been edited since you last fetched it — call \`get_page_blocks\` again to get the current refs.`;

    case 'path_not_found':
    case 'invalid_path':
      return `Block path ${formatPath(hints.path)} doesn't address an existing block. Re-fetch the post with \`get_page_blocks\` to get current paths — paths shift when blocks are added or removed.`;

    case 'path_out_of_bounds':
      return `Block path ${formatPath(hints.path)} is out of bounds. The post has fewer blocks than expected — re-fetch with \`get_page_blocks\` for current state.`;

    // ── Block tier / preference enforcement ────────────────────────
    case 'legacy_block':
      return blockName
        ? `${blockName} is in a namespace this site has configured as legacy. Use ${hints.suggested_replacement ?? 'a core block instead'}.`
        : 'Legacy block rejected. Use a core block (or a higher-tier alternative) instead.';

    case 'inner_html_required': {
      const attrs = Array.isArray(hints.source_bound_attributes)
        ? hints.source_bound_attributes.join(', ')
        : 'one or more';
      return blockName
        ? `${blockName} stores attribute(s) [${attrs}] in HTML markup. Include \`innerHTML\` matching the attribute value (e.g. \`<p>{content}</p>\` for core/paragraph) — without it the saved block is self-closing and Gutenberg reports "Block contains unexpected or invalid content" on next edit.`
        : `Block stores source-bound attribute(s) [${attrs}] in HTML markup but no \`innerHTML\` was provided. The saved block would be self-closing and Gutenberg would flag it as invalid on next edit.`;
    }

    case 'static_markup_stale_risk':
      return 'Updating attributes on a static block without new innerHTML may leave its rendered markup stale. Pass `innerHTML` alongside `attributes`, or use a dynamic block.';

    // ── Rate limiting ──────────────────────────────────────────────
    case 'rate_limit_exceeded': {
      const where = hints.post_id !== undefined ? `on post ${hints.post_id} ` : '';
      return `Too many writes ${where}in the last minute. Wait ~60s before retrying, or batch your edits into a single \`edit_block_tree\` call.`;
    }

    // ── v1.2 post lifecycle ────────────────────────────────────────
    case 'mixed_trash_payload':
      return '`status: "trash"` cannot be combined with other fields. Trash the post in one call, then update other fields after.';

    case 'invalid_post_type':
      return 'Post type not allowed by this site\'s gk_block_api_post_types_allowlist option. Ask the site admin to add it, or pick a supported type.';

    case 'invalid_status':
      return 'Post status not allowed. Valid values: draft, pending, publish, future, private (trash via DELETE only).';

    // ── Media uploads ──────────────────────────────────────────────
    case 'invalid_url':
      return 'URL rejected by SSRF guard. Hostnames pointing at private/loopback/cloud-metadata IPs are blocked. Use a publicly reachable URL.';

    case 'disallowed_mime':
      return 'File MIME type isn\'t in WordPress\'s allowed-uploads list. Convert to PNG/JPG/WEBP for images, MP4 for video, etc.';

    default:
      return null;
  }
}

/** Render a path array as `[0, 2, 1]` for error messages. */
function formatPath(path: number[] | undefined): string {
  if (!path || path.length === 0) return '?';
  return `[${path.join(', ')}]`;
}
