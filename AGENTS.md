# AGENTS.md — GK Block API + MCP Server

> Block-level WordPress content CRUD with path-based mutations, preference scoring, and AI-friendly tooling via MCP.

## Quick Start

```bash
# 1. Install dependencies
cd MCPs/block-mcp && npm install

# 2. Build the MCP server (esbuild, single CJS bundle)
npm run build          # → dist/index.cjs

# 3. Set environment variables (or copy .env.example → .env)
export WORDPRESS_URL=https://www.gravitykit.com
export WORDPRESS_USER=<wordpress-username>
export WORDPRESS_APP_PASSWORD=<app-password>

# 4. Start the server (stdio transport — used by Claude Code / Hermes)
npm start              # node dist/index.cjs

# 5. Inspect with MCP Inspector (interactive debugging UI)
npm run inspect
```

The WordPress plugin (`wordpress-plugin/gk-block-api/`) must be active on the target site. It registers the `gk-block-api/v1` REST namespace.

## Repository Map

```
MCPs/block-mcp/
├── AGENTS.md                          # This file
├── CLAUDE.md                          # Points Claude at AGENTS.md
├── docs/specs/                        # Versioned design specs (v1.2: docs lifecycle)
├── package.json                       # @gravitykit/block-mcp, esbuild build
├── tsconfig.json                      # ES2022, bundler resolution
├── .env.example                       # Required env vars template
├── src/
│   ├── index.ts                       # MCP server entry — aggregates tools, wires handlers
│   ├── client.ts                      # WordPressBlockClient — HTTP client for REST API
│   ├── types.ts                       # All TypeScript interfaces (config, blocks, mutations, responses)
│   ├── preferences.ts                 # Client-side enrichment: legacy warnings, tier grouping
│   └── tools/
│       ├── discovery.ts               # list_block_types, list_patterns, get_pattern, get_site_usage
│       ├── read.ts                    # get_page_blocks
│       ├── write.ts                   # update_block, insert_blocks, delete_block, rewrite_post_blocks
│       ├── mutate.ts                  # edit_block_tree (9 operations)
│       └── patterns.ts               # insert_pattern
├── dist/
│   └── index.cjs                      # Built bundle (esbuild, CJS, single file)
└── wordpress-plugin/
    └── gk-block-api/
        ├── gk-block-api.php           # Plugin bootstrap — autoloader, rest_api_init wiring
        ├── uninstall.php              # Cleanup: deletes option + transient
        └── includes/
            ├── class-rest-controller.php    # All REST route registration + endpoint handlers
            ├── class-block-crud.php         # Core CRUD + mutation engine + auto-transform
            ├── class-block-safety.php       # Static block markup staleness detection
            ├── class-block-registry.php     # Block type discovery with preference enrichment
            ├── class-pattern-manager.php    # Pattern listing, scoring, legacy detection
            ├── class-preferences.php        # Namespace scoring, tier system, replacement map
            └── class-block-inventory.php    # Site-wide block + pattern inventory (cached)
```

## Architecture

### Two-Component Design

```
AI Agent  ←→  MCP Server (TypeScript, stdio)  ←→  WordPress REST API (PHP plugin)
              src/index.ts                          wordpress-plugin/gk-block-api/
              Enriches responses with                Parses/serializes blocks, manages
              natural-language guidance               revisions, enforces preferences
```

The MCP server is a thin orchestration layer. It validates inputs, delegates to the WordPress REST API, and enriches responses with AI-friendly annotations (tier groupings, legacy warnings, replacement suggestions). The heavy lifting — block parsing, serialization, safety checks, rate limiting, revision tracking — lives in the PHP plugin.

### WordPress Plugin: Initialization Flow

Defined in `gk-block-api.php` (lines 54-76). On `rest_api_init`:

1. `Preferences` — loads namespace scores and replacement map from `wp_options`
2. `Block_Inventory` — cached site-wide block + pattern inventory (1-hour transient)
3. `Block_Registry(Preferences, Block_Inventory)` — enriched block type discovery
4. `Pattern_Manager(Preferences)` — pattern listing with scoring
5. `Block_Safety` — static block markup staleness checker
6. `Block_CRUD(Preferences, Block_Safety)` — all read/write operations
7. `REST_Controller(Block_Registry, Pattern_Manager, Block_CRUD, Block_Inventory, Block_Mutator, Post_Manager, Term_Manager, Media_Manager, Preferences)` — registers routes

All classes use the `GravityKit\BlockAPI` namespace with PSR-4-style autoloading via `spl_autoload_register` (class name → `includes/class-{name}.php`).

### WordPress Plugin: REST Endpoints

All routes are under `gk-block-api/v1`. Read endpoints require `edit_posts`; write endpoints require `edit_post` on the specific post.

