=== GK Block API ===
Contributors: gravitykit, katzwebservices
Tags: blocks, rest-api, gutenberg, mcp, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API for block-level CRUD operations with smart preferences for AI agents.

== Description ==

GK Block API provides a comprehensive REST API for reading, editing, and managing WordPress block content at a granular level. Designed for AI agents (via MCP — Model Context Protocol), it enables surgical block editing without rewriting entire pages.

**Key Features:**

* **Block CRUD** — Read, insert, update, delete, replace, and atomic-range-replace blocks via REST endpoints.
* **Path-based mutations** — Edit nested blocks (e.g., a button inside a column inside a group) using integer array paths like `[0, 2, 1]`.
* **9 mutation operations** — `update-attrs`, `update-html`, `replace-block`, `remove-block`, `wrap-in-group`, `unwrap-group`, `insert-child`, `duplicate`, `move`.
* **Atomic range replace** — Swap N blocks for M blocks in a single revision (no half-written intermediate state).
* **Static block safety guards** — Warns when attribute changes on static blocks may leave rendered markup inconsistent.
* **Auto-transform innerHTML** — Automatically updates HTML when attributes imply structural changes (heading level, list ordered, group tagName, etc.). Uses `WP_HTML_Tag_Processor` for safe attribute manipulation.
* **Preference scoring** — Configurable namespace scores rank blocks by preference tier (preferred, acceptable, avoid, legacy). Includes a replacement map for legacy block migration.
* **Dual-storage detection** — Refuses innerHTML-only updates on blocks like `yoast/faq-block` where attributes and innerHTML must stay in sync.
* **Storage-mode auto-discovery** — One-shot scanner classifies every distinct block name on the site as static / dynamic / dual.
* **Pattern management** — List, search, and insert synced or registered patterns with preference scoring and legacy block detection.
* **Site usage analytics** — Block and pattern usage statistics across all published content.
* **Render mode** — Optional `render=true` parameter includes server-rendered output for dynamic blocks, expands shortcodes, resolves synced pattern content.
* **URL resolver** — Map any site URL to its post ID, type, and title.
* **Block search** — Find blocks by name or text content within a page.
* **Revision tracking** — Every write operation creates WordPress revisions with before/after IDs for easy rollback.
* **Rate limiting** — Per-post write limits to prevent runaway automated edits.
* **Connect onboarding** — A few-clicks flow (Settings → Block MCP → Connect) to connect an AI assistant: a one-click Claude Desktop installer (.mcpb) or a browser-approve flow that writes the client config for you, backed by a dedicated minimal-capability service account.
* **Settings UI** — Admin page (Settings → Block MCP) for editing tier scores, replacement map, dual-storage list, and post-type allow-list.
* **Post lifecycle tools** — Create and update posts, list taxonomy terms, upload media (with SSRF guard).
* **Server-driven preference policy** — All namespace scoring is configurable per-site; nothing is hardcoded in the codebase.

**REST Endpoints (under `gk-block-api/v1`):**

* `GET /block-types` — Registered block types with preference scores.
* `GET /patterns` — Synced and registered patterns with scoring.
* `GET /patterns/{id}` — Single pattern with parsed block content.
* `GET /patterns/search` — Search patterns by keyword.
* `GET /site-usage` — Block and pattern usage statistics.
* `GET /resolve` — Resolve a URL to a post ID.
* `GET /find-posts` — Search posts with pagination.
* `GET /post-info` — Look up post metadata by ID, URL, or slug.
* `GET /terms` — List taxonomy terms.
* `GET /posts/{id}/blocks` — Page blocks as structured JSON with paths.
* `POST /posts/{id}/blocks` — Insert blocks at a position.
* `POST /posts/{id}/blocks/replace` — Atomic range replace.
* `PATCH /posts/{id}/blocks/{index}` — Update a single block.
* `DELETE /posts/{id}/blocks/{index}` — Remove a block.
* `PUT /posts/{id}/blocks` — Full page rewrite.
* `POST /posts/{id}/mutate` — Path-based mutation (9 operations).
* `POST /posts/{id}/insert-pattern` — Insert a pattern (synced or inline).
* `POST /posts` — Create a new post or page.
* `PATCH /posts/{id}` — Update post metadata, status, or terms.
* `POST /media` — Upload to media library (multipart, URL sideload, or base64).
* `POST /storage-modes/scan` — Auto-discover storage modes site-wide.

== Installation ==

