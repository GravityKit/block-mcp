# GravityKit Block MCP — Specification

> AI-powered block-level content management for gravitykit.com via Model Context Protocol.

## Problem

Today, AI agents can read WordPress page content as raw HTML (via `wordpress-mcp`) or as structured JSON (via VIP Block Data API). But they **cannot**:

1. **Surgically edit** a single block without rewriting the entire `post_content`
2. **Know what blocks exist** on the site to make informed choices
3. **Browse and insert patterns** from the site's pattern library
4. **Respect block preferences** (e.g., prefer `filter/` theme blocks over `stackable/`)

This spec defines a two-part system: a **WordPress REST plugin** (`gk-block-api`) and an **MCP server** (`block-mcp`) that together give AI agents full block-level CRUD with smart preferences.

---

## Architecture

```
AI Agent (Claude Code, etc.)
    │
    ▼
┌──────────────┐
│  block-mcp   │  MCP Server (Node.js/TypeScript)
│  (MCP tools) │  Translates AI tool calls → REST API calls
└──────┬───────┘
       │ HTTPS + Application Password auth
       ▼
┌──────────────────────┐
│  gk-block-api        │  WordPress Plugin (PHP)
│  REST endpoints      │  parse_blocks() / serialize_blocks()
│  + VIP Block Data    │  Block registry, patterns, preferences
│    API (read layer)  │
└──────────────────────┘
       │
       ▼
   gravitykit.com (WordPress / Convesio)
```

### Dependencies

- **VIP Block Data API** v1.4.7 (already installed) — provides the read layer for page blocks
- **WordPress REST API** — authentication via Application Passwords
- **gk-block-api** — new WordPress plugin providing write endpoints + registry + preferences
- **block-mcp** — new MCP server exposing tools to AI agents

---

## Component 1: WordPress Plugin (`gk-block-api`)

### REST Endpoints

All endpoints are under the namespace `gk-block-api/v1`. Authentication required (Application Password or cookie auth for logged-in users with `edit_posts` capability).

#### Registry & Discovery

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/block-types` | All registered block types with attributes, categories, and preference scores |
| `GET` | `/block-types/{namespace}` | Block types filtered by namespace (e.g., `filter`, `core`) |
| `GET` | `/patterns` | All patterns (synced + registered) with preference scores |
| `GET` | `/patterns/{id}` | Single pattern with its parsed block content |
| `GET` | `/patterns/search?q={term}` | Search patterns by name/keyword |
| `GET` | `/site-usage` | Block and pattern usage statistics across the site |

#### Page Block CRUD

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/posts/{id}/blocks` | Proxy to VIP Block Data API (or fallback to `parse_blocks()`) |
| `PATCH` | `/posts/{id}/blocks/{index}` | Update a single block's attributes and/or innerHTML |
| `POST` | `/posts/{id}/blocks` | Insert block(s) at a position |
| `DELETE` | `/posts/{id}/blocks/{index}` | Remove a block at a position |
| `PUT` | `/posts/{id}/blocks` | Replace all blocks (full page rewrite) |

#### Pattern Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/posts/{id}/insert-pattern` | Insert a pattern (synced or inline) at a position |

---

### Endpoint Details

#### `GET /block-types`

Returns all registered block types with preference metadata.

**Query Parameters:**
- `namespace` — filter by namespace (e.g., `filter`, `core`)
- `category` — filter by block category
- `preferred` — if `true`, only return blocks with preference score >= 50

**Response:**
```json
{
  "block_types": [
    {
      "name": "filter/testimonial-wall",
      "title": "Testimonial Wall",
      "category": "filter",
      "description": "Display a wall of testimonials",
      "attributes": {
        "columns": { "type": "number", "default": 3 }
      },
      "preference": {
        "score": 100,
        "tier": "preferred",
        "namespace_policy": "always_prefer"
      },
      "usage": {
        "count": 41,
        "last_used": "2026-03-15"
      },
      "replaces": ["stackable/testimonial"]
    },
    {
      "name": "stackable/testimonial",
      "title": "Testimonial",
      "category": "stackable",
      "preference": {
        "score": 10,
        "tier": "avoid",
        "namespace_policy": "migrate_away",
        "replacement": "filter/testimonial-wall"
      },
      "usage": {
        "count": 62,
        "last_used": "2024-05-15"
      }
    }
  ]
}
```

