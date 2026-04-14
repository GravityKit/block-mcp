# GK Block API — MCP Server for WordPress Block-Level Content Management

An MCP (Model Context Protocol) server that gives AI agents surgical control over WordPress block content. Instead of working with raw HTML, agents read and write individual blocks as structured JSON with preference-aware guidance.

## What It Does

- **Read** page content as a structured block tree (name, attributes, innerHTML, path)
- **Edit** individual blocks without touching the rest of the page
- **Insert** blocks at precise positions with validation against the block registry
- **Delete** blocks or ranges of consecutive blocks
- **Mutate** nested block trees with path-based operations (update attrs, replace, wrap/unwrap, move, duplicate)
- **Insert patterns** — synced (linked) or inline (independent copy)
- **Discover** available block types and patterns, scored by preference tier
- **Analyze** site-wide block usage and identify legacy patterns

Every write operation creates a WordPress revision. Legacy blocks are rejected or warned about automatically.

## Two Components

### 1. WordPress Plugin (PHP)

Located in `wordpress-plugin/gk-block-api/`. Provides a REST API (`gk-block-api/v1`) for block-level operations. Handles parsing, serialization, safety checks, preference scoring, rate limiting, and revision tracking.

**Install**: Copy the `gk-block-api/` folder to `wp-content/plugins/` and activate.

### 2. MCP Server (TypeScript)

Located in `src/`. Wraps the REST API as MCP tools consumable by AI agents (Claude Code, Hermes, etc.). Adds natural-language enrichment — legacy block warnings, tier-grouped listings, pattern recommendations.

**Build & Run**:

```bash
npm install
npm run build
npm start
```

## Requirements

- Node.js >= 20
- WordPress 6.0+ with Application Passwords enabled
- PHP 7.4+

## Configuration

Set three environment variables:

```bash
GK_SITE_URL=https://www.gravitykit.com
GK_BLOCK_API_USER=your-wp-username
GK_BLOCK_API_APP_PASSWORD=your-application-password
```

See `.env.example` for the template.

## MCP Tools

| Tool | Description |
|------|-------------|
| `list_block_types` | Browse available blocks with preference tiers |
| `list_patterns` | Search and filter patterns with scoring |
| `get_pattern` | Inspect a single pattern's block content |
| `get_site_usage` | Site-wide block and pattern analytics |
| `get_page_blocks` | Read all blocks on a page as structured JSON |
| `update_block` | Edit a single block's attributes or HTML |
| `insert_blocks` | Add blocks at a specific position |
| `delete_block` | Remove block(s) from a page |
| `replace_all_blocks` | Full page rewrite with validation |
| `mutate_block_tree` | Path-based structural operations (9 ops) |
| `insert_pattern` | Insert a pattern — synced or inline |

## Block Preference System

Blocks are scored by namespace to guide agents toward preferred choices:

- **Preferred** (score >= 80): `filter/` (theme), `core/`, `gravityforms/`, `gk-*`
- **Acceptable** (score >= 50): `outermost/`, `kevinbatdorf/`
- **Avoid** (score >= 10): `stackable/` — allowed with warnings
- **Legacy** (score < 10): `ugb/`, `jetpack/` — hard-rejected on insert

The replacement map automatically suggests `core/heading` when an agent tries to use `stackable/heading`, etc.

## License

- WordPress Plugin: GPL-2.0-or-later
- MCP Server: MIT