1. Upload the `gk-block-mcp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Connect an AI assistant: Settings → Block MCP → Connect, pick your client, and follow the one-click (.mcpb) or browser-approve flow. This provisions a dedicated service account and its credential automatically.
4. (Optional) Visit Settings → Block MCP to review tier scores and the post-type allow-list.
5. To wire up the REST API by hand instead, create an Application Password (Users → Profile → Application Passwords) and authenticate with Basic Auth.

== Frequently Asked Questions ==

= Does this plugin modify my site's front end? =

No. GK Block API is a REST-only plugin — it adds no front-end output, scripts, or styles. It only provides API endpoints and a settings page for managing block content.

= What authentication does it require? =

WordPress Application Passwords with Basic Auth over HTTPS. The authenticated user must have `edit_post` capability for the target post (writes), `edit_posts` capability (reads), or `manage_options` (settings + storage-mode scan).

= Does it work with custom post types? =

Yes. The block API works with any post type that stores block content in `post_content`, including pages, posts, and custom post types like EDD Downloads. The `create_post` tool's allow-list (in Settings → Block MCP) controls which types can be created via the API.

= What is the MCP server? =

The MCP (Model Context Protocol) server is a separate TypeScript application that wraps the REST API as AI-friendly tools. It runs locally on the developer's machine and connects to the WordPress plugin via REST. The MCP server is not required to use the REST API directly.

= Can I connect a client manually, without the Connect flow? =

Yes — the Connect flow is just a convenience. To wire up any MCP client by hand:

1. Create an Application Password for a WordPress user that can edit posts (Users → Profile → Application Passwords).
2. Add the Block MCP server to your client's MCP configuration, with your site URL, that username, and the Application Password:

    {
      "mcpServers": {
        "block-mcp": {
          "command": "npx",
          "args": ["-y", "@gravitykit/block-mcp"],
          "env": {
            "WORDPRESS_URL": "https://your-site.com",
            "WORDPRESS_USER": "your-username",
            "WORDPRESS_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
          }
        }
      }
    }

3. Restart the client. The same `@gravitykit/block-mcp` package powers both this manual setup and the Connect flow.

= How do I configure which blocks are "legacy" on my site? =

Visit Settings → Block MCP. Set the score for a namespace to less than 10 to mark it as legacy (hard-rejected on insert). Use the replacement map to suggest alternatives.

= What happens to data if I uninstall the plugin? =

`uninstall.php` deletes all plugin options and transients (`gk_block_api_preferences`, `gk_block_api_post_types_allowlist`, `gk_block_api_storage_modes`, the manual dual-storage list, the inventory cache, and per-post rate-limit transients). Post content and revisions are not touched.

== Changelog ==

= 2.0.2 on June 15, 2026 =

This release fixes connecting on sites protected by a server security firewall, which previously blocked setup partway through with an "Access Denied" page.

#### 🐛 Fixed

* Fixes connecting being blocked on sites running a server security firewall (such as Monarx), where setup returned an "Access Denied" page and never completed. The dedicated assistant account is now created when you open the connect screen, and its credential is issued on a separate step when you approve — so the firewall no longer mistakes setup for a single suspicious "create an account and hand out its password" action.

= 2.0.1 on June 12, 2026 =

This release makes connecting to a local development site smoother: clearer error messages when a connection fails, and a connection that keeps working after setup.

#### ✨ Improved

* Improves the error message when connecting to your site fails. Instead of a cryptic "fetch failed", you now see what actually went wrong — for example, a security certificate your computer doesn't trust yet — along with how to fix it.

#### 🐛 Fixed

* Fixes the AI assistant losing access to a local development site right after connecting successfully. The secure-connection setting used during setup now carries over to the assistant automatically, so it keeps working with sites built using tools like Laravel Herd, Valet, Local, or OrbStack.

#### 💻 Developer Updates

* The connector now surfaces the underlying TLS/network error code when the credential exchange fails, and propagates `NODE_EXTRA_CA_CERTS` into every generated MCP client config (`claude mcp add`, Claude Desktop, Cursor, and printed configs).

= 2.0.0 on June 10, 2026 =

Block MCP 2.0 lets you connect an AI assistant like Claude to your site in a few clicks — no terminal and no config files — using a dedicated, limited account that keeps the assistant's access separate from your own login.

#### 🚀 Added

* **Connect an AI assistant in a few clicks.** A new Connect screen at Settings → Block MCP walks you through linking an AI app to your site — no command line, no editing files. Pick a one-click **Claude Desktop** installer, a browser **Approve** step for **Cursor** or **Claude Code** that sets everything up for you, or **Configure it myself** for any other MCP client.
* **A separate, limited account for the AI.** Block MCP gives the assistant its own account to write and edit your posts, pages, and custom post types — like documentation or portfolios. It can't change your site settings, delete other people's content, or be used to log in — so the AI never has more access than it needs.
* **Or connect through your own account.** Most sites should use the dedicated account above. If the assistant needs the same access your own account has, you can connect through your account instead — it's clearly flagged as the higher-risk choice and asks you to confirm you understand before finishing.
* **Stay in control of your connections.** See every connected app on the Connect screen — including which account it uses and who approved it — and **Disconnect** any of them instantly to revoke its access.
* **Decide what the AI is allowed to do.** Choose which content types it can create (or allow them all), turn media uploads on or off, and allow or block moving posts to the trash (off by default — and even when allowed, the assistant can only trash content, never permanently delete it). You can also set custom instructions it should always follow, and advanced users can tune which blocks the assistant prefers.
* **Keyboard and screen-reader friendly.** The Connect and settings screens are fully operable with a keyboard and work with screen readers.

#### ✨ Improved

* Improves the built-in guidance the AI assistant receives, so its edits land right the first time: it can now build nested layouts (groups, columns, callout boxes) in a single step, and knows exactly how to position new blocks on the page.
* The assistant can now read very large pages in smaller chunks instead of all at once, so editing long pages is faster and more reliable.

#### 🔒 Security & privacy

* When you connect, your site credential is never placed in a web address or left in your browser history, and any client config files are written so that only you can read them.

#### 🐛 Fixed

* Fixes adding an image from a file on your computer through the assistant — local-file uploads previously didn't get uploaded (adding by web address or pasted data worked).
* Fixes the assistant silently adding content to the end of the page when it described the position the wrong way — it now gets corrected immediately with the valid options, so new blocks land where you asked.

#### 🛠 Developer note

* Every plugin hook now uses the GravityKit `gk/block-mcp/{area}/{name}` naming — for example, the trash filter `gk_block_api_allow_trash` is now `gk/block-mcp/post/allow-trash`. If you've customized Block MCP with your own code, rename your `add_filter()` / `add_action()` calls to match. Option names are unchanged.

= 1.8.1 on May 22, 2026 =

Fixes nested container blocks rendering their children as top-level siblings on the front end while looking correct in the editor. Pages built inside-out — adding columns first, then columns/column children, then content — now render with children correctly nested inside their wrappers.

#### 🐛 Fixed

* Nested layouts built via `insert-child` (columns/column, group, buttons, hero splits, and similar containers) now serialise with children inside their wrapper instead of as siblings outside it. The editor was always correct; only the front-end output was broken.

= 1.8.0 on May 21, 2026 =

Every WordPress core block and the full Gutenberg trunk block library now compose cleanly through the write API. Each insert is validated against the block's inline HTML attribute definitions, so malformed input is caught up front with a clear, actionable error before becoming an "invalid content" warning in the editor.

#### ✨ Improved

* Full compatibility coverage across every block in WordPress core and the entire Gutenberg trunk block library — each release verifies the write API works against the shipping block catalogue, so when a new block type lands it's usable immediately.
* `insert_blocks` reads each block's own inline HTML attribute definitions from its `block.json` schema and validates inserts against them. When an attribute that lives in saved HTML arrives without matching `innerHTML`, the API responds with a clear `inner_html_required` error that names the field and shows the canonical form to use — covering `core/paragraph`, `core/heading`, `core/image`, `core/button`, `core/html`, `core/shortcode`, and every other block whose data is stored in markup.
* Legacy-block errors fire with a replacement suggestion even on sites that never had the source plugin installed, surfacing actionable guidance instead of a generic "not registered" error.

#### 🐛 Fixed

* Strips empty `class=""` attributes from `innerHTML` on save — they previously surfaced as "Block contains unexpected or invalid content" warnings the next time the page was edited.

= 1.7.1 on May 20, 2026 =

Two Code Block Pro rendering bugs fixed and one hardening pass on the wrapper markup. All three changes ship in the TypeScript MCP server; the WordPress plugin is unchanged apart from version metadata.

#### 🐛 Fixed

* Code Block Pro blocks inserted via the MCP (e.g. `edit_block_tree` → `replace-block`) now render correctly on the front-end. The enricher previously populated only the `codeHTML` attribute and left `innerHTML` empty, so the block saved successfully but appeared as a blank gap on the page. The enricher now builds a minimal wrapper (`<div class="wp-block-kevinbatdorf-code-block-pro" style="…">{codeHTML}{optional <textarea>}</div>`) when `innerHTML` is missing, mirroring CBP's `save()` inline-style behaviour.
* Explicit `language: "plaintext"` is now respected instead of triggering auto-detection. Previously the enricher collapsed missing + `plaintext` into "infer the language", and a chat-style prompt containing the word "from" twice tripped the SQL signal in `inferLanguage()` — the block rendered with mis-coloured English words ("Set", "Block", "from", "URL", "and", "Password"). The contract now is: missing / `''` / `'auto'` → infer; `'plaintext'` / `'text'` / `'plain'` / `'txt'` / `'none'` → render as plaintext, no inference; anything else → use verbatim.

#### 🔒 Security

* Caller-supplied wrapper attributes (`fontFamily`, `fontSize`, `lineHeight`, `bgColor`, `textColor`, `className`) are now HTML-encoded before being interpolated into the wrapper `style="…"` and `class="…"` attributes. A value containing `"` (whether accidental — `"Helvetica Neue"` — or hostile — `safe" onclick="alert(1)`) used to break out of the attribute and either corrupt the markup or inject active content. The new `escapeAttr` helper maps `& < > " '` to their entity equivalents on every wrapper value before the `<div>` is built.