| Method | Route | Handler | Purpose |
|--------|-------|---------|---------|
| GET | `/block-types` | `get_block_types` | List all registered block types with preference scores |
| GET | `/block-types/{namespace}` | `get_block_types_by_namespace` | Filter block types by namespace |
| GET | `/patterns` | `get_patterns` | List patterns with scoring, filtering, sorting |
| GET | `/patterns/search?q={term}` | `search_patterns` | Search patterns by name/keyword |
| GET | `/patterns/{id}` | `get_pattern` | Single pattern with parsed block content |
| GET | `/site-usage` | `get_site_usage` | Block/pattern usage analytics |
| GET | `/resolve?url={path}` | `resolve_url` | URL path → post ID resolver |
| GET | `/posts/{id}/blocks` | `get_post_blocks` | Page blocks as structured JSON |
| PATCH | `/posts/{id}/blocks/{index}` | `update_block` | Update single block attrs/HTML |
| POST | `/posts/{id}/blocks/batch-update` | `update_blocks_batch` | Apply N independent updates atomically in ONE revision |
| POST | `/posts/{id}/blocks` | `insert_blocks` | Insert blocks at position |
| DELETE | `/posts/{id}/blocks/{index}` | `delete_block` | Remove block(s) |
| PUT | `/posts/{id}/blocks` | `replace_all_blocks` | Full page rewrite |
| POST | `/posts/{id}/insert-pattern` | `insert_pattern` | Insert pattern (synced or inline) |
| POST | `/posts/{id}/mutate` | `mutate_block_tree` | Path-based structural mutations |
| POST | `/posts` | `create_post` | (v1.2) Create a new post or page |
| PATCH | `/posts/{id}` | `update_post` | (v1.2) Update post metadata, status, or terms |
| GET | `/terms` | `list_terms` | (v1.2) List taxonomy terms |
| POST | `/media` | `upload_media` | (v1.2) Upload to media library (multipart, URL sideload, or base64) |

GET `/posts/{id}/blocks` supports query params:
- `fields` — comma-separated field filter (e.g., `path,name,attributes`)
- `search` — text search in innerHTML
- `block_name` — filter by block name
- `render` — include rendered output, expand shortcodes, resolve synced patterns

### WordPress Plugin: Core Classes

**`Block_CRUD`** (`class-block-crud.php`, ~1184 lines) — the block-level engine.
- `get_blocks($post_id, $render)` — parses `post_content`, formats with path tracking
- `update_block()` — merges attributes and/or replaces innerHTML at flat index
- `update_blocks_batch()` — applies N independent updates atomically (ONE revision); validates all-or-nothing, server-side cap of `Block_CRUD::MAX_BATCH_SIZE` (50)
- `insert_blocks()` — validates block names against registry, checks preference tier, splices into content
- `delete_blocks()` — removes consecutive blocks, warns on synced pattern removal
- `replace_all_blocks()` — full page rewrite with block validation
- `insert_pattern()` — synced (core/block ref) or inline pattern insertion
- `mutate_block_tree()` — 9 path-based operations (see Key Concepts)
- `auto_transform_html()` — automatically updates innerHTML when attributes change (see Key Concepts)
- `save_post_content()` — saves via `wp_update_post`, tracks before/after revision IDs
- Rate limiting: 10 writes/min/post, 2 full rewrites/min/post (transient-based)

**`Preferences`** (`class-preferences.php`) — scoring engine.
- Stored in `wp_options` as `gk_block_api_preferences`
- `get_block_score($name)` → `{ score, tier, namespace_policy }`
- `get_pattern_score($input)` → `{ score, tier, reasons[] }`
- `get_replacement($name)` → replacement block name or null
- Score-to-tier mapping: `>=80` preferred, `>=50` acceptable, `>=10` avoid, `<10` legacy

**`Block_Safety`** (`class-block-safety.php`) — mutation guard.
- `check_mutation($block_name, $changed_attrs, $has_new_html)` → warning array
- Editor-only attrs (always safe): `lock`, `templateLock`, `allowedBlocks`, `metadata`, `className`, `anchor`, `align`, `fontFamily`, `fontSize`
- Dynamic blocks are always safe (render via PHP at runtime)
- Only warns on static blocks when render-affecting attrs change without innerHTML

**`Block_Registry`** (`class-block-registry.php`) — discovery with enrichment.
- Wraps `WP_Block_Type_Registry`, adds preference scores, usage counts, replacement info
- Supports namespace, category, and preferred_only filtering

**`Pattern_Manager`** (`class-pattern-manager.php`) — pattern intelligence.
- Queries both synced patterns (`wp_block` CPT) and registered patterns (`WP_Block_Patterns_Registry`)
- Scores patterns by: recency bonus, reference count multiplier, legacy block penalty
- Counts cross-site pattern references via `wpdb` query

**`Block_Inventory`** (`class-block-inventory.php`) — site-wide block + pattern inventory.
- Scans all published content, counts blocks per post, tracks namespace totals
- Detects legacy patterns (synced patterns containing avoid/legacy blocks)
- Cached in transient `gk_block_inventory` (1-hour TTL)

