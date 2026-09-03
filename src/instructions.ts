/**
 * Per-site `serverInfo.instructions` assembly (BLOCK-19).
 *
 * The MCP server ships a hard-coded baseline string in `BASELINE`. At
 * startup, the server fetches an admin-editable addendum from the
 * connected WordPress site via `GET /gk-block-api/v1/instructions` and
 * appends it to the baseline. The combined string is passed to the
 * `McpServer` constructor's `instructions` field, where the MCP SDK
 * surfaces it to clients during the initialize handshake.
 *
 * Two opt-out paths:
 *
 * - The fetch wraps every step in try/catch and falls back to baseline-
 *   only on any failure (DNS, timeout, 404, 5xx, malformed JSON). The
 *   server never blocks startup on the fetch.
 * - The `BLOCK_MCP_INSTRUCTIONS_OFF=1` env var skips the fetch entirely
 *   — useful for offline testing and isolation.
 *
 * Threat model: the remote addendum comes from the WP options table and
 * is sanitized server-side, but a compromised WP install could still
 * push malicious instructions. This module performs defense-in-depth
 * sanitization (length cap, control-char strip, suspicious-pattern
 * filter) before passing the value to the SDK. The primary control
 * remains "don't connect Block MCP to a WP site you don't trust."
 */

import axios, { AxiosError } from 'axios';
import { restRouteUrl } from './rest-url.js';

/**
 * The baseline instructions string.
 *
 * This is the canonical, version-controlled guidance every MCP client
 * receives, regardless of which WordPress site the server connects to.
 * Site-specific rules go in the WP option served by `/instructions`.
 *
 * Kept verbatim in sync with the inline literal that previously lived
 * at `src/index.ts:102-107` so existing clients see no change in
 * behaviour when no addendum is set.
 */
export const BASELINE = `Block-level WordPress CRUD. URL → post_id is resolved server-side — pass URLs directly to get_page_blocks / resolve_url; never shell out to curl or wp-json.

After a write, the response already includes the canonical post-save snapshot (\`saved.inner_html\` + \`saved.attributes\` on update_block; \`saved\` per result on update_blocks with \`verbose:true\`). Use that for verification — do not fetch the public page to confirm edits. If you need a single-block re-read later, call get_block(ref) — it returns the block flat at the top level (\`name\`, \`ref\`, \`attributes\`, \`inner_html\`) plus the same \`saved\` snapshot as an alias.

Tier policy is per-site config, surfaced inline (block.preference) and via list_block_types. Read block-mcp://agent-guide for the editing workflow.`;

/**
 * Server-side cap on the addendum length. Mirrors `Instructions::MAX_LENGTH`
 * in the PHP plugin. Truncation is silent — `McpServer` doesn't care, and
 * surfacing an error would block startup over a value that's already on
 * the wire.
 */
export const MAX_ADDENDUM_LENGTH = 2000;

/**
 * Timeout for the addendum fetch. Short, because we don't want startup
 * to feel laggy when the site is offline — falling back to baseline-only
 * is preferable to hanging.
 */
const FETCH_TIMEOUT_MS = 3000;

/**
 * Hard cap on the response body axios accepts from `/instructions`.
 *
 * The expected payload is `{ addendum, length, max_length, updated_at }`
 * where `addendum` is at most `MAX_ADDENDUM_LENGTH` characters. With
 * 4-byte UTF-8 codepoints and the JSON envelope, the realistic ceiling
 * is well under 10 KB. Capping at 16 KB gives generous headroom while
 * making a compromised or malicious WP site unable to push an unbounded
 * stream of bytes at us (slowloris-style DoS, memory pressure).
 *
 * The HTTP-layer cap is the primary defense; `sanitizeAddendum` is the
 * secondary defense. Both stay in place.
 */
const FETCH_MAX_BYTES = 16 * 1024;

/**
 * Env var that disables the fetch entirely. Useful for offline tests,
 * isolation, or when running against an internal site whose admin you
 * don't trust to manage the addendum.
 */
const OFF_ENV_VAR = 'BLOCK_MCP_INSTRUCTIONS_OFF';

/**
 * Response envelope from `GET /gk-block-api/v1/instructions`.
 *
 * `length` and `max_length` are advisory — the TypeScript side re-checks
 * the actual addendum string and enforces its own truncation.
 */
export interface InstructionsResponse {
  addendum: string;
  length?: number;
  max_length?: number;
  updated_at?: number;
}

/**
 * Sanitize a remote addendum before handing it to the MCP SDK.
 *
 * Defense in depth — the PHP side already does most of this, but we
 * cannot assume the WordPress install has the plugin version that
 * sanitizes on the read path. Steps:
 *
 * 1. Cast to string; non-strings (objects, arrays, null) return empty.
 * 2. Strip ASCII C0 control characters except `\t` (tab), `\n` (LF),
 *    `\r` (CR) — same set the PHP side keeps.
 * 3. Strip the DEL character (`\x7F`).
 * 4. Strip the unicode Bidi override and zero-width characters that
 *    have been used in prompt-injection PoCs to hide text from human
 *    review while still being visible to the LLM.
 * 5. Normalize CRLF/CR to LF.
 * 6. Trim outer whitespace.
 * 7. Truncate to `MAX_ADDENDUM_LENGTH` characters.
 */