#### 🧪 Tests

* 14 new vitest cases in `src/__tests__/unit/enrichers/cbp-enricher.test.ts`:
  * Wrapper-build path (no incoming `innerHTML`): wrapper class + Shiki `<pre>` present; inline-style values mirrored from font/colour attributes; copy `<textarea>` present when `copyButton: true`, omitted when `false`; literal `</textarea>` in source code escaped.
  * Language contract: explicit `plaintext` respected; `text` / `plain` / `txt` / `none` treated as plaintext aliases; inference runs only when language is missing or `'auto'`; explicit non-plaintext language passes through verbatim; regression for the English-prose-detected-as-SQL bug.
  * Attribute encoding: double-quotes in style values escape; `<script>` and `&` in style values escape; double-quotes in className escape.
* Existing "no-op when already enriched" test updated to assert no-op only when both `codeHTML` AND `innerHTML` are already current — partial enrichment correctly triggers wrapper rebuild instead of returning the half-built block.

#### 💻 Developer Updates

* New TypeScript export: `escapeAttr(value: string): string` in `src/enrichers.ts`. Mapped to entities: `& → &amp;`, `< → &lt;`, `> → &gt;`, `" → &quot;`, `' → &#39;`. Callers MUST quote the resulting value with `"` (the apostrophe escape uses `&#39;` for older-browser compatibility, the rest assume `"` surrounds).
* New TypeScript language-attribute contract documented in code: missing → infer; `auto` → infer; `plaintext` (and aliases `text` / `plain` / `txt` / `none`) → render as plaintext, no inference; anything else → use verbatim. Callers that previously relied on `language: "plaintext"` triggering auto-detect must switch to `language: "auto"` (or omit the attribute) to keep the same behaviour.