**`Post_Manager`** (`class-post-manager.php`, v1.2, ~696 lines) — post lifecycle.
- `create_post( $args )` — create a new post or page. Validates: title required, post_type allow-list (overridable via `gk_block_api_post_types_allowlist` option), status enum (no `trash` on create), `future` status requires future `date`, parent must be hierarchical type and not self, terms must exist in their taxonomy, featured_media must be an image attachment.
- `update_post( $post_id, $args )` — partial update. Routes status transitions through `wp_trash_post`/`wp_untrash_post` so trash hooks fire. Rejects `mixed_trash_payload` (status:trash + other fields). Uses `Block_CRUD::check_rate_limit` (10 writes/min/post bucket) — shared with the per-block write tools.
- Block validation delegates to `Block_CRUD::validate_block_def()` — single source of truth for tier policy and replacement messaging.

**`Term_Manager`** (`class-term-manager.php`, v1.2, ~107 lines) — read-only term listing.
- `list_terms( $args )` — wraps `get_terms()` + `wp_count_terms()`. Returns `{ taxonomy, total, page, per_page, terms[] }`. Per-page caps at 200.

**`Media_Manager`** (`class-media-manager.php`, v1.2, ~404 lines) — media uploads.
- Three input modes (mutually exclusive): multipart `file` field, URL sideload, base64 (with `filename`).
- **SSRF guard** (`guard_ssrf`): URL host is DNS-resolved; reserved/private/link-local IPv4 ranges (RFC1918, 169.254/16 cloud metadata, 127/8 loopback, 0/8, 224/4 multicast) are rejected with `400 invalid_url` *before* `download_url()` runs. Block list is admin-extensible via the `gk_block_api_url_sideload_blocked_ranges` filter.
- Size cap: URL sideload limited to `URL_DOWNLOAD_MAX_BYTES` (25 MB). Base64 size is checked twice — encoded length first (cheap), then decoded length — so memory consumption is bounded before any disk write.
- MIME via `wp_check_filetype_and_ext`. Disallowed types rejected with `400 disallowed_mime`; tmp file is `@unlink`'d on every error path.
- `download_url()` timeout reduced from default 300s to 10s.

### MCP Server: Tool Architecture

The server (`src/index.ts`) aggregates tools from five modules, each exporting a `*_TOOLS` array and a `handle*Tool()` dispatcher:

| Module | Tools | Category |
|--------|-------|----------|
| `discovery.ts` | `list_block_types`, `list_patterns`, `get_pattern`, `get_site_usage` | Read-only exploration |
| `read.ts` | `get_page_blocks` | Page content reading |
| `write.ts` | `update_block`, `update_blocks`, `insert_blocks`, `delete_block`, `replace_block_range`, `rewrite_post_blocks`, `revert_to_revision` | Index-based CRUD |
| `mutate.ts` | `edit_block_tree` | Path-based structural operations |
| `patterns.ts` | `insert_pattern` | Pattern insertion |
| `posts.ts` (v1.2) | `create_post`, `update_post` | Post lifecycle |
| `terms.ts` (v1.2) | `list_terms` | Taxonomy term discovery |
| `media.ts` (v1.2) | `upload_media` | Media library upload |
| `yoast.ts` (v1.2) | `yoast_get_seo`, `yoast_update_seo`, `yoast_bulk_update_seo` | Yoast SEO metadata (separate REST namespace) |

Tool routing in `index.ts` uses `Set<string>` lookups per category (lines 92-104). Each handler:
1. Validates and casts input arguments
2. Calls `WordPressBlockClient` methods
3. Enriches results via `preferences.ts` functions (`enrichBlockTypes`, `enrichBlockList`, `enrichPatternList`)
4. Returns JSON stringified in MCP text content format

The server also exposes a **resource** (`block-mcp://block-preferences`) containing block preference rules as a system prompt context.

**Client** (`src/client.ts`) — typed HTTP wrapper using axios.
- Base URL: `{WORDPRESS_URL}/wp-json/gk-block-api/v1`
- Auth: Basic Auth with Application Password (base64-encoded)
- 30-second timeout, meaningful error formatting for connection/timeout/HTTP errors
- Response interceptor converts `AxiosError` into human-readable messages

**Preference Enrichment** (`src/preferences.ts`) — client-side annotation layer.
- `enrichBlockList(blocks)` — scans blocks for legacy namespaces, returns warnings with suggested replacements
- `enrichPatternList(patterns)` — sorts by score, groups into recommended/avoid tiers, generates summary
- `enrichBlockTypes(types)` — groups by tier (preferred/standard/acceptable/avoid/legacy), generates guidance text
- `formatPreferenceWarning(warning)` — single-line warning message
- Mirrors the PHP replacement map (lines 22-43) for client-side lookups
- Legacy namespace set: `stackable`, `ugb`, `jetpack`

