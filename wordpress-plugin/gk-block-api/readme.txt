=== GK Block API ===
Contributors: gravitykit, katzwebservices
Tags: blocks, rest-api, gutenberg, mcp, ai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API for block-level CRUD operations with smart preferences for AI agents.

== Description ==

GK Block API provides a comprehensive REST API for reading, editing, and managing WordPress block content at a granular level. Designed for AI agents (via MCP — Model Context Protocol), it enables surgical block editing without rewriting entire pages.

**Key Features:**

* **Block CRUD** — Read, insert, update, delete, and replace blocks via REST endpoints.
* **Path-based mutations** — Edit nested blocks (e.g., a button inside a column inside a group) using integer array paths like `[0, 2, 1]`.
* **9 mutation operations** — `update-attrs`, `update-html`, `replace-block`, `remove-block`, `wrap-in-group`, `unwrap-group`, `insert-child`, `duplicate`, `move`.
* **Static block safety guards** — Warns when attribute changes on static blocks may leave rendered markup inconsistent.
* **Auto-transform innerHTML** — Automatically updates HTML when attributes imply structural changes (e.g., heading level, list ordered, group tagName). Uses `WP_HTML_Tag_Processor` for safe attribute manipulation.
* **Preference scoring** — Configurable namespace scores rank blocks by preference tier (preferred, acceptable, avoid, legacy). Includes a replacement map for legacy block migration.
* **Pattern management** — List, search, and insert synced or registered patterns with preference scoring and legacy block detection.
* **Site usage analytics** — Block and pattern usage statistics across all published content.
* **Render mode** — Optional `render=true` parameter includes server-rendered output for dynamic blocks, expands shortcodes, resolves synced pattern content, and marks blocks as dynamic/static.
* **URL resolver** — Map any site URL to its post ID, type, and title.
* **Block search** — Find blocks by name or text content within a page.
* **Revision tracking** — Every write operation creates WordPress revisions with before/after IDs for easy rollback.
* **Rate limiting** — Configurable per-post write limits to prevent runaway automated edits.

**REST Endpoints (under `gk-block-api/v1`):**

* `GET /block-types` — Registered block types with preference scores.
* `GET /patterns` — Synced and registered patterns with scoring.
* `GET /patterns/{id}` — Single pattern with parsed block content.
* `GET /patterns/search` — Search patterns by keyword.
* `GET /site-usage` — Block and pattern usage statistics.
* `GET /resolve` — Resolve a URL to a post ID.
* `GET /posts/{id}/blocks` — Page blocks as structured JSON with paths.
* `POST /posts/{id}/blocks` — Insert blocks at a position.
* `PATCH /posts/{id}/blocks/{index}` — Update a single block.
* `DELETE /posts/{id}/blocks/{index}` — Remove a block.
* `PUT /posts/{id}/blocks` — Full page rewrite.
* `POST /posts/{id}/mutate` — Path-based mutation (9 operations).
* `POST /posts/{id}/insert-pattern` — Insert a pattern (synced or inline).

== Installation ==

1. Upload the `gk-block-api` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Create an Application Password for your API user (WordPress Admin → Users → Profile → Application Passwords).
4. Authenticate REST requests using Basic Auth with the Application Password.

== Frequently Asked Questions ==

= Does this plugin modify my site's front end? =

No. GK Block API is a REST-only plugin — it adds no front-end output, scripts, or styles. It only provides API endpoints for managing block content.

= What authentication does it require? =

WordPress Application Passwords with Basic Auth over HTTPS. The authenticated user must have `edit_post` capability for the target post.

= Does it work with custom post types? =

Yes. The block API works with any post type that stores block content in `post_content`, including pages, posts, and custom post types like EDD Downloads.

= What is the MCP server? =

The MCP (Model Context Protocol) server is a separate TypeScript application that wraps the REST API as AI-friendly tools. It runs locally on the developer's machine and connects to the WordPress plugin via REST. The MCP server is not required to use the REST API directly.

== Changelog ==

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
