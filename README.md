# Block MCP

> Surgical block-level content editing for WordPress via the Model Context Protocol.

An MCP server + WordPress plugin that lets AI agents read, edit, and restructure Gutenberg block content as nested JSON instead of raw HTML. Every edit creates a WordPress revision for rollback. Preference scoring guides agents toward the right blocks for your site.

## Why

AI agents working with WordPress typically have three options for editing content:

1. **Rewrite `post_content` as HTML** — destroys block structure, lossy, dangerous.
2. **Use the default WP REST API** — whole-post reads/writes; no path-based access to nested blocks.
3. **Use this MCP** — read/write blocks by path (`[0, 2, 1]`), surgical edits, auto-transforms, revision tracking.

## Features

**Read**
- Full block tree as structured JSON with paths, names, attributes, and a `text_preview` of each block's content
- Page summary in one call: block type counts, headings with paths, section markers, legacy block detection, max nesting depth
- Outline mode for fast page structure inspection
- Search blocks by text content or block name
- Render mode expands shortcodes, resolves synced patterns, and marks dynamic blocks

**Write**
- Update a single block's attributes or HTML (auto-transforms keep them in sync)
- Insert blocks at any position (flat index or nested path)
- Delete blocks with optional count
- Full page rewrite with validation
- Insert patterns — synced (`core/block` reference) or inline (independent copy)

**Mutate** (9 path-based operations)
- `update-attrs`, `update-html`, `replace-block`, `remove-block`
- `wrap-in-group`, `unwrap-group`, `insert-child`, `duplicate`, `move`
- `dry_run` parameter to validate without writing
- `before` (pre-move indexing) and `count` (move N consecutive blocks)

**Safety**
- Auto-transform keeps innerHTML in sync when attributes change (heading level, list ordered, group tagName, button URL, spacer height, etc.)
- Static block guards warn when attr changes may leave rendered markup stale
- Legacy blocks are hard-rejected on insert; avoid-tier blocks generate warnings with suggested replacements
- Rate limiting (10 writes/min per post, 2 full rewrites/min per post)
- Every write creates a before/after WordPress revision — use `revert_to_revision` to undo

**Discover**
- List block types filtered by namespace, category, or preference tier
- Browse patterns (synced + registered) with scoring by recency, reference count, and legacy content
- Site-wide usage analytics (cached)
- Resolve any URL to its post ID, type, and edit link

## How It Works

The project has two components:

**WordPress plugin** (`wordpress-plugin/gk-block-api/`) — PHP REST API under the `gk-block-api/v1` namespace. Handles block parsing, serialization, safety, preference scoring, rate limiting, revisions. Works with any post type that stores block content in `post_content`.

**MCP server** (`src/`) — TypeScript server that exposes the REST API as MCP tools. Runs locally on your machine via stdio transport. No part of the MCP server touches the WordPress site directly — it authenticates as a normal user.

```
AI Agent  ←stdio→  MCP server (your machine)  ←HTTPS→  WordPress plugin (your site)
```

## Installation

### WordPress plugin

1. Copy `wordpress-plugin/gk-block-api/` to your site's `wp-content/plugins/` directory.
2. Activate the plugin.
3. Create a WordPress Application Password (Users → Your Profile → Application Passwords) for the user the MCP will authenticate as. The user needs `edit_posts` capability at minimum.

### MCP server

```bash
npm install   # auto-builds dist/index.cjs via prepare script
```

Set three environment variables in your MCP client config:

```
GK_SITE_URL=https://example.com
GK_BLOCK_API_USER=<wordpress-username>
GK_BLOCK_API_APP_PASSWORD=<application-password>
```

Then register the server with your MCP client (Claude Code, etc.) pointing at `dist/index.cjs`:

```json
{
  "command": "node",
  "args": ["/path/to/block-mcp/dist/index.cjs"],
  "env": {
    "GK_SITE_URL": "https://example.com",
    "GK_BLOCK_API_USER": "...",
    "GK_BLOCK_API_APP_PASSWORD": "..."
  }
}
```

## MCP Tools