**Data Flow** (example: `get_page_blocks`):
1. Agent calls `get_page_blocks({ post_id: 123 })`
2. `handleReadTool()` validates args, calls `client.getPageBlocks(123)`
3. Client sends `GET /wp-json/gk-block-api/v1/posts/123/blocks`
4. PHP plugin: `Block_CRUD::get_blocks()` → `parse_blocks()` → `format_blocks_recursive()`
5. REST response returns structured JSON with index, path, name, attributes, innerHTML
6. MCP server: `enrichBlockList()` adds legacy warnings and summary
7. Agent receives blocks + warnings + natural-language summary

## Conventions

### PHP (WordPress Plugin)

- **Namespace**: `GravityKit\BlockAPI`
- **Autoloading**: `class-{lowercased-underscored-name}.php` convention
- **Error handling**: Return `\WP_Error` from service classes; `REST_Controller` wraps exceptions via `handle_error()`
- **Sanitization**: `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for innerHTML
- **Rate limiting**: Transient-based per-post (`gk_block_api_rate_{post_id}`), sliding 60-second window
- **Revisions**: Every write operation tracks `before_revision_id` and `revision_id`
- **Permissions**: Read = `edit_posts` capability; Write = `edit_post` on specific post
- **Text domain**: `gk-block-api`

### TypeScript (MCP Server)

- **Module system**: ESM source (`"type": "module"` in package.json), esbuild bundles to CJS (`dist/index.cjs`)
- **Import extensions**: All imports use `.js` suffix (TypeScript with ESM resolution)
- **Build tool**: esbuild (not tsc) — single-file bundle, no separate declaration emit at build time
- **No dotenv**: Environment variables passed by parent process; dotenv breaks esbuild ESM bundles
- **Error format**: `{ error: true, message, statusCode, tool }` with `isError: true` in MCP response
- **Type safety**: Strict mode, all API responses fully typed in `types.ts`

### Comments and docblocks

Code comments, docblocks, and commit messages are **public artifacts**. They must read as standalone documentation of what the code does today — never as a journal of how it got there.

#### Internal-process references

Don't reference internal plans, specs, tickets, or review processes from inside source files:

- No CodeRabbit / review-tool attributions ("review found", "as flagged by …"). Apply the fix; write the comment from the perspective of the code's current behaviour.
- No `docs/specs/…` path pointers as the load-bearing reason a piece of code exists. The code is the source of truth — if a comment only makes sense once you've opened an off-tree spec, the comment is wrong. (It's fine to *cite* a spec for the curious; it's not fine to require it for comprehension.)
- No Linear / GitHub-issue numbers in source (`# fixes ABC-123`). Belong in PR descriptions and commit trailers, not in source.
- No "TODO(<initiative>-followup)" tags referencing internal initiative names. Plain `TODO:` is fine when the gap itself is described concretely; an initiative-name-only TODO is noise.
- No "future SaaS revision will…" or "when X lands we'll switch on…" speculation. File a ticket; don't seed a code comment that rots into a stale promise.