#### `GET /patterns`

Returns all patterns with preference scoring.

**Query Parameters:**
- `q` — search by name
- `synced` — `true` for synced only, `false` for registered only, omit for all
- `min_score` — minimum preference score (filters out legacy)
- `category` — filter by pattern category
- `limit` — max results (default 20)
- `order_by` — `score` (default), `usage`, `date`, `name`

**Response:**
```json
{
  "patterns": [
    {
      "id": 879954,
      "name": "Testimonial Wall",
      "type": "synced",
      "created": "2025-11-20",
      "modified": "2026-01-15",
      "reference_count": 0,
      "preference": {
        "score": 95,
        "tier": "recommended",
        "reasons": ["no_legacy_blocks", "recent", "filter_theme_blocks"]
      },
      "contains_blocks": ["filter/testimonial-wall", "core/paragraph"],
      "has_legacy_blocks": false,
      "preview_html": "<div class=\"testimonial-wall\">..."
    },
    {
      "id": 827219,
      "name": "GravityView extensions",
      "type": "synced",
      "created": "2024-05-15",
      "modified": "2024-05-15",
      "reference_count": 0,
      "preference": {
        "score": -80,
        "tier": "legacy",
        "reasons": ["contains_stackable_blocks", "zero_references", "old"]
      },
      "contains_blocks": ["stackable/heading", "stackable/text", "stackable/testimonial"],
      "has_legacy_blocks": true,
      "legacy_blocks": ["stackable/spacer", "stackable/button-group", "stackable/button", "stackable/testimonial", "stackable/image", "stackable/heading", "stackable/text"]
    }
  ]
}
```

#### `PATCH /posts/{id}/blocks/{index}`

Update a single block by its index in the flat block array.

**Request Body:**
```json
{
  "attributes": {
    "content": "Updated testimonial text"
  },
  "innerHTML": "<p>Updated testimonial text</p>"
}
```

- `attributes` — partial update; merges with existing attributes
- `innerHTML` — replaces the block's HTML content
- Either or both can be provided

**Process:**
1. `parse_blocks(post_content)` → flat array
2. Validate index exists
3. Merge attributes / replace innerHTML
4. `serialize_blocks()` → new post_content
5. `wp_update_post()` with revision created
6. Return the updated block

**Response:**
```json
{
  "success": true,
  "block": {
    "index": 2,
    "name": "core/paragraph",
    "attributes": { "content": "Updated testimonial text" }
  },
  "revision_id": 883456
}
```

#### `POST /posts/{id}/blocks`

Insert one or more blocks at a specific position.

**Request Body:**
```json
{
  "after": 4,
  "blocks": [
    {
      "name": "core/heading",
      "attributes": { "level": 2, "content": "New Section" }
    },
    {
      "name": "core/paragraph",
      "attributes": { "content": "Section description text." }
    }
  ]
}
```

- `after` — insert after this index (0-based). Use `-1` or omit to append at end. Use `"start"` to prepend.
- `before` — alternative: insert before this index
- `blocks` — array of blocks to insert

**Process:**
1. `parse_blocks(post_content)`
2. Validate each block's `name` exists in the registry
3. **Check preference scores** — if any block has `tier: "avoid"` or `tier: "legacy"`, return a warning (not an error) with suggested replacements
4. Build block markup for each block using `serialize_block()`
5. Splice into the array at the specified position
6. `serialize_blocks()` → `wp_update_post()`
7. Return inserted blocks with their new indices

**Response:**
```json
{
  "success": true,
  "inserted": [
    { "index": 5, "name": "core/heading" },
    { "index": 6, "name": "core/paragraph" }
  ],
  "warnings": [],
  "revision_id": 883457
}
```

**Warning example (if stackable block used):**
```json
{
  "success": true,
  "inserted": [...],
  "warnings": [
    {
      "block": "stackable/heading",
      "message": "stackable/ blocks are deprecated on this site. Prefer filter/ or core/ blocks.",
      "suggested_replacement": "core/heading"
    }
  ]
}
```

