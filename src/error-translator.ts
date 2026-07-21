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
  /** Block name involved in a legacy/avoid/dual-storage rejection. */
  block?: string;
  /** Suggested replacement block name suggested by the preference engine. */
  suggested_replacement?: string;
  /** Status from the data envelope, sometimes present even on success. */
  status?: number;
  /** Some endpoints return `block_name` instead of `block`. */
  block_name?: string;
  /** flat_index that was rejected as out of range (invalid_index). */
  flat_index?: number;
  /** Revision ID the caller's If-Match expected (stale_revision). */
  expected_revision?: number;
  /** Revision ID currently stored (stale_revision). */
  current_revision?: number;
  /** Configured max nesting depth (block_depth_exceeded). */
  max_depth?: number;
  /** Actual nesting depth that exceeded max_depth (block_depth_exceeded). */
  actual_depth?: number;
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
  if (typeof d.flat_index === 'number')          hints.flat_index = d.flat_index;
  if (typeof d.expected_revision === 'number')   hints.expected_revision = d.expected_revision;
  if (typeof d.current_revision === 'number')    hints.current_revision = d.current_revision;
  if (typeof d.max_depth === 'number')           hints.max_depth = d.max_depth;
  if (typeof d.actual_depth === 'number')        hints.actual_depth = d.actual_depth;

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
      return 'REST route not found at this site. Confirm the Block MCP plugin is active and the WORDPRESS_URL is correct.';

    case 'rest_forbidden':
    case 'rest_cannot_edit':
    case 'rest_cannot_create':
      return 'Permission denied. The Application Password\'s user lacks the required capability (typically `edit_posts`, or `edit_post` on this specific post).';

    case 'rest_cookie_invalid_nonce':
    case 'rest_authentication_required':
      return 'Authentication failed. Confirm WORDPRESS_USER and WORDPRESS_APP_PASSWORD are set to a valid Application Password (not a regular login password).';

    // ── Post lookup ────────────────────────────────────────────────
    // `post_not_found` is the code the plugin actually emits;
    // `rest_post_invalid_id` is WordPress core's equivalent, kept as an
    // alias in case a core route ever surfaces it.
    case 'rest_post_invalid_id':
    case 'post_not_found': {
      const target = hints.post_id !== undefined ? `Post ${hints.post_id}` : 'Post';
      return `${target} not found. List pages with \`list_posts\` to find the right ID.`;
    }

    case 'not_found':
      // Generic 404 from our own handlers (post / pattern / media missing).
      return hints.post_id
        ? `Post ${hints.post_id} not found. It may have been deleted, or the ID is wrong.`
        : 'Resource not found. It may have been deleted, or the ID is wrong.';

    // ── Block ref / path resolution ────────────────────────────────
    case 'invalid_ref':
      return 'Ref must be a non-empty string. Use the `ref` value returned by `get_page_blocks` — not a made-up ID.';

    case 'ref_stale': {
      const where = hints.post_id !== undefined ? ` in post ${hints.post_id}` : '';
      return `Block ref \`${hints.ref ?? '?'}\`${where} no longer resolves to a block. It may have been deleted, or the ref is from an older snapshot — call \`get_page_blocks\` again to get current refs.`;
    }

    case 'invalid_path':
      return `Block path ${formatPath(hints.path)} doesn't address an existing block (or isn't a valid array of non-negative integers). Re-fetch the post with \`get_page_blocks\` to get current paths — paths shift when blocks are added or removed.`;

    case 'invalid_index': {
      const idx = typeof hints.flat_index === 'number' ? ` ${hints.flat_index}` : '';
      return `Block index${idx} out of range. Re-fetch the post with \`get_page_blocks\` to get current indices — they shift when blocks are added or removed.`;
    }

    // ── Block tier / preference / storage enforcement ───────────────
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

    case 'dual_storage_requires_both':
      return blockName
        ? `${blockName} is dual-storage: \`attributes\` and \`innerHTML\` carry the same data and must be sent together — sending only one silently desyncs the other. Pass both fields in the same call.`
        : 'This block is dual-storage: `attributes` and `innerHTML` carry the same data and must be sent together — sending only one silently desyncs the other. Pass both fields in the same call.';

    case 'block_depth_exceeded': {
      const depth = typeof hints.max_depth === 'number' && typeof hints.actual_depth === 'number'
        ? ` (max ${hints.max_depth}, got ${hints.actual_depth})`
        : '';
      return `Block tree exceeds the maximum nesting depth${depth}. Flatten the structure — split deeply nested groups into separate top-level blocks.`;
    }

    // ── Concurrency / staleness ──────────────────────────────────────
    case 'edit_conflict':
      return 'The post content changed since it was read (a concurrent write raced this one). Re-fetch the page with `get_page_blocks` and retry your edit against the current content.';

    case 'stale_revision': {
      const rev = typeof hints.current_revision === 'number' ? ` (current revision: ${hints.current_revision})` : '';
      return `The post has changed since you fetched it${rev}. Re-fetch with \`get_page_blocks\` and retry.`;
    }

    // ── Rate limiting ──────────────────────────────────────────────
    case 'rate_limit_exceeded': {
      const where = hints.post_id !== undefined ? `on post ${hints.post_id} ` : '';
      return `Too many writes ${where}in the last minute. Wait ~60s before retrying, or batch your edits into a single \`edit_block_tree\` call.`;
    }

    case 'rate_limit_locked': {
      const where = hints.post_id !== undefined ? ` on post ${hints.post_id}` : '';
      return `Another write${where} is in progress. Retry in a moment; the lock clears within a second. To avoid contention, batch edits into a single \`edit_block_tree\` call.`;
    }

    // ── v1.2 post lifecycle ────────────────────────────────────────
    case 'mixed_trash_payload':
      return '`status: "trash"` cannot be combined with other fields. Trash the post in one call, then update other fields after.';

    case 'invalid_post_type':
      return 'Post type not allowed by this site\'s gk_block_api_post_types_allowlist option. Ask the site admin to add it, or pick a supported type.';

    case 'invalid_status':
      return 'Post status not allowed. Valid values: draft, pending, publish, future, private. To trash, call update_post with status:"trash" (on its own, not combined with other fields).';

    case 'trash_disabled':
      return 'Moving posts to trash is turned off for this site. A site administrator can enable it under Block MCP → Settings, or use update_post with a different status.';

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