export function sanitizeAddendum(input: unknown): string {
  if (typeof input !== 'string') {
    return '';
  }
  let s = input;

  // ASCII C0/C1 control chars except tab/LF/CR + DEL.
  s = s.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');

  // Unicode Bidi overrides + zero-width chars. These render invisibly in
  // some clients but still reach the LLM tokenizer, making them a
  // prompt-injection vector. Stripped via explicit escape sequences so
  // the source is readable and the set is unambiguous.
  //
  //   U+200B  ZERO WIDTH SPACE
  //   U+200C  ZERO WIDTH NON-JOINER
  //   U+200D  ZERO WIDTH JOINER
  //   U+2060  WORD JOINER
  //   U+FEFF  ZERO WIDTH NO-BREAK SPACE / BOM
  //   U+202A..U+202E LRE/RLE/PDF/LRO/RLO Bidi overrides
  //   U+2066..U+2069 LRI/RLI/FSI/PDI Bidi isolates
  s = s.replace(/[\u200B-\u200D\u2060\uFEFF\u202A-\u202E\u2066-\u2069]/g, '');

  // CRLF / CR → LF.
  s = s.replace(/\r\n?/g, '\n');

  s = s.trim();

  // Count and slice by Unicode code points, NOT UTF-16 code units, so an
  // astral character (emoji, rare CJK, math symbol) is never split mid
  // surrogate pair — which would leave the tail as an unpaired surrogate
  // that downstream JSON serializers either reject or mangle. Matches
  // the server's `mb_strlen($s, 'UTF-8')` semantics.
  const codePoints = Array.from(s);
  if (codePoints.length > MAX_ADDENDUM_LENGTH) {
    s = codePoints.slice(0, MAX_ADDENDUM_LENGTH).join('');
  }

  return s;
}

/**
 * Combine the baseline with an optional addendum into the final string
 * passed to `McpServer`. When the addendum is empty, returns the
 * baseline unchanged — clients that don't customize see no marker
 * polluting the handshake.
 *
 * Two newlines between baseline and addendum so markdown renderers in
 * MCP clients see them as separate paragraphs / sections.
 */
export function combineInstructions(baseline: string, addendum: string): string {
  const clean = addendum.trim();
  if (clean.length === 0) {
    return baseline;
  }
  return `${baseline}\n\n${clean}`;
}

/**
 * Fetch the addendum from the WordPress site's `/instructions` endpoint.
 *
 * Public, unauthenticated request (the endpoint is public-by-design).
 * On any failure — network error, non-200 response, malformed JSON,
 * missing addendum field — returns an empty string. Logs the cause to
 * stderr so admins can debug, but never throws.
 *
 * Honors `BLOCK_MCP_INSTRUCTIONS_OFF=1` by returning empty immediately
 * (no HTTP call).
 *
 * @param wordpressUrl  Base URL of the WordPress site (no trailing slash
 *                      enforced — the function normalizes both forms).
 */
export async function fetchAddendum(wordpressUrl: string): Promise<string> {
  if (process.env[OFF_ENV_VAR] === '1') {
    return '';
  }

  // Permalink-independent ?rest_route= form: /wp-json/ 404s on plain permalinks.
  const url = restRouteUrl(wordpressUrl, '/instructions');

  try {
    const response = await axios.get<InstructionsResponse>(url, {
      timeout: FETCH_TIMEOUT_MS,
      // Disable axios's automatic redirect following entirely. Without
      // this, a compromised or misconfigured WP site could redirect us
      // to a different origin (an exfil endpoint, an SSRF target on the
      // internal network, etc.). Admins should configure
      // WORDPRESS_URL with the canonical scheme + host so the first
      // hop returns 200 directly. If the site needs an HTTP → HTTPS
      // redirect, fix the env var instead of relying on axios to
      // follow it for us.
      //
      // The corollary is a 3xx response now surfaces as an axios
      // error and we fall back to baseline-only. Logged to stderr.
      maxRedirects: 0,
      // Hard cap the response body size. Primary defense against an
      // unbounded payload — `sanitizeAddendum` still truncates after,
      // but we never want raw bytes past this cap to hit our process.
      maxContentLength: FETCH_MAX_BYTES,
      // Lower-case Accept so caches see a stable Vary key.
      headers: {
        Accept: 'application/json',
      },
      // We treat 2xx as success; 3xx (any redirect) and 4xx/5xx fall
      // back to empty via the catch block.
      validateStatus: (status) => status >= 200 && status < 300,
    });

    if (!response.data || typeof response.data !== 'object') {
      console.error(
        `[block-mcp] /instructions returned non-object payload; using baseline only.`
      );
      return '';
    }

    return sanitizeAddendum(response.data.addendum);
  } catch (err) {
    // Don't crash startup — empty addendum, log to stderr, continue.
    const message = formatFetchError(err);
    console.error(`[block-mcp] Failed to fetch /instructions (${message}); using baseline only.`);
    return '';
  }
}

/**
 * Convenience helper: fetch + combine in one call. The default entry
 * point used by `src/index.ts`.
 */
export async function getInstructions(wordpressUrl: string): Promise<string> {
  const addendum = await fetchAddendum(wordpressUrl);
  return combineInstructions(BASELINE, addendum);
}

/**
 * Map axios / network errors into a short stderr message. Kept private
 * because callers shouldn't depend on the exact wording.
 */
function formatFetchError(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const axiosErr = err as AxiosError;
    if (axiosErr.code === 'ECONNABORTED' || axiosErr.message.includes('timeout')) {
      return `timeout after ${FETCH_TIMEOUT_MS}ms`;
    }
    if (axiosErr.response) {
      return `HTTP ${axiosErr.response.status}`;
    }
    if (axiosErr.code) {
      return axiosErr.code;
    }
    return axiosErr.message;
  }
  if (err instanceof Error) {
    return err.message;
  }
  return String(err);
}