#### `DELETE /posts/{id}/blocks/{index}`

Remove a block at a specific index.

**Query Parameters:**
- `count` — number of consecutive blocks to remove (default 1)

**Process:**
1. `parse_blocks(post_content)`
2. Validate index
3. If the block is a `core/block` (synced pattern ref), warn that this will remove the pattern from the page (not delete the pattern itself)
4. Splice out the block(s)
5. `serialize_blocks()` → `wp_update_post()`

#### `PUT /posts/{id}/blocks`

Full page rewrite. Replaces all blocks.

**Request Body:**
```json
{
  "blocks": [
    { "name": "core/heading", "attributes": { "level": 1, "content": "Page Title" } },
    { "name": "core/paragraph", "attributes": { "content": "Intro text." } }
  ]
}
```

**Guardrails:**
- Creates a revision before overwriting
- Validates all block names against registry
- Warns on any legacy/avoid-tier blocks
- Returns the full new block list

#### `POST /posts/{id}/insert-pattern`

Insert a pattern at a position on the page.

**Request Body:**
```json
{
  "pattern_id": 879954,
  "after": 4,
  "synced": true
}
```

- `pattern_id` — ID of the synced pattern (wp_block post) or name of a registered pattern
- `after` / `before` — insertion position
- `synced` — if `true` (default), insert as `core/block` ref (stays linked). If `false`, inline the pattern's blocks directly (independent copy that can be edited per-page).

**Response (synced):**
```json
{
  "success": true,
  "inserted": {
    "index": 5,
    "name": "core/block",
    "attributes": { "ref": 879954 },
    "pattern_name": "Testimonial Wall",
    "synced": true
  },
  "revision_id": 883458
}
```

**Response (inline):**
```json
{
  "success": true,
  "inserted": [
    { "index": 5, "name": "filter/testimonial-wall" },
    { "index": 6, "name": "core/paragraph" }
  ],
  "pattern_name": "Testimonial Wall",
  "synced": false,
  "revision_id": 883459
}
```

#### `GET /site-usage`

Returns block and pattern usage statistics for preference calibration.

**Response:**
```json
{
  "block_usage": {
    "core/paragraph": { "count": 16784, "post_count": 450 },
    "core/heading": { "count": 5493, "post_count": 420 },
    "filter/custom-background": { "count": 235, "post_count": 80 },
    "stackable/heading": { "count": 122, "post_count": 45 }
  },
  "namespace_totals": {
    "core": 38457,
    "stackable": 754,
    "filter": 611,
    "ugb": 66
  },
  "pattern_references": {
    "879340": { "name": "Trusted By Logos", "refs": 45 },
    "880107": { "name": "30-day guarantee", "refs": 33 }
  },
  "legacy_patterns": [
    { "id": 827219, "name": "GravityView extensions", "refs": 0, "legacy_blocks": ["stackable/testimonial", "stackable/heading"] }
  ]
}
```

---

### Block Preference Scoring

The plugin maintains a preference configuration (stored as a WordPress option, editable via REST):