= 1.7.0 on May 20, 2026 =

New per-site MCP server instructions addendum. Admins paste rules in **Settings → Block MCP**; the TypeScript MCP server fetches them on startup and appends to its hard-coded baseline before constructing `serverInfo.instructions`, so every connected client receives the same site-specific conventions at handshake — eliminating the per-session rediscovery LLM agents currently do for things like callout className conventions or code-block theme choices.

#### ✨ New

* New admin field at **Settings → Block MCP** → *MCP server instructions*. Plain-text textarea, 2,000-character cap, live character counter, in-page warning that the value is served unauthenticated.
* New REST endpoint `GET /gk-block-api/v1/instructions`. Returns `{ addendum, length, max_length, updated_at }` with `Cache-Control: public, max-age=60`. Unauthenticated by design — the value reaches MCP clients before any tool-call auth, same posture as MCP `initialize` itself.
* New `Instructions` service class (`includes/class-instructions.php`) owning option storage, sanitize, length cap, and the rate-limit bucket.

#### 🔒 Security

* Save path sanitization: `wp_strip_all_tags` (HTML/PHP), `strip_shortcodes` (no `do_shortcode` is ever called on this value), C0/C1 control characters stripped except `\t \n \r`, length cap enforced after sanitize so the 2,000-character budget is the post-sanitize size that reaches clients.
* Read path sanitization (defense in depth) — the same `Instructions::sanitize` runs on every `get_addendum()` so direct `update_option` writes from sibling plugins or database restores can't bypass it.
* Public read endpoint rate-limited at 30 req/min per remote IP (sliding 60s window, IP hashed before use as a transient key for PII minimization).
* Admin field is `manage_options`-gated; settings save retains the Settings API's built-in nonce.