| Tool | Purpose |
|---|---|
| `get_page_blocks` | Read a post's blocks — supports `outline`, `summary_only`, `search`, `block_name`, `render`, `fields` params |
| `mutate_block_tree` | Path-based structural edits (9 operations) |
| `update_block` | Flat-index single block update |
| `insert_blocks` | Insert blocks at a position |
| `delete_block` | Remove block(s) by index |
| `replace_all_blocks` | Full page rewrite |
| `insert_pattern` | Insert a pattern, synced or inline |
| `revert_to_revision` | Roll back to a prior revision ID |
| `list_block_types` | Browse registered blocks with tiers |
| `list_patterns` | Search patterns with preference scoring |
| `get_pattern` | Inspect a pattern's block content |
| `get_site_usage` | Block/pattern usage analytics |
| `resolve_url` | Map a URL to post ID and type |
| `create_post` | Create a new post or page (draft, publish, etc.) — accepts blocks or HTML |
| `update_post` | Update post metadata, status, or terms — covers publish/trash/untrash transitions |
| `list_terms` | List taxonomy terms (categories, tags, custom taxonomies) for ID lookup |
| `upload_media` | Upload to the media library via local path, URL sideload, or base64 |

## Preference System

Block preferences are stored as a WordPress option and configurable per-site. Default tiers:

| Tier | Score | Policy |
|---|---|---|
| **preferred** | ≥ 80 | Use freely |
| **acceptable** | 50–79 | Use if preferred unavailable |
| **avoid** | 10–49 | Warn, suggest replacement |
| **legacy** | < 10 | Reject on insert, return error |

Defaults: `core/*` and theme (`filter/*`) blocks are preferred. Known-legacy namespaces (`ugb`, `jetpack`) are rejected. A replacement map suggests modern alternatives when an agent tries to insert a legacy block (e.g., `stackable/heading` → `core/heading`).

Customize via the `gk_block_api_preferences` WordPress option or extend the defaults in `class-preferences.php`.

## Example Usage

Once configured with your MCP client:

> **Agent**: "Change the H2 'Welcome' on `/about/` to 'About Us'"

1. Agent calls `resolve_url({ url: "/about/" })` → gets post ID
2. Agent calls `get_page_blocks({ post_id: 42, outline: true })` → finds heading at `path: [4]`
3. Agent calls `mutate_block_tree({ post_id: 42, op: "update-attrs", path: [4], attributes: { content: "About Us" } })` → done, revision created

The auto-transform updates both the `content` attribute and the inner `<h2>...</h2>` text so the block editor stays consistent.

### Docs Lifecycle (v1.2)

Authoring a fresh doc end-to-end with a single MCP:

> **Agent**: "Write a getting-started doc with a screenshot, publish under Documentation."

1. `list_terms({ taxonomy: "category", search: "Documentation" })` → grab the category ID.
2. `create_post({ title: "Getting Started with GravityView", status: "draft", categories: [<id>], blocks: [{ name: "core/heading", attributes: { level: 2 }, innerHTML: "<h2 class=\"wp-block-heading\">Quick Start</h2>" }] })` → captures the post ID.
3. `upload_media({ path: "/tmp/screenshot.png", alt_text: "Filtering view results", post_id: <id> })` → captures the attachment ID + URL.
4. `insert_blocks({ post_id, after: 0, blocks: [{ name: "core/image", attributes: { id: <atch>, url, alt: "Filtering view results" } }] })`.
5. `update_post({ post_id, status: "publish" })` → live.
6. (Later) `update_post({ post_id, status: "trash" })` to retire, or `revert_to_revision` to undo a bad edit.

## Testing

- **PHP**: 162 PHPUnit tests covering preferences, safety, CRUD, mutation engine, HTML transforms, summary/outline
- **TypeScript**: 99 Vitest tests covering tool validation, client calls, enrichment

Run locally:

```bash
# PHP
cd wordpress-plugin/gk-block-api && phpunit -c tests/phpunit.xml

# TypeScript
npm test
```

## Requirements

- Node.js ≥ 20
- WordPress ≥ 6.0 with Application Passwords enabled
- PHP ≥ 7.4
- HTTPS (required by WordPress for Application Password authentication)

## Limitations

- Edits work on posts stored as blocks. Block-theme templates (`wp_template`, `wp_template_part`) and widget areas are not currently supported.
- Rate limits are per-post, not per-user — multiple agents editing the same post share the budget.
- Static block innerHTML can't be regenerated server-side (WordPress has no PHP equivalent of the React `save` function). Auto-transforms cover the common cases; for anything else, supply innerHTML explicitly.

## License

- WordPress plugin: GPL-2.0-or-later
- MCP server: MIT