```php
// Default preference configuration
$preferences = [
    'namespace_scores' => [
        'filter'             => 100,  // Theme blocks — always preferred
        'core'               => 90,   // WordPress native
        'gravityforms'       => 80,   // GravityKit ecosystem
        'gk-gravitycharts'   => 80,
        'gk-gravitycalendar' => 80,
        'gravityboard'       => 80,
        'outermost'          => 60,   // Third-party, acceptable
        'kevinbatdorf'       => 50,   // Code block pro
        'stackable'          => 10,   // Migrate away
        'ugb'                => 0,    // Legacy — never use
        'jetpack'            => 0,    // Never use
    ],
    'pattern_scoring' => [
        'recency_bonus' => [
            2026 => 50,
            2025 => 30,
            2024 => 10,
            // older => 0
        ],
        'reference_multiplier'   => 5,   // score += refs * 5
        'no_legacy_bonus'        => 20,  // +20 if no legacy blocks
        'has_legacy_penalty'     => -100, // -100 if contains stackable/ugb/jetpack
    ],
    'replacement_map' => [
        'stackable/heading'      => 'core/heading',
        'stackable/text'         => 'core/paragraph',
        'stackable/button'       => 'core/button',
        'stackable/button-group' => 'core/buttons',
        'stackable/columns'      => 'core/columns',
        'stackable/column'       => 'core/column',
        'stackable/image'        => 'core/image',
        'stackable/spacer'       => 'core/spacer',
        'stackable/divider'      => 'core/separator',
        'stackable/testimonial'  => 'filter/testimonial-wall',
        'stackable/accordion'    => 'filter/accordion',
        'stackable/icon'         => 'outermost/icon-block',
        'stackable/icon-label'   => 'outermost/icon-block',
        'stackable/card'         => 'core/group',
        'stackable/subtitle'     => 'core/paragraph',
        'ugb/columns'            => 'core/columns',
        'ugb/column'             => 'core/column',
        'ugb/button'             => 'core/button',
        'ugb/text'               => 'core/paragraph',
        'ugb/pricing-box'        => 'core/group',
    ],
];
```

#### Score Calculation

**Block type score:**
```
score = namespace_scores[namespace] ?? 30
```

**Pattern score:**
```
score = (reference_count * reference_multiplier)
      + recency_bonus[year_created]
      + (has_legacy_blocks ? has_legacy_penalty : no_legacy_bonus)
```

**Score tiers:**
| Score | Tier | AI Behavior |
|-------|------|-------------|
| >= 80 | `preferred` | Use freely |
| 50-79 | `acceptable` | Use if no preferred alternative |
| 10-49 | `avoid` | Warn and suggest replacement |
| < 10 | `legacy` | Block usage; return error with replacement |

---

### Data Storage

#### Usage Cache

Block and pattern usage stats are cached in a transient (`gk_block_usage_stats`) with a 1-hour TTL. Regenerated on demand via `GET /site-usage?refresh=true`.

---

## Component 2: MCP Server (`block-mcp`)

TypeScript MCP server that wraps the WordPress REST endpoints as AI-friendly tools.

### Configuration

```json
{
  "wordpress_url": "${GK_SITE_URL}",
  "auth": {
    "username": "${GK_BLOCK_API_USER}",
    "application_password": "${GK_BLOCK_API_APP_PASSWORD}"
  }
}
```

Environment variables stored in `~/.monokit/.env`.

### MCP Tools

#### Discovery Tools

| Tool | Description | Maps To |
|------|-------------|---------|
| `list_block_types` | List available block types with preference scores. Params: `namespace?`, `category?`, `preferred_only?` | `GET /block-types` |
| `list_patterns` | Browse patterns with preference scoring. Params: `search?`, `synced?`, `min_score?`, `limit?` | `GET /patterns` |
| `get_pattern` | Get a single pattern's full block content. Params: `pattern_id` | `GET /patterns/{id}` |
| `get_site_usage` | Get block/pattern usage stats for the site. | `GET /site-usage` |

#### Read Tools

| Tool | Description | Maps To |
|------|-------------|---------|
| `get_page_blocks` | Get all blocks on a page as structured JSON. Params: `post_id`, `include?`, `exclude?` | `GET /posts/{id}/blocks` (VIP Block Data API) |

#### Write Tools

| Tool | Description | Maps To |
|------|-------------|---------|
| `update_block` | Update a single block's attributes or content. Params: `post_id`, `block_index`, `attributes?`, `innerHTML?` | `PATCH /posts/{id}/blocks/{index}` |
| `insert_blocks` | Insert blocks at a position. Params: `post_id`, `after`/`before`, `blocks[]` | `POST /posts/{id}/blocks` |
| `delete_block` | Remove a block. Params: `post_id`, `block_index`, `count?` | `DELETE /posts/{id}/blocks/{index}` |
| `replace_all_blocks` | Full page rewrite. Params: `post_id`, `blocks[]` | `PUT /posts/{id}/blocks` |

#### Pattern Tools