#### 💻 Developer Updates

* `Instructions::OPTION_KEY`, `Instructions::UPDATED_AT_OPTION`, `Instructions::MAX_LENGTH`, `Instructions::RATE_LIMIT_PER_MIN` exposed as public constants for downstream callers.
* `Instructions::sanitize( $value )` callable from anywhere as the canonical sanitizer for this option.
* New uninstall sweep: `gk_block_api_instructions`, `gk_block_api_instructions_updated_at`, and per-IP `_transient_gk_block_api_instr_rl_*` transients are removed alongside existing plugin data.
* Reset-to-defaults handler clears the new options + per-IP rate-limit transients so admins can wipe instructions without a full uninstall.

= 1.6.1 on May 20, 2026 =

`/patterns` no longer times out on sites with many synced patterns. The per-pattern reference-count query that previously ran 2N LIKE scans against `wp_posts` is now a single chunked aggregate scan cached for an hour. Includes a permission gate on cache refresh, an orphan-ref filter, and a memory-bounded chunked loop.

#### 🐛 Fixed

* `/patterns` no longer times out at the 30-second client ceiling on sites with many synced patterns. The previous per-pattern reference counter ran two `post_content LIKE '%"ref":N%'` scans per pattern (2N scans per listing); the new aggregate runs one chunked scan, tallies references in PHP, and caches the result in a one-hour transient.
* Cold-cache rebuilds no longer risk `Allowed memory size exhausted`. The aggregate scan pages through `wp_posts` in batches (default 200 rows / chunk) so peak resident memory stays bounded regardless of how many posts contain pattern references.
* Orphaned pattern refs (numeric IDs in `post_content` that don't resolve to a real published `wp_block` on this site — typically from copy-pasted content across installs) are dropped from the cached map instead of growing the transient with bogus keys.

#### ✨ Improved

* `?refresh=true` on `/patterns` now requires `manage_options`. Editors can no longer loop the refresh param to force repeated full-table scans; the base read path remains at `edit_posts`. Editors hitting refresh receive `rest_forbidden_refresh` with the standard authorization-required status.
* The orphan-filter allow-list and the synced-pattern listing query now share a single capacity limit (`gk_block_api_synced_patterns_query_limit`, default 500). Previously the allow-list had no cap and could outgrow the list it was gating on large sites.
* Per-request instance memoization on the reference-count lookup eliminates ~N-1 redundant `get_transient()` calls when formatting a full pattern listing.
* Three `apply_filters` calls (`gk_block_api_synced_patterns_query_limit`, `gk_block_api_legacy_patterns_scan_limit`, `gk_block_api_pattern_ref_scan_batch_size`) now have proper docblocks with parameter types and defaults so WordPress hook documentation tooling can extract them.

#### 💻 Developer Updates

* New filter `gk_block_api_pattern_ref_scan_batch_size` (default 200) — rows pulled per chunk when scanning `post_content` for pattern references. Lower for very-large-content sites; raise for very-small sites.
* New `refresh` query parameter on `GET /patterns` and the `list_patterns` MCP tool — busts the one-hour reference-count cache on demand. Requires `manage_options`.
* New uninstall sweep: `gk_block_api_pattern_ref_counts` transient is removed alongside the other plugin caches.

#### 🧪 Tests

* New `RestControllerTestCase` base wires the nine-collaborator REST controller once for shared use across REST integration tests.
* New `PatternReferenceCountsTest` covers the aggregate scan contract: distinct-post counting, per-post de-duplication, numeric-prefix safety, non-published exclusion, transient persistence, orphan-ID filtering, and chunked-scan correctness across batch boundaries.
* New `PatternsRefreshAuthTest` covers the `manage_options` gate on `/patterns?refresh=true` (editor blocked, admin allowed, refresh check doesn't leak into the base read path).
* New `@group stress` test inserts 2,000 patterns plus 2,000 referencing posts at the production batch size to validate chunked-scan correctness under realistic load.

= 1.6.0 on May 19, 2026 =

This release adds a master kill-switch for media uploads with an admin checkbox, restricts the `create_post` tool to a configurable post-type allowlist, and closes several visibility leaks where drafts and password-protected posts could appear in search and lookup results.

#### 🚀 Added

* Adds a master kill-switch for media uploads. Toggle "Allow MCP agents to upload media" under Settings → Block MCP to refuse every upload path (multipart, URL sideload, base64) with HTTP 403 before any download or disk write happens.
* Adds a "Post types AI agents can create" allowlist in the settings page. Check the post types you want exposed to `create_post`; leave everything unchecked to allow any public post type with REST support (the default).
* Adds an override filter so deliberately-private taxonomies (workflow status, internal department, etc.) can opt back into the `/terms` endpoint for the agent-editing use case without affecting the rest of WordPress's REST surface.

#### ✨ Improved

* Lays out the post-types allowlist as a 3-column grid in the settings page instead of a single inline-wrapped row.
* Hides drafts, private posts, and password-protected posts from `/find-posts`, `/post-info`, `/resolve`, `/terms`, and `/patterns/{id}` for callers without read access. Pagination metadata follows the visible result set, so password-protected counts no longer leak through `total` / `total_pages`.
* Renders separator blocks to valid HTML when `tagName` changes. The shared allowlist with `core/group` could produce `<hr>...</hr>` or rewrite separators to `<div>...</div>`; the two paths are now independent and separators always emit a self-closing `<hr />`.
* Mints fresh block refs when inserting a pattern inline. Repeated inserts of the same pattern no longer share refs with the original, and the response's `index` matches the flat-index vocabulary used by every other write tool.
* Preserves `metadata.gk_ref` and `metadata.bindings` when only updating other metadata fields like `metadata.name`. Partial writes via `edit_block_tree` no longer wipe sibling keys.
* Stores post dates in the site's local timezone for admin sort and date queries (previously stored as GMT in both date columns).

#### 🐛 Fixed

* Preserves `innerHTML` and `innerBlocks` when creating posts via `create_post` and when rewriting all blocks via `rewrite_post_blocks`, so structured block payloads round-trip through the database without dropping nested content. Thanks to [Jake Jackson](https://github.com/jakejackson1) for the fix in [#13](https://github.com/GravityKit/block-mcp/pull/13).
* Toggling Yoast's "Cornerstone Content" off via `yoast_update_seo` actually disables it. The storage previously wrote the literal string `"false"`, which PHP treats as truthy — the API said off and Yoast saw on.
* `yoast_bulk_update_seo` now caps batches at 50 posts per call, matching the per-block batch tools.
* `update_post` rejects `comment_status` / `ping_status` values other than `open` / `closed` instead of silently storing whatever the client sent.
* `create_post` validates the `date` field before passing it to WordPress. Garbage values previously rendered posts unsortable in admin lists.
* Reverting to a previous revision now counts against the per-post write rate limit. Previously revert could be used to bypass the budget.
* Media metadata writes (title, caption, alt-text, description) preserve apostrophes and backslashes through WordPress's slash handling.
* Synced patterns that reference themselves (directly or via an ancestor) surface a `cycle_detected` flag in the response instead of crashing under deep recursion.
* MCP server `get_post_info` rejects floating-point `post_id` values client-side with the documented "post_id must be a positive integer" error.
* REST `parse_error` responses no longer expose internal class names, file paths, or PHP type-error details in production. Full traces are written to the error log; `WP_DEBUG` re-enables them in the response for local debugging.

#### 💻 Developer Updates

* New filter `gk_block_api_uploads_enabled` — return `false` to disable every upload path; runs before any I/O or DNS lookup.
* New filter `gk_block_api_allow_taxonomy_in_terms` — return `true` for a private taxonomy slug to expose it through `/terms` without flipping `show_in_rest`.
* New filter `gk_block_api_url_sideload_blocked_ranges` — admin-extensible IPv4 block list for the SSRF guard on URL sideloads.
* New filters `gk_block_api_legacy_patterns_scan_limit` and `gk_block_api_synced_patterns_query_limit` (both default 500) cap the synced-pattern scans backing `/site-usage` and `/patterns`.
* New option `gk_block_api_post_types_allowlist` (array of post-type slugs) restricts `create_post`. Cleaned up on uninstall.
* New public helper `Block_CRUD::is_post_readable( $post )` — used by every visibility gate above and available to integrators that need the same logic.

= 1.5.1 =
* New: `update_block` and `update_blocks_batch` responses now include a canonical `saved` snapshot — `{ flat_index, block_name, attributes, inner_html, is_dynamic, ref? }`. Single update_block always echoes; batch echoes per-result only when called with `verbose: true` (default false).
* New: `Block_CRUD::get_block(post_id, ref|flat_index)` returns the same `saved` shape — lighter than `get_blocks()` for single-block verification reads.
* New: `GET /posts/{id}/block?ref=...|flat_index=...` REST endpoint (single-block fetch).
* New: `verbose` request param on `POST /posts/{id}/blocks/batch-update` (boolean, default false).
* Fixed: MCP server `create_post` tool dropped `attributes`, `innerHTML`, and `innerBlocks` from each block in its `blocks` array because the input schema only declared `name`. Posts created with structured blocks landed as empty shells, surfacing as "Block contains unexpected or invalid content" in the editor. The schema now uses the shared `BLOCK_INPUT_SCHEMA` constant so all four fields pass through. No PHP-side change — `Post_Manager::create_post()` was always ready to accept the full shape.
* Doc: Plugin readme and inline docblocks now make the "response IS the verification" contract explicit — agents should not refetch the public page (or call `get_page_blocks`) to confirm what a write saved.

= 1.5.0 =
* New: Stable block refs — every block now carries a persistent `gk_ref` (e.g. `blk_a3f2c1q9`) stored in `metadata.gk_ref`. Refs survive sibling inserts/removals so chained mutations don't go stale. All write tools accept `ref` as a targeting alternative to `flat_index` / `path`.
* New: `POST /posts/{id}/blocks/batch-update` — apply N independent block updates atomically in ONE WordPress revision. All-or-nothing validation; per-post rate-limit cost is one write regardless of N. Max 50 items per call.
* New: ETag / If-Match optimistic concurrency on all write endpoints — pass `If-Match: <revision_id>` to reject a write whose pre-state has shifted since the agent's last read.
* New: Cursor pagination on `GET /posts/{id}/blocks` (`cursor` + `limit` query params) — handle multi-thousand-block pages without bloating a single response.
* New: Yoast SEO bridge rolled into the plugin — `yoast_get_seo`, `yoast_update_seo`, `yoast_bulk_update_seo` tools backed by a `/yoast` REST namespace, sharing the same Application Password auth as the block routes.
* New: Translations for 20 WordPress languages.
* Improved: Settings UI polish — natural-sort tier rows, accessible live region for save feedback, refined empty-state copy.
* Deprecated: `before` alias on the `move` mutation op. Use `destination` instead — the alias still works for backwards compatibility.
* Fixed: `replace-block` and `insert-child` mutations no longer drop nested `innerBlocks` from the replacement payload.
* Fixed: Concurrent ref-assignment guarded by object-cache lock — eliminates the race where two simultaneous writes could assign the same `gk_ref` to different blocks.

= 1.4.2 =
* Fixed: Block content passed to `wp_update_post` / `wp_insert_post` is now `wp_slash()`-encoded so core's automatic `wp_unslash()` doesn't strip the leading backslash on every `\n`, `\"`, and `--` escape inside block-comment JSON. Without this, Code Block Pro's escape sequences and CSS variable references (`var(--foo)`) arrived in the database as invalid JSON, breaking block validation in Gutenberg ("This block has been modified externally").
* New: `gk_block_api_format_block` filter — third-party integrations can enrich the formatted block response (used by the Code Block Pro integration to surface `codeHTML`).
* New: Code Block Pro (CBP) dual-storage sync — when `code` / `language` / `theme` attributes change on a CBP block, the plugin auto-regenerates `codeHTML` via Shiki so the editor's preview stays in sync with the saved code.
* Tests: enrichers + CBP auto-transform + format_block filter coverage (175 TypeScript + 249 PHP).

= 1.4.1 =
* Fixed: `insert_blocks` / `replace_blocks_range` silently dropped nested `innerBlocks` when serializing the inserted block tree (BLOCK-1).
* Fixed: SSRF guard on `upload_media` URL sideload now blocks IPv6 reserved ranges in addition to IPv4 (link-local, ULA, loopback, IPv4-mapped).
* Fixed: Uninstall now sweeps options + transients on every site of a multisite network, not just the active site.
* Fixed: Storage-mode scan now uses `WP_Block_Type_Registry` as the primary source of truth (was building from `post_content` scans, which missed unused registered blocks); OOM/DoS hardened with bounded post queries and safe `wpdb` interpolation.
* Fixed: Lifecycle hooks reorganized (instantiation moved off `plugins_loaded` onto `rest_api_init`); global `add_filter` calls replaced with named callbacks so they can be unhooked; debug-log writes gated on `WP_DEBUG`.
* New: `/block-types` endpoint gained `outputSchema`, plus `tier`, `storage_mode`, `search`, and `usage_only` filter params.
* Fixed: MCP server re-audit — stale resource URIs corrected, tool `outputSchema` declarations completed, annotation hints (`readOnlyHint` / `destructiveHint` / `idempotentHint`) aligned with actual behavior.
* i18n: All remaining user-facing strings wrapped in `__()` / `_e()` with the `gk-block-mcp` text domain.

= 1.4.0 =
* New: Settings → Block MCP admin page for editing tier scores, replacement map, dual-storage list, and post-type allow-list.
* New: `POST /storage-modes/scan` — site-wide auto-discovery of static / dynamic / dual block classification, persisted to `wp_options.gk_block_api_storage_modes`.
* New: `POST /posts/{id}/blocks/replace` — atomic range replace of N blocks with M blocks in one revision.
* New: `top_level_counter` field on every top-level block in `get_page_blocks` response — eliminates the manual ordinal computation that caused "block landed in wrong section" errors.
* New: `storage_mode` field on every block in `get_page_blocks` response (`static` | `dynamic` | `dual`).
* New: `path` and `top_level_counter` on `insert_blocks` response so callers can chain `mutate_block_tree insert-child` without an extra `get_page_blocks` round-trip.
* New: `Block_Inventory` class (renamed from `Usage_Stats`) — broader scope (block + pattern inventory + storage-mode classification).
* New: Per-block `preference: { tier, suggested_replacement }` annotation on `get_page_blocks` for non-preferred blocks.
* New: Server-driven preference policy — namespace tier classification now reads from the (admin-editable) Preferences config; no hardcoded namespace lists in the codebase.
* New: Empty `legacy_blocks` summary key omitted on clean pages; `by_namespace` map is sparse (only present namespaces).
* New: Rich `legacy_block` rejection error includes structured data (`block`, `namespace`, `suggested_replacement`, `policy_resource`).
* New: `dual_storage_requires_both` rejection error when innerHTML-only updates target dual-storage blocks (closes BLOCK-3 data corruption class).
* New: `Domain Path: /languages` plugin header.
* Changed: `Block_CRUD` constructor now requires `Block_Inventory`. `REST_Controller` constructor now requires `Preferences`.
* Changed: Tool descriptions disambiguate flat `index` vs `top_level_counter` addressing.
* Changed: `update_block` documents shallow attribute merge semantics explicitly.
* Changed: Site-scan endpoints chunked to avoid OOM on large sites.
* Changed: Settings page form schema rewritten to indexed-row format (fixes namespace-add and replacement_map save bugs).
* Fixed: `_GET` reads on the settings page now go through `wp_unslash()` + `absint()`.
* Fixed: SSRF guard now validates IPv6 ranges in addition to IPv4.
* Fixed: `count_pattern_references` uses `$wpdb->esc_like()` and consults the inventory cache before running standalone queries.
* Security: `manage_options` capability required for storage-mode scan (was `edit_posts`).

= 1.3.0 =
* New: Yoast SEO tools integrated into the MCP server (`yoast_get_seo`, `yoast_update_seo`, `yoast_bulk_update_seo`). Note: requires the gravitykit/v1 mu-plugin on the target site.
* Changed: License separated — MIT for the MCP server, GPL-2.0+ for the WordPress plugin.

= 1.2.0 =
* New: `create_post` and `update_post` REST endpoints for the docs lifecycle.
* New: `list_terms` REST endpoint for taxonomy term lookup.
* New: `upload_media` REST endpoint with three input modes (multipart, URL sideload, base64) and a comprehensive SSRF guard against private/link-local IPv4 ranges.
* New: `gk_block_api_post_types_allowlist` option to constrain `create_post` post types.

= 1.1.0 =
* New: `find_posts` and `post_info` REST endpoints.
* New: `outline`, `summary_only`, and `include_legacy_paths` query params on `get_page_blocks`.
* New: Page summary now includes block_types counts, sections, headings, and legacy_blocks aggregate.

= 1.0.0 =
* Initial release.
* Block CRUD endpoints (GET, POST, PATCH, DELETE, PUT).
* Path-based mutation engine with 9 operations.
* Static block safety guards with auto-transform.
* Preference scoring with namespace policies and replacement map.
* Pattern management with synced/registered support.
* Site usage analytics with caching.
* Render mode for dynamic block output and shortcode expansion.
* URL resolver and block search endpoints.
* Rate limiting and revision tracking.