| Avoid | Prefer |
|---|---|
| `// Pre-fix, find_posts ran without perm:'readable' …` | `// perm:'readable' pushes the cap filter into posts_where_paged.` |
| `// Per spec section 3.3 — only assign refs when persist_refs=true` | `// Only persist refs when persist_refs=true; otherwise refs live in-memory only.` |
| `// TODO(planB-followup): test harness fix needed` | `// TODO: form posts from about:blank end up at wp-login.php; need a different submit path.` |
| `// CodeRabbit flagged: secrets in job-level if invalid` | *(delete; the workflow file's structure is the documentation)* |

#### Engineering planning, scale, and architecture speculation

Source files are not the place for capacity planning or future-architecture suggestions:

- **Internal scale assumptions.** No "≤ a few hundred posts per site", "we expect <50 blocks per page", "typical install has fewer than 10 patterns". Load-bearing numbers belong in benchmarks or capacity-planning docs; non-load-bearing ones don't belong anywhere.
- **Future-architecture suggestions.** No "promote pattern_refs to a custom table later", "switch the inventory transient to options when sites scale", "v2 should do X". File a ticket; plans evolve cleanly there and rot in source.
- **Speculative thresholds.** "If we ever exceed N…", "when this becomes a bottleneck…" — same problem. Measure and act, or file a ticket.

#### What to write instead

1. **Describe behaviour in the present tense.** "Coalesces same-block writes inside a single revision," not "we used to write a revision per call but now we batch."
2. **Document hard contracts callers MUST honor.** "Each block name must be registered with `WP_Block_Type_Registry::is_registered` before insertion." "innerContent null positions must be preserved across mutations." "Not atomic — last write wins on the per-post rate-limit transient."
3. **Document non-obvious WHY when behaviour is surprising.** If `is_post_readable` cap-elevates password-protected posts to `edit_post`, the one-line "passwords are cookie-checked, not cap-checked, so we require a stronger cap for that branch" earns its keep. The reader's question is "why does this look stronger than needed?"; the comment answers it without requiring an off-tree doc.

Three quick tests before writing any comment or docblock — if any answer is yes, rewrite or move it:

- Would this be a sentence in a Linear ticket / PR description / postmortem? Put it there instead.
- Does it describe history ("pre-fix", "we used to") or speculate about future architecture ("if we ever", "promote to X later")? Git blame handles history; the issue tracker handles plans.
- Does it require the reader to know about an internal artifact (a spec path, a review tool's flag, an initiative codename) to make sense? Rewrite from the code's standpoint.

## Key Concepts

### Block Paths

Blocks are addressed two ways:

1. **Flat index** — sequential counter across all blocks (skipping empty/whitespace). Used by `update_block`, `insert_blocks`, `delete_block` (the write tools).
2. **Path** — integer array describing position in the nested tree. `[0, 2, 1]` means "top-level block 0 → innerBlock 2 → innerBlock 1". Used by `edit_block_tree`.

Both are returned by `get_page_blocks`. The `path` field uses raw `parse_blocks()` indices (preserving whitespace-only block positions), while `index` is a sequential counter that skips empty blocks.

The mutate endpoint supports nine operations:

| Operation | Required Fields | Effect |
|-----------|----------------|--------|
| `update-attrs` | `attributes` | Merge attributes; auto-transform innerHTML if possible |
| `update-html` | `innerHTML` | Replace innerHTML; preserve innerBlock placeholders |
| `replace-block` | `block` | Swap entire block at path |
| `remove-block` | — | Delete block, re-index siblings |
| `wrap-in-group` | (optional `wrapper`) | Wrap block in core/group or custom container |
| `unwrap-group` | — | Promote innerBlocks to parent level |
| `insert-child` | `block`, (optional `position`) | Add child to container block |
| `duplicate` | — | Deep-clone block, insert after original |
| `move` | `before` or `destination`, (optional `count`) | Relocate block(s) with pre-move index adjustment |

### Preference Scoring & Tiers

Namespace-based scores (from `Preferences::get_defaults()`, `class-preferences.php` lines 46-59):

| Namespace | Score | Tier | Policy |
|-----------|-------|------|--------|
| `filter` (theme) | 100 | preferred | always_prefer |
| `core` | 90 | preferred | always_prefer |
| `gravityforms`, `gk-*` | 80 | preferred | always_prefer |
| `outermost` | 60 | acceptable | use_if_needed |
| `kevinbatdorf` | 50 | acceptable | use_if_needed |
| `stackable` | 10 | avoid | migrate_away |
| `ugb`, `jetpack` | 0 | legacy | never_use |
| Unknown | 30 | acceptable | — |

**Enforcement**:
- `legacy` blocks are **hard-rejected** on insert/replace (HTTP 400)
- `avoid` blocks generate warnings but are allowed
- `preferred` and `acceptable` pass silently

Pattern scoring (`class-preferences.php` lines 199-246) combines:
- Recency bonus: 2026 → +50, 2025 → +30, 2024 → +10
- Reference multiplier: refs x 5
- Legacy penalty: -100 if pattern contains legacy blocks; +20 bonus if clean

### Static Block Safety

When `edit_block_tree` runs `update-attrs` on a static block (no PHP render callback), `Block_Safety::check_mutation()` checks whether the changed attributes affect rendered markup. If they do and no new innerHTML is provided, a `static_markup_stale_risk` warning is returned.

**Editor-only attributes** (never affect innerHTML): `lock`, `templateLock`, `allowedBlocks`, `metadata`, `className`, `anchor`, `align`, `fontFamily`, `fontSize`.

Dynamic blocks (those with `is_dynamic() === true` or unregistered blocks) skip this check entirely.

### Auto-Transform

`Block_CRUD::auto_transform_html()` (`class-block-crud.php`, lines 1392-1608) automatically updates innerHTML when attribute changes imply structural HTML changes. This prevents the static block staleness warning for known patterns.

Four categories of transforms:

1. **Tag name swaps** (regex — WP_HTML_Tag_Processor cannot change tag names):
   - `core/list` + `ordered` → `<ul>` ↔ `<ol>`
   - `core/heading` + `level` → `<h1>` through `<h6>`
   - `core/group` + `tagName` → `<div>`, `<section>`, `<aside>`, etc.

2. **HTML attribute transforms** (WP_HTML_Tag_Processor):
   - `url` → `href`/`src` on links, images, media
   - `src` → `src` on media elements
   - `alt` → `alt` on images
   - Boolean attributes: `autoplay`, `loop` on audio/video
   - `core/details` + `showContent` → `open` attribute

3. **CSS inline style transforms** (WP_HTML_Tag_Processor):
   - `height`, `width` → inline style property replacement

4. **Text content transforms** (regex):
   - `core/quote`/`core/pullquote` + `citation` → `<cite>` inner text

When auto-transform succeeds, it also updates `innerContent` (the array with null placeholders for innerBlocks) to match, preserving container block structure.

### Render Mode

GET `/posts/{id}/blocks?render=true` activates render mode in `format_blocks_recursive()`:
- Dynamic blocks get `rendered_html` (full HTML) and `rendered_text` (plain text, max 500 chars)
- Shortcodes in innerHTML are expanded via `do_shortcode()`
- Synced pattern references (`core/block`) include the pattern's parsed block tree
- Each block is tagged `dynamic: true/false`

Post context is set up before rendering (`setup_postdata`) so shortcodes like `[filter_edd_version_number]` resolve correctly.

## Extension Patterns

### Adding a New REST Endpoint

1. Register the route in `REST_Controller::register_routes()` (`class-rest-controller.php`):

```php
register_rest_route(
    self::NAMESPACE,
    '/my-endpoint',
    array(
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => array( $this, 'my_handler' ),
        'permission_callback' => array( $this, 'check_permissions' ),
        'args'                => array( /* schema */ ),
    )
);
```

2. Add the handler method to `REST_Controller` following the try/catch + `handle_error()` pattern
3. Use `check_permissions()` for read, `check_edit_permissions()` + `check_post_edit_permission($post_id)` for write
4. Delegate to the appropriate service class (Block_CRUD, Pattern_Manager, etc.)

### Adding a New MCP Tool

1. Add the tool definition to the appropriate `*_TOOLS` array in `src/tools/*.ts`:

```typescript
{
  name: 'my_tool',
  description: 'Clear description of what this tool does for an AI agent.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      post_id: { type: 'number', description: 'WordPress post or page ID.' },
    },
    required: ['post_id'],
  },
}
```

2. Add the case to the corresponding `handle*Tool()` switch
3. If it calls a new REST endpoint, add the client method to `src/client.ts` with full types
4. Add response types to `src/types.ts`
5. Rebuild: `npm run build`

### Adding a New Mutation Operation

1. Add the op name to the `MutationOp` union type in `src/types.ts` (line 279)
2. Add validation fields to `MutationRequest` interface in `src/types.ts` (line 291)
3. Add the `case` in `Block_CRUD::mutate_block_tree()` (`class-block-crud.php`, around line 801):

```php
case 'my-op':
    // Validate params
    // Modify $parent[$target_index]
    $result_block = array( 'name' => ..., 'attributes' => ... );
    break;
```

4. Add the op to the `enum` in `REST_Controller::register_routes()` (`class-rest-controller.php`, line 401)
5. Add client-side validation in `handleMutateTool()` (`src/tools/mutate.ts`)
6. Add the op to `VALID_OPS` set in `src/tools/mutate.ts` (line 16)
7. Add the op to the `enum` in the `edit_block_tree` tool's `inputSchema` (`src/tools/mutate.ts`, line 50)

### Adding a New Auto-Transform

In `Block_CRUD::auto_transform_html()` (`class-block-crud.php`, starting line 1392):

1. Choose the category (tag swap, HTML attr, CSS style, or text content)
2. Add a conditional block matching `$block_name` and `$changed_attrs`
3. For HTML attributes: use `WP_HTML_Tag_Processor` (never escape values — it handles that internally)
4. For tag swaps: use regex (the processor cannot change tag names)
5. The method returns `null` if no transform applies (safety warning fires instead)

Example — adding a transform for `core/button` link text:

```php
// In auto_transform_html(), under section 4 (text content transforms):
if ( 'core/button' === $block_name && array_key_exists( 'text', $changed_attrs ) ) {
    $new_text = wp_kses_post( $changed_attrs['text'] );
    $html = preg_replace_callback(
        '/(<a[^>]*>).*?(<\/a>)/is',
        function ( $matches ) use ( $new_text ) {
            return $matches[1] . $new_text . $matches[2];
        },
        $html
    );
}
```

## Hook Reference (WordPress)

The plugin does not define custom action/filter hooks. It relies on these WordPress extension points:

- **`rest_api_init`** — plugin initialization happens here (`gk-block-api.php`, line 54). All classes are instantiated on this hook (not on `plugins_loaded`) to ensure the REST infrastructure is ready.
- **`wp_kses_post`** — all innerHTML passes through this filter on write operations for XSS sanitization
- **`parse_blocks()` / `serialize_blocks()`** — WordPress core functions for block parsing/serialization. The plugin never manipulates raw `post_content` strings directly.
- **`wp_update_post()`** — used for all saves, which triggers WordPress revision creation automatically
- **`wp_get_post_revisions()`** — queried before and after saves to track revision IDs
- **`WP_Block_Type_Registry::is_registered()`** — validates block names on all insert/replace operations
- **`WP_HTML_Tag_Processor`** — used in `auto_transform_html()` for safe HTML attribute manipulation

**Stored data**:
- `wp_options` key `gk_block_api_preferences` — preference config (namespace scores, pattern scoring, replacement map)
- Transient `gk_block_inventory` — cached site-wide block + pattern inventory (1-hour TTL)
- Transient `gk_block_api_rate_{post_id}` — per-post rate limiting data (auto-expires)

## Development

### Prerequisites

- Node.js >= 20
- npm
- WordPress 6.0+ site with Application Passwords enabled
- PHP 7.4+

### Building the MCP Server

```bash
npm run build     # One-shot build → dist/index.cjs
npm run dev       # Watch mode (esbuild --watch)
```

The build uses esbuild to create a single CJS bundle. TypeScript compilation (`tsc`) is available via tsconfig.json but is not used for the production build — esbuild handles both bundling and transpilation.

Build output is a single `dist/index.cjs` file (~50KB) with all dependencies bundled except `node_modules` externals. The `@modelcontextprotocol/sdk` and `axios` are bundled inline.

**Why CJS output?** The MCP SDK's stdio transport uses `process.stdin`/`process.stdout` which work more reliably with CJS require chains than ESM dynamic imports in some Node.js configurations.

### Testing Locally (gkclone)

1. Start the gkclone local WordPress environment
2. Symlink or copy the plugin: `wordpress-plugin/gk-block-api/` → gkclone's `wp-content/plugins/`
3. Activate the plugin in WordPress admin
4. Create an Application Password for a user with `edit_posts` capability
5. Set env vars and run `npm start`

### Deploying the Plugin

Copy the `wordpress-plugin/gk-block-api/` directory to the target site's `wp-content/plugins/` and activate. No build step required for the PHP plugin — it is pure PHP with no Composer dependencies.

## Versioning & Releases

The WordPress plugin (`wordpress-plugin/gk-block-api/`) and the MCP server (`package.json`) version independently. The plugin follows WordPress plugin conventions (`readme.txt` is the canonical changelog); the MCP server is just the bundle that talks to it.

### Semver policy (plugin)

- **MAJOR** (`x.0.0`) — breaking REST changes: removed endpoints, renamed routes, removed response fields, changed permission semantics, broken backwards compatibility on existing tool signatures. Bump only with a migration note.
- **MINOR** (`x.y.0`) — new endpoints, new tools, new request/response fields, new shortcode-attr filters, new admin settings. Additive only — existing consumers must continue to work unchanged.
- **PATCH** (`x.y.z`) — bug fixes, security patches, hardening, internal refactors, doc-only changes, test additions, i18n. No surface-area changes.

### Required updates on every plugin version bump

A version bump is not done until **all five** of these are updated to the new version:

1. `wordpress-plugin/gk-block-api/gk-block-api.php` — `* Version:` plugin header
2. `wordpress-plugin/gk-block-api/gk-block-api.php` — `GK_BLOCK_API_VERSION` constant
3. `wordpress-plugin/gk-block-api/readme.txt` — `Stable tag:` line
4. `wordpress-plugin/gk-block-api/readme.txt` — `== Upgrade Notice ==` section (one-paragraph summary, newest at top)
5. `wordpress-plugin/gk-block-api/readme.txt` — `== Changelog ==` section (bulleted list of every notable change, grouped by `* New:` / `* Improved:` / `* Fixed:` / `* Deprecated:` / `* i18n:` / `* Tests:` / `* Doc:`, newest at top)

The `Upgrade Notice` block is what WordPress.org shows to admins on the update screen — keep it scannable (1–3 sentences, headline value only). The `Changelog` block is the durable record — be specific.

### Required updates on every MCP server version bump

1. `package.json` — `"version"` field
2. Optional: mention in the plugin's `readme.txt` Changelog if a server-side TS change is user-observable

The MCP server version is informational; the plugin version is what site owners track.

### Process

1. Land feature/fix commits normally with descriptive messages.
2. When ready to cut a release, in a single `chore(plugin): bump gk-block-api to X.Y.Z` commit:
   - Update items 1–3 above
   - Append the new `Upgrade Notice` and `Changelog` entries
   - If the MCP server side also moved, bump `package.json` in the same commit
3. Push.

### Tagging the release

Every plugin version bump gets a matching annotated git tag. Tags live on the merge commit on `main`; never on a feature branch tip.

- **Format**: `v{plugin-version}` — e.g. `v1.7.0`. Match the plugin version, not the MCP server version. (When the MCP server bumps independently without a plugin bump, no tag — server versions are informational.)
- **Annotated, not lightweight**: `git tag -a v1.7.0 -m "<release notes>"`. Lightweight tags don't carry a message and don't show up in `git show`.
- **Tag message**: the same prose as the `readme.txt` `Upgrade Notice` for that version, plus a `Highlights:` bulleted list of the headline changes. Look at `git show v1.6.0` for the canonical shape.
- **Push the tag explicitly**: `git push origin v1.7.0`. Bare `git push` does not push tags. Without this step the tag exists locally only and `gh release create` can't find it.
- **When to tag**: after the version-bump commit has landed on `main` (via PR merge or direct push). Tagging on the feature branch and merging via squash strands the tag on a dead commit.
- **GitHub release**: optional, but if you create one, attach it to the tag (`gh release create v1.7.0 --notes-file …` or via the UI). The marketplace pin / Composer installer reads the tag, not the release.

### Backfilling missing entries

If a version was bumped without a corresponding `readme.txt` entry (it happens — historically `1.4.1`, `1.4.2`, and `1.5.0` all shipped without changelog updates), audit the commits that landed between the previous bump and the version bump commit (`git log <prev-bump>..<this-bump> -- wordpress-plugin/gk-block-api/`) and write the missing entries. Keep the Upgrade Notice and Changelog sections in strict reverse-chronological order across all versions.

## Gotchas

- **Empty blocks in parse_blocks()**: WordPress includes whitespace-only blocks in `parse_blocks()` output. The plugin filters these (checks `blockName` is non-empty) for write operations but preserves raw indices for path-based addressing. The `index` field skips empties; the `path` field does not. This means `path: [2]` might address what appears to be the first block if indices 0 and 1 are whitespace.

- **innerContent vs innerHTML**: `innerHTML` is the block's own HTML content. `innerContent` is an array where `null` entries are placeholders for innerBlocks positions. A container block's innerContent looks like `['<div class="wp-block-group">', null, null, '</div>']` — two nulls for two child blocks. When updating innerHTML on a container, `rebuild_inner_content()` (`class-block-crud.php`, line 1630) splits the new HTML at the first `>` to find the opening tag, preserves null positions, and appends the closing portion. Destroying this structure causes `serialize_blocks()` to lose child blocks.

- **No dotenv in MCP server**: The entry point (`src/index.ts`, line 37) explicitly avoids `dotenv.config()` because it breaks esbuild ESM bundles via CJS dynamic `require('fs')`. Environment variables must be passed by the parent process.

- **Rate limiting is per-post**: 10 writes/min and 2 full rewrites (PUT)/min per post, tracked via transients with key `gk_block_api_rate_{post_id}`. The limit resets on its own after 60 seconds. The sliding window uses timestamp arrays.

- **Legacy blocks are hard-rejected**: Inserting a `legacy`-tier block (`ugb/*`, `jetpack/*`) returns HTTP 400 with error code `legacy_block`. `avoid`-tier blocks (`stackable/*`) generate warnings but succeed. This is enforced in `insert_blocks()`, `replace_all_blocks()`, `replace-block` mutation, and `insert-child` mutation.

- **Registered patterns cannot be synced**: Only synced patterns (wp_block CPT with a post ID) can be inserted as `core/block` references. Registered patterns are always inlined — `synced` is forced to `false` in `insert_pattern()` (`class-block-crud.php`, line 616).

- **WP_HTML_Tag_Processor does not double-escape**: In `auto_transform_html()`, attribute values passed to `set_attribute()` must NOT be pre-escaped with `esc_attr()`. The processor handles escaping internally. Double-escaping produces garbled output like `&amp;amp;`.

- **Move uses pre-move indexing**: The `before` parameter in the `move` operation uses paths as they exist before the move happens. The PHP code (`class-block-crud.php`, lines 1256-1301) adjusts destination indices after removing the source blocks, handling both same-parent and cross-level moves.

- **Cannot move into self or descendants**: The `move` operation validates that the destination path does not start with the source path (lines 1231-1246). This prevents creating circular references.

- **Unwrap updates grandparent innerContent**: When `unwrap-group` promotes N children from a container, the grandparent's `innerContent` must replace the single null (for the removed container) with N nulls (for the promoted children). This adjustment happens at lines 1047-1077.

- **Duplicate deep-clones via PHP serialize**: The `duplicate` operation uses `unserialize(serialize($original))` for deep cloning (`class-block-crud.php`, line 1169). This handles nested structures but assumes all values are serializable (they always are for parsed blocks).

- **Uninstall cleanup**: Deleting the plugin via WordPress admin removes `gk_block_api_preferences` option and `gk_block_inventory` transient (`uninstall.php`).

- **Block inventory scan is expensive**: `Block_Inventory::build_stats()` queries ALL published posts with `posts_per_page: -1` and parses every one. The 1-hour transient cache is important. Use `refresh: true` sparingly.

- **Pattern reference counting uses LIKE**: `Pattern_Manager::count_pattern_references()` searches post_content with `LIKE '%<!-- wp:block {"ref":ID} /-->%'`. This is accurate but not indexed — avoid calling it on sites with tens of thousands of posts without the transient cache.

## Related Resources

- `docs/specs/` — versioned design specs (v1.2: `2026-04-27-docs-lifecycle-tools.md`)
- WordPress Block API: https://developer.wordpress.org/block-editor/reference-guides/block-api/
- MCP Specification: https://modelcontextprotocol.io
- WP_HTML_Tag_Processor: https://developer.wordpress.org/reference/classes/wp_html_tag_processor/
