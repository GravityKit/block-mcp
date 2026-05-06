=== GK Block API ===
Contributors: gravitykit, katzwebservices
Tags: blocks, rest-api, gutenberg, mcp, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.0
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

= 1.4.0 =
Settings UI added. Auto-discovery of dual-storage blocks. Atomic range-replace endpoint. Dual-storage write protection (refuses innerHTML-only updates on dual-storage blocks to prevent the corruption class fixed by BLOCK-3). Tool/method renames may break custom code that instantiated Block_CRUD or REST_Controller directly — see changelog.

= 1.3.0 =
Yoast SEO tool integration. License (MIT for MCP / GPL-2.0+ for plugin) added.

= 1.2.0 =
Docs lifecycle tools (`create_post`, `update_post`, `list_terms`, `upload_media` with SSRF guard).

== Changelog ==

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