| Tool | Description | Maps To |
|------|-------------|---------|
| `insert_pattern` | Insert a pattern at a position (synced by default, or inline for per-page customization). Params: `post_id`, `pattern_id`, `after`/`before`, `synced?` | `POST /posts/{id}/insert-pattern` |

### Tool Response Enrichment

The MCP server adds context to responses that the raw REST API doesn't provide:

1. **Preference warnings** — When `get_page_blocks` returns blocks, the MCP annotates any legacy/avoid-tier blocks:
   ```
   Block 12: stackable/testimonial (AVOID — use filter/testimonial-wall instead)
   ```

2. **Pattern suggestions** — When `list_patterns` is called, results are pre-sorted by preference score and the response includes a natural language summary:
   ```
   Recommended: "Testimonial Wall" (score: 95, uses filter/ blocks, created 2025)
   Avoid: "GravityView extensions" (score: -80, LEGACY, contains stackable/ blocks)
   ```

3. **Block type guidance** — When `list_block_types` is called, the response groups blocks by tier:
   ```
   PREFERRED (filter/): testimonial-wall, accordion, carousel, tabs, ...
   STANDARD (core/): paragraph, heading, image, button, group, columns, ...
   AVOID (stackable/): heading → use core/heading, button → use core/button, ...
   ```

### System Prompt Context

The MCP server provides a resource that agents can read for context:

```
# GravityKit Block Preferences

When editing pages on gravitykit.com:

1. ALWAYS prefer `filter/` (theme) blocks over alternatives
2. Use `core/` blocks for standard content (headings, paragraphs, images, buttons)
3. NEVER use `stackable/`, `ugb/`, or `jetpack/` blocks — they are legacy
4. When inserting content, check patterns first — reuse existing patterns before building from scratch
5. Prefer synced patterns to keep content consistent across pages
6. When inserting a pattern that needs per-page customization, use `synced: false` to inline it
7. When you encounter legacy blocks on a page, note them but do not replace unless asked
```

---

## Safety & Guardrails

### Revision Trail

Every write operation creates a WordPress revision. The response includes `revision_id` so changes can be traced and reverted via standard WordPress revision UI.

### Block Validation

Before any write operation, the plugin validates:
1. All block names exist in the registry
2. Required attributes are present
3. Preference tier is checked — `legacy` tier blocks are rejected with an error, `avoid` tier blocks trigger a warning

### Rate Limiting

- Write operations: max 10 per minute per post
- Full page rewrites (`PUT`): max 2 per minute per post
- Pattern insertions: no special limit

### Concurrency

The plugin uses `wp_update_post()` which respects WordPress's built-in post locking. If two agents try to edit the same post simultaneously, the second write will create a conflict revision.

---

## Current Site Data (as of 2026-04-06)

### Block Type Registry: 312 types

| Namespace | Count | Policy |
|-----------|-------|--------|
| `core` | ~150 | Standard — use freely |
| `filter` | 35 | **Preferred** — always use when available |
| `stackable` | 49 | **Avoid** — migrate to filter/core |
| `ugb` | 28 | **Legacy** — never use |
| `jetpack` | 45 | **Avoid** — rarely needed |
| `edd` | 14 | Acceptable — EDD-specific |
| `gravityforms` | 1 | Acceptable — form embeds |
| `gk-*` | 4 | Acceptable — GravityKit ecosystem |
| Other | ~30 | Case-by-case |

### Available `filter/` Theme Blocks (35)

```
filter/accordion              filter/accordion-item
filter/advanced-posts-list    filter/author-information
filter/carousel               filter/carousel-item
filter/copy-page-button       filter/countdown-timer
filter/custom-background      filter/faq-item
filter/faq-parent             filter/faqs-list
filter/floating-search        filter/modal
filter/nav-menu               filter/posts-carousel
filter/posts-list             filter/product-overview
filter/product-reviews-list   filter/purchase-product-options
filter/query-extract          filter/query-title-tags
filter/related-posts-carousel filter/site-search
filter/site-search-results    filter/sitemap
filter/social-footer-menu     filter/statistic
filter/tab-content            filter/tab-content-item
filter/tab-navigation         filter/tab-navigation-item
filter/table-of-contents      filter/tabs
filter/testimonial-wall
```

