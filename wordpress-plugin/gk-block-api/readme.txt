=== GK Block API ===
Contributors: gravitykit, katzwebservices
Tags: blocks, rest-api, gutenberg, mcp, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.0
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

1. Upload the `gk-block-api` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. (Optional) Visit Settings → Block MCP to review tier scores and the post-type allow-list.
4. Create an Application Password for your API user (Users → Profile → Application Passwords).
5. Authenticate REST requests using Basic Auth with the Application Password.

== Frequently Asked Questions ==

= Does this plugin modify my site's front end? =

No. GK Block API is a REST-only plugin — it adds no front-end output, scripts, or styles. It only provides API endpoints and a settings page for managing block content.

= What authentication does it require? =

WordPress Application Passwords with Basic Auth over HTTPS. The authenticated user must have `edit_post` capability for the target post (writes), `edit_posts` capability (reads), or `manage_options` (settings + storage-mode scan).

= Does it work with custom post types? =

Yes. The block API works with any post type that stores block content in `post_content`, including pages, posts, and custom post types like EDD Downloads. The `create_post` tool's allow-list (in Settings → Block MCP) controls which types can be created via the API.

= What is the MCP server? =

The MCP (Model Context Protocol) server is a separate TypeScript application that wraps the REST API as AI-friendly tools. It runs locally on the developer's machine and connects to the WordPress plugin via REST. The MCP server is not required to use the REST API directly.

= How do I configure which blocks are "legacy" on my site? =

Visit Settings → Block MCP. Set the score for a namespace to less than 10 to mark it as legacy (hard-rejected on insert). Use the replacement map to suggest alternatives.

= What happens to data if I uninstall the plugin? =

`uninstall.php` deletes all plugin options and transients (`gk_block_api_preferences`, `gk_block_api_post_types_allowlist`, `gk_block_api_storage_modes`, the manual dual-storage list, the inventory cache, and per-post rate-limit transients). Post content and revisions are not touched.

== Upgrade Notice ==

= 1.6.0 =
Master kill-switch for media uploads (Settings → Block MCP → "Allow MCP agents to upload media") with REST 403 enforcement before any disk I/O, DNS lookup, or HTTP fetch. New `gk_block_api_allow_taxonomy_in_terms` filter lets admins opt internal-state taxonomies (workflow status, etc.) back into `/terms` without flipping `show_in_rest`. Visibility leaks fixed on `/post-info`, `/find-posts`, `/resolve`, `/terms`, `/patterns/{id}`, and synced-pattern (`core/block`) expansion — drafts, password-protected posts, and non-REST taxonomies now stay invisible to callers without the appropriate cap. `insert_pattern` mints fresh `gk_ref` values on inlined blocks and returns the visible (flat) index. `update-attrs` deep-merges metadata so partial writes preserve `gk_ref` and `bindings`. Post-types allowlist UI re-laid as a 3-column grid. Plugin works on PHP 7.4 through 8.4.

= 1.5.1 =
Write responses now echo a canonical `saved` snapshot (inner_html + attributes) so agents can verify edits without a follow-up read. New `get_block` endpoint mirrors the same shape for single-block re-reads. New `verbose` request param on batch-update (default false). All changes are additive — existing consumers continue to work unchanged.

= 1.5.0 =
Stable block refs (`gk_ref`) — every block gets a persistent ID that survives sibling inserts/removals, so chained mutations don't go stale. Atomic batch updates (`POST /posts/{id}/blocks/batch-update`) for N independent edits in ONE revision. ETag / If-Match optimistic concurrency for safe parallel writes. Cursor pagination on `get_page_blocks` for large pages. Yoast SEO bridge rolled into the plugin. WordPress admin settings page for editing tier scores, replacement map, dual-storage list, and post-type allow-list. Translations for 20 WordPress languages.

= 1.4.2 =
Critical save-path fix: block content now goes through `wp_slash()` before reaching `wp_update_post` / `wp_insert_post`, so backslashes inside block-comment JSON (Code Block Pro escape sequences, CSS variable references, etc.) survive the round-trip instead of being stripped by core's automatic `wp_unslash()`. New `gk_block_api_format_block` filter for enriching block responses (used by the Code Block Pro integration).

= 1.4.1 =
Security and hardening pass: SSRF guard extended to IPv6, multisite uninstall sweep, registry-first storage scan with OOM/DoS hardening and query safety. Lifecycle hooks reorganized, global filter wiring cleaned up, log gating refined, all user-facing strings wrapped in `__()` for translation.

= 1.4.0 =
Settings UI added. Auto-discovery of dual-storage blocks. Atomic range-replace endpoint. Dual-storage write protection (refuses innerHTML-only updates on dual-storage blocks to prevent the corruption class fixed by BLOCK-3). Tool/method renames may break custom code that instantiated Block_CRUD or REST_Controller directly — see changelog.

= 1.3.0 =
Yoast SEO tool integration. License (MIT for MCP / GPL-2.0+ for plugin) added.

= 1.2.0 =
Docs lifecycle tools (`create_post`, `update_post`, `list_terms`, `upload_media` with SSRF guard).

== Changelog ==

= 1.6.0 =
* New: Master kill-switch for media uploads. Settings → Block MCP → "Allow MCP agents to upload media" toggles `gk_block_api_uploads_enabled`. When disabled, every upload path (multipart, URL sideload, base64) returns HTTP 403 `uploads_disabled` before any disk I/O, DNS lookup, or HTTP fetch. Also overridable via the `gk_block_api_uploads_enabled` filter.
* New: `gk_block_api_allow_taxonomy_in_terms` filter — opt deliberately-private taxonomies (workflow status, internal department) back into the `/terms` endpoint for the agent-editing use case, without flipping `show_in_rest` for the rest of WordPress's REST surface.
* New: `gk_block_api_url_sideload_blocked_ranges` filter — admin-extensible IPv4 block list for the SSRF guard. The default already covers RFC1918, link-local (cloud metadata), loopback, IETF reserved, benchmark, multicast, and "this network" ranges; the filter is for sites that want to add additional ranges.
* New: `gk_block_api_post_types_allowlist` option exposed in the settings UI as a 3-column checkbox grid ("Post types AI agents can create"). Default (no checkboxes) allows any post type with `show_in_rest: true`; checking specific types restricts `create_post` to that set.
* New: `gk_block_api_legacy_patterns_scan_limit` filter (default 500) caps the synced-pattern scan that drives `/site-usage` legacy-pattern detection.
* New: `gk_block_api_synced_patterns_query_limit` filter (default 500) caps the synced-pattern query backing `/patterns`.
* Fixed: Visibility leak on `GET /post-info` — direct id and slug lookups returned title / status / author / parent / timestamps for any post the caller could not actually read. Now routed through `Block_CRUD::is_post_readable()` and falls back to 404 to avoid signalling the post's existence.
* Fixed: Visibility leak on `GET /find-posts` — the underlying `WP_Query` ran without `perm` set, so SQL returned every matching post regardless of user cap. Now passes `perm: 'readable'` plus a per-result `is_post_readable()` check that also catches publish-with-password posts. Response `total` / `total_pages` are derived from the visible result set, so password-protected counts don't leak through pagination metadata either.
* Fixed: Visibility leak on `GET /resolve` — `url_to_postid()` resolves drafts when the caller is logged in, so the endpoint handed back metadata for any URL that resolved including drafts of other users. Gated by `is_post_readable()`.
* Fixed: Visibility leak on `GET /terms` — taxonomies with `show_in_rest: false` were enumerable by any `edit_posts` caller. Now gated to taxonomies that opt into REST exposure (with the new override filter above for the agent-editing case).
* Fixed: Visibility leak on `GET /patterns/{id}` — single-pattern lookup did not check `is_post_readable()`, so drafts / private / password-protected `wp_block` entries could be fetched by id.
* Fixed: Visibility leak in render-mode block tree — `core/block` (synced pattern) expansion handed back title and full block tree for the referenced `wp_block` regardless of the caller's cap on it. Now gated.
* Fixed: `insert_pattern` inline mode preserved the source pattern's `metadata.gk_ref` values, so re-inserting or chaining mutations targeted the wrong blocks. Now mints fresh refs across the inlined tree via `assign_fresh_refs_recursive()`.
* Fixed: `insert_pattern` returned the raw-array `$insert_at` index in the response, which counts whitespace blocks and is off-by-N from the flat-index vocabulary the rest of the write surface uses. Now returns the visible index.
* Fixed: `Block_Mutator` `update-attrs` op merged attrs via a single `array_merge`, so a partial `{ metadata: { name: 'Hero' } }` write clobbered sibling keys including `gk_ref` (ref stability) and `bindings` (write-guard inputs). Now deep-merges `metadata` to match `Block_Writer::apply_block_update_in_place()`.
* Fixed: `HTML_Transformer` `tagName` swap previously shared one allowlist for `core/group` and `core/separator` that included `hr`. The combined path could emit `<hr>…</hr>` (invalid HTML for a void element) or rewrite `<hr>` to `<div></div>` (silently destructive). Split into separate code paths — container tags for `core/group`, void tags for `core/separator` — and normalised separator output to the self-closing `<hr ... />` form.
* Fixed: `Block_Inventory::get_stats(refresh=true)` updated the throttle stamp before calling `build_stats()`, so a failed rebuild still burned the refresh budget. The stamp is now recorded only after a successful build + cache write.
* Fixed: `Block_Reader` parse-error responses leaked the raw exception message (class names, file paths, type errors) to unauthenticated REST callers. Production responses now carry a generic message; the full trace is written to `error_log`; `WP_DEBUG` re-enables the message + attachment for local debugging.
* Fixed: `Block_Reader::get_blocks()` cache key is now cast to `int` to match `parse()` / `invalidate()` so non-canonical numeric `$post_id` inputs produce keys that invalidation finds.
* Fixed: `Block_Reader::format_blocks_recursive` is depth-guarded by `MAX_BLOCK_DEPTH` so a pathological deeply-nested document can't blow the stack during formatting.
* Fixed: `Post_Manager::create_post` and `update_post` stored the GMT timestamp in both `post_date` and `post_date_gmt`. WordPress reads `post_date` directly for admin sort and date queries, so the column has to hold site-local time — now converted via `get_date_from_gmt()`.
* Fixed: `Post_Manager` `comment_status` / `ping_status` writes now reject any value other than `open` / `closed` instead of silently storing whatever the client sent.
* Fixed: `Post_Manager::create_post` validates `date` with `strtotime` before passing to `wp_insert_post`; garbage values previously rendered posts unsortable in admin lists.
* Fixed: `Pattern_Manager::get_patterns` search input is sanitised with `sanitize_text_field` before the `strpos` comparison.
* Fixed: `Yoast_Bridge::write_fields` `is_cornerstone` disable path stored the literal string `"false"`, which PHP treats as truthy — so toggling cornerstone off via the API silently left it enabled in Yoast's view. Disable now deletes the meta key, matching Yoast's own convention.
* Fixed: `Yoast_Bridge::bulk_update_seo` rejects batches over `Block_CRUD::MAX_BATCH_SIZE` (50) with HTTP 400 — closes a cheap resource-amplification path where an authenticated `edit_posts` user without per-post permission could send an unbounded `posts` array.
* Fixed: `Media_Manager::apply_metadata` wraps `$updates` in `wp_slash()` before `wp_update_post`, so apostrophes / backslashes in title / caption / description survive WordPress's automatic unslash on string fields.
* Fixed: `Block_Writer::revert_to_revision` now calls `check_rate_limit` / `record_rate_limit` so it counts against the per-post write budget — closes the bypass where an attacker could route every mutation through revert.
* Fixed: `Block_Writer::save_post_content` wraps the `content_save_pre` filter removal in a `try/finally` so a thrown save leaves the filter graph clean.
* Fixed: `Block_Writer::insert_pattern` honours `Block_CRUD::is_post_readable()` for synced-pattern references so an `edit_posts` user can't insert a `wp_block` they shouldn't be able to read.
* Fixed: `validate_block_def()` rejects empty block names with `block_name_required` instead of silently writing an empty block.
* Fixed: `Block_Mutator::wrap-in-group` rejects unknown wrapper tag names with `disallowed_tag` instead of silently falling back to `<div>` (closes a markup-smuggling vector when an attacker controlled the `wrapper` payload).
* Fixed: `Block_Mutator::duplicate` round-trips the cloned block through JSON encode/decode so deep clones can never share references with the source.
* Fixed: `HTML_Transformer` boolean-attribute path uses `filter_var(FILTER_VALIDATE_BOOLEAN)` so string truthiness (`"true"`, `"on"`) is handled consistently with the editor's serialisation.
* Fixed: `HTML_Transformer` text-content transforms use `preg_replace_callback` so `$N` sequences inside user-controlled replacement strings can't inject backreferences.
* Fixed: `Yoast_Faq_Enricher` excerpt uses `mb_substr` / `mb_strlen` so multibyte UTF-8 truncation respects codepoint boundaries.
* Fixed: `Core_Block_Enricher` (synced-pattern expansion) tracks a per-request visited set so a `wp_block` that references itself (or any ancestor in the chain) yields a `cycle_detected` flag instead of blowing the stack.
* Fixed: MCP server `get_post_info` validation tightened to `Number.isInteger(post_id) && > 0`. Floats are now rejected client-side with the same "post_id must be a positive integer" error the schema documents.
* Fixed: MCP server `assertHasKeys` test helper rejects `null` explicitly before the `typeof === 'object'` check (since `typeof null === 'object'`).
* Improved: Post-types allowlist UI laid out as a 3-column CSS grid (auto-fill min 240px) instead of a single inline-wrapped row.
* Improved: Settings page exposes the kill-switch alongside the existing tier/replacement/post-type controls.
* Improved: `Pattern_Manager::get_pattern` rejects non-published `wp_block` posts the caller cannot read.
* Tests: PHPUnit suite migrated off custom mocks onto the real WordPress test harness (`wp-phpunit` + `roots/wordpress` + `sqlite-database-integration`). 606 tests / 7412 assertions on a PHP 7.4 / 8.2 / 8.3 / 8.4 matrix.
* Tests: New `tests/Stress/` (rate-limit burst, ref collisions, pattern recursion, wide trees, unicode pathologies) and `tests/Security/` (XSS bypass, SSRF, uploads-disabled kill-switch) suites.
* Tests: Regression test added for every bug fix above — `tests/Block/BlockCrudTest.php` covers the visibility gate, ref-mint, and visible-index contracts; `tests/Block/BlockMutatorTest.php` covers the metadata deep-merge; `tests/Block/HtmlTransformerTest.php` covers the separator/group split; `tests/REST/PostVisibilityTest.php` covers the `/post-info` / `/find-posts` / `/resolve` gates and the pagination-metadata fix; `tests/Post/TermManagerTest.php` covers the taxonomy gate and override filter.
* Tests: TypeScript MCP server gains 469 Vitest tests covering tool wiring, error translation, schema assertions, and integration patterns. Live-WP integration suite runs against a real site when configured.
* Tests: CI matrix now runs PHP 7.4 / 8.2 / 8.3 / 8.4 plus TypeScript Vitest. Workflow rejects on `secrets.X` in job-level `if` resolved by gating the integration job on `workflow_dispatch` or `[integration]` commit-message marker only.
* Tests: `composer lint` (PHPCS WordPress + PHPCompatibility) gated in CI with zero errors and zero warnings on `includes/`.
* Doc: AGENTS.md gains a "Comments and docblocks" section (no internal-process references in source, no scale or future-architecture speculation, present-tense behaviour description, three-question self-test) plus a tests-folder convention (docblocks not inline narrative on every test method).

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
* i18n: All remaining user-facing strings wrapped in `__()` / `_e()` with the `gk-block-api` text domain.

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