### Synced Patterns: ~100 total

**Active (2025-2026, no legacy blocks):** ~60 patterns
- Testimonial Wall, Testimonial 1/2, Case Study Carousel, Product Grid, Newsletter Signup, 30-day guarantee, Trusted By Logos, CTAs, etc.

**Legacy (contains stackable/ugb blocks):** 11 patterns
- GravityView extensions, You'll love our other add-ons too, Built with GravityView, Webinars, BFCM-CTA, Call to Action (old), Get free template, Sale button, button-red, Untitled Reusable Block, Pillar Card

**Dead (0 references, legacy blocks):** 9 of the 11 legacy patterns have zero references and can be trashed.

### Block Usage Across Published Content

Top 15:
| Block | Count | Policy |
|-------|-------|--------|
| `core/paragraph` | 16,784 | Standard |
| `core/list-item` | 5,656 | Standard |
| `core/heading` | 5,493 | Standard |
| `core/image` | 4,427 | Standard |
| `core/group` | 1,808 | Standard |
| `core/list` | 1,577 | Standard |
| `core/column` | 629 | Standard |
| `core/block` (synced pattern refs) | 396 | Standard |
| `core/columns` | 271 | Standard |
| `filter/custom-background` | 235 | **Preferred** |
| `core/button` | 228 | Standard |
| `stackable/heading` | 122 | **Migrate → core/heading** |
| `stackable/column` | 117 | **Migrate → core/column** |
| `core/spacer` | 115 | Standard |
| `filter/purchase-product-options` | 71 | **Preferred** |

---

## Implementation Plan

### Phase 1: WordPress Plugin — Read Layer
- Block type registry endpoint with preference scores
- Pattern listing endpoint with scoring
- Site usage statistics endpoint
- Preference configuration stored as WP option

### Phase 2: WordPress Plugin — Write Layer
- `PATCH /posts/{id}/blocks/{index}` — single block update
- `POST /posts/{id}/blocks` — block insertion
- `DELETE /posts/{id}/blocks/{index}` — block removal
- `PUT /posts/{id}/blocks` — full page rewrite
- Revision creation on every write
- Block validation and preference warnings

### Phase 3: WordPress Plugin — Pattern Operations
- Pattern insertion (synced or inline)
- Preference-aware pattern recommendations

### Phase 4: MCP Server
- TypeScript MCP server wrapping all REST endpoints
- Tool definitions for all operations
- Response enrichment with preference annotations
- System prompt resource for agent context

### Phase 5: Legacy Cleanup
- Trash 9 dead legacy patterns (0 references)
- Audit remaining 2 legacy patterns with references
- Create modern replacements for referenced legacy patterns
- Migration tooling to swap legacy pattern refs to new versions

---

## File Structure

```
MCPs/block-mcp/
├── SPEC.md                          # This file
├── wordpress-plugin/
│   └── gk-block-api/
│       ├── gk-block-api.php         # Plugin bootstrap
│       ├── includes/
│       │   ├── class-block-registry.php    # Block type registry + preferences
│       │   ├── class-pattern-manager.php   # Pattern listing + scoring
│       │   ├── class-block-crud.php        # parse/serialize block operations
│       │   ├── class-rest-controller.php   # REST endpoint registration
│       │   ├── class-usage-stats.php       # Site-wide usage analytics
│       │   └── class-preferences.php       # Preference config + scoring
│       └── uninstall.php
├── src/
│   ├── index.ts                     # MCP server entry point
│   ├── tools/
│   │   ├── discovery.ts             # list_block_types, list_patterns, get_pattern
│   │   ├── read.ts                  # get_page_blocks, get_site_usage
│   │   ├── write.ts                 # update_block, insert_blocks, delete_block
│   │   └── patterns.ts             # insert_pattern (synced or inline)
│   ├── client.ts                    # WordPress REST API client
│   ├── preferences.ts               # Client-side preference enrichment
│   └── types.ts                     # TypeScript interfaces
├── package.json
├── tsconfig.json
└── .env.example
```
