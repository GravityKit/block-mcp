# AGENTS.md — GK Block API WordPress Plugin

> REST API for block-level CRUD operations with preference scoring, path-based mutations, and static block safety guards.

## Quick Start

- **Namespace**: `GravityKit\BlockAPI`
- **REST prefix**: `gk-block-api/v1`
- **Auth**: WordPress Application Passwords (Basic Auth over HTTPS). User must have `edit_posts` capability; write endpoints also check `edit_post` per post.
- **Entry point**: `gk-block-api.php` bootstraps on `rest_api_init` (line 54). All class instantiation happens inside that hook — no global singletons.
- **PHP**: 7.4+, WordPress 6.0+

## File Inventory

```
gk-block-api/
├── gk-block-api.php              # Plugin bootstrap, autoloader, rest_api_init hook
├── uninstall.php                 # Cleanup on plugin delete
├── readme.txt                    # WordPress plugin readme
└── includes/
    ├── class-block-crud.php      # Block-level CRUD engine (~1184 lines)
    ├── class-block-mutator.php   # Path-based mutation engine (~836 lines)
    ├── class-html-transformer.php# Auto-transform engine (Tag_Processor) (~376 lines)
    ├── class-rest-controller.php # HTTP layer, route registration (~1870 lines)
    ├── class-preferences.php     # Namespace scoring, replacement map (~345 lines)
    ├── class-block-safety.php    # Static block safety guards (~132 lines)
    ├── class-block-registry.php  # Block type discovery with enrichment (~196 lines)
    ├── class-pattern-manager.php # Synced + registered pattern management (~477 lines)
    ├── class-block-inventory.php # Site-wide block + pattern inventory (~331 lines)
    ├── class-post-manager.php    # (v1.2) create_post / update_post (~696 lines)
    ├── class-term-manager.php    # (v1.2) list_terms (~107 lines)
    └── class-media-manager.php   # (v1.2) upload_media — multipart/URL/base64 with SSRF guard (~404 lines)
```

## Plugin Architecture

### Bootstrap & Autoloading

`gk-block-api.php` registers an `spl_autoload_register` (line 32) that maps `GravityKit\BlockAPI\Some_Class` to `includes/class-some-class.php`. No Composer autoloader needed.

On `rest_api_init` (line 54), the bootstrap creates all service objects and wires them together:

```
Preferences → Block_Registry (+ Block_Inventory)
Preferences → Pattern_Manager
Preferences + Block_Safety → Block_CRUD
Block_Registry + Pattern_Manager + Block_CRUD + Block_Inventory → REST_Controller
```

### Class Dependency Graph

```
REST_Controller
├── Block_Registry
│   ├── Preferences
│   └── Block_Inventory
├── Pattern_Manager
│   └── Preferences
├── Block_CRUD
│   ├── Preferences
│   └── Block_Safety
└── Block_Inventory
```

### REST Endpoint Registration

All routes are registered in `REST_Controller::register_routes()` (class-rest-controller.php:83). The REST namespace constant is `gk-block-api/v1` (line 30).

## REST API Reference

### Discovery Endpoints

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| GET | `/block-types` | `get_block_types` | Registered block types with preference scores. Params: `namespace`, `category`, `preferred` (bool). |
| GET | `/block-types/{namespace}` | `get_block_types_by_namespace` | Filter block types by namespace (e.g., `core`, `filter`). |

### Read Endpoints

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| GET | `/posts/{id}/blocks` | `get_post_blocks` | Page blocks as structured JSON with paths. Params: `fields`, `search`, `block_name`, `render`. |
| GET | `/resolve` | `resolve_url` | Map a URL to post ID, type, title, and edit link. Param: `url`. |

### Write Endpoints

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| POST | `/posts/{id}/blocks` | `insert_blocks` | Insert blocks at a position. Params: `blocks[]`, `after`, `before`. |
| POST | `/posts/{id}/blocks/batch-update` | `update_blocks_batch` | Apply N independent updates atomically in ONE revision. Params: `updates[]` (each: `ref` XOR `flat_index`, `attributes?`, `innerHTML?`). All-or-nothing validation. Capped at `Block_CRUD::MAX_BATCH_SIZE` (50). |
| PATCH | `/posts/{id}/blocks/{index}` | `update_block` | Update a single block by flat index. Params: `attributes`, `innerHTML`. |
| DELETE | `/posts/{id}/blocks/{index}` | `delete_block` | Remove block(s) at index. Param: `count`. |
| PUT | `/posts/{id}/blocks` | `replace_all_blocks` | Full page rewrite. Param: `blocks[]`. Uses stricter `put` rate limit (2/min). |
| POST | `/posts` | `create_post` | (v1.2) Create a new post/page. Body: `title` (req), `post_type`, `status`, `content` xor `blocks`, `slug`, `parent`, `categories`, `tags`, `terms`, `excerpt`, `featured_media`, `date`, `menu_order`, `comment_status`, `ping_status`, `author`. |
| PATCH | `/posts/{id}` | `update_post` | (v1.2) Partial update of post metadata/status/terms. `status: trash` trashes; any non-trash status untrashes. Block content edits stay on per-block routes. |
| GET | `/terms` | `list_terms` | (v1.2) List taxonomy terms. Params: `taxonomy` (default `category`), `search`, `parent`, `hide_empty`, `per_page` (≤200), `page`, `orderby`, `order`, `include`, `slug`. |
| POST | `/media` | `upload_media` | (v1.2) Upload to media library. Multipart `file` field, `url` (sideload, 25 MB cap), or `data_base64` + `filename`. Plus `title`, `alt_text`, `caption`, `description`, `post_id`. Cap: `upload_files`. |

### Mutation Endpoint

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| POST | `/posts/{id}/mutate` | `mutate_block_tree` | Path-based mutation engine. Params: `op`, `path[]`, plus operation-specific params. |

**9 mutation operations** (class-rest-controller.php:401):

| Op | Required Params | Description |
|----|----------------|-------------|
| `update-attrs` | `path`, `attributes` | Merge attributes. Triggers auto-transform or safety warning. |
| `update-html` | `path`, `innerHTML` | Replace innerHTML. Preserves innerBlock placeholders. |
| `replace-block` | `path`, `block` | Replace entire block (supports `innerBlocks` in definition). |
| `remove-block` | `path` | Remove block at path. Warns on synced pattern removal. |
| `wrap-in-group` | `path`, `wrapper?` | Wrap target in a container (default `core/group`). |
| `unwrap-group` | `path` | Promote inner blocks to parent level. Fixes grandparent innerContent. |
| `insert-child` | `path`, `block`, `position?` | Insert a child block (`start`, `end`, or numeric index). |
| `duplicate` | `path` | Deep-clone block at path, insert as next sibling. |
| `move` | `path`, `before`/`destination`, `count?` | Move block(s) to new path. Adjusts indices for cross-level moves. |

### Pattern Endpoints

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| GET | `/patterns` | `get_patterns` | List synced/registered patterns. Params: `q`, `synced`, `min_score`, `category`, `limit`, `order_by`. |
| GET | `/patterns/search` | `search_patterns` | Search by keyword. Params: `q`, `limit`. |
| GET | `/patterns/{id}` | `get_pattern` | Single pattern with parsed blocks. |
| POST | `/posts/{id}/insert-pattern` | `insert_pattern` | Insert a pattern. Params: `pattern_id`, `after`, `before`, `synced` (bool: reference vs inline). |

### Utility Endpoints

| Method | Route | Handler | Description |
|--------|-------|---------|-------------|
| GET | `/site-usage` | `get_site_usage` | Block/pattern usage stats. Param: `refresh`. |

## Core Classes

### Block_CRUD
**File**: `includes/class-block-crud.php` (~2015 lines)

The mutation engine. Owns all read/write logic for post block content.

- **`get_blocks($post_id, $render)`** (line 73) — Parse post content, format blocks. When `render=true`, sets up post context, runs `render_block()` for dynamic blocks, expands shortcodes, and resolves synced pattern content.
- **`update_block()`** (line 133) — Flat-index single block update. Uses `flatten_blocks()` to map index to nested path. Internally delegates the merge → auto-transform → innerContent reconciliation to the private `apply_block_update_in_place()` helper, which is shared with `update_blocks_batch()`.
- **`update_blocks_batch()`** — Atomic N-update batch in ONE revision. Phase 1 validates every item (target resolution, payload presence, dual-storage gate, duplicate-target detection by canonical path) and aborts the whole call with `WP_Error('batch_validation_failed')` + itemized `errors` if anything fails. Phase 2 applies all updates in memory via `apply_block_update_in_place()`. Phase 3 saves once. Counts as one write against `RATE_LIMIT_WRITES`; size capped at `MAX_BATCH_SIZE` (50).
- **`insert_blocks()`** (line 216) — Validates block names against registry, enforces preference tiers (legacy = hard reject, avoid = warning), splices into block array.
- **`delete_blocks()`** (line 357) — Warns on synced pattern reference removal.
- **`replace_all_blocks()`** (line 444) — Full page rewrite. Uses `put` rate limit bucket.
- **`insert_pattern()`** (line 554) — Synced patterns insert as `core/block` ref; registered patterns are inlined.
- **`mutate_block_tree()`** (line 733) — The path-based mutation switch. Navigates `$blocks` by reference through `innerBlocks` arrays to reach the target, then applies the operation.
- **`auto_transform_html()`** (line 1392) — Automatically updates innerHTML when attributes change. 4 categories: tag name swaps (regex), HTML attribute transforms (`WP_HTML_Tag_Processor`), CSS inline style transforms, text content transforms.
- **`save_post_content()`** (line 1702) — Wraps `wp_update_post()`, captures before/after revision IDs.
- **`format_blocks()`** (line 1748) — Public method. Produces the response structure with `index`, `path`, `name`, `attributes`, `innerHTML`, `dynamic`, `section`, and optionally `rendered_html`, `rendered_text`, `innerHTML_rendered`, `pattern_ref`.

**Rate limits** (lines 30-37): 10 writes/min/post, 2 PUT/min/post. Stored as transients with 2-minute TTL. A single `update_blocks_batch()` call counts as ONE write regardless of N — this is intentional (one revision, one save) and is bounded by `MAX_BATCH_SIZE` (50) to prevent the exemption from being abused.

### REST_Controller
**File**: `includes/class-rest-controller.php` (~1167 lines)

Thin HTTP layer. Registers routes, sanitizes input, calls service methods, wraps results in `WP_REST_Response`. Catches all exceptions via `handle_error()` (line 478).

- **Permission callbacks**: `check_permissions()` (line 438) for reads, `check_edit_permissions()` (line 455) for writes. Both require `edit_posts`. Write endpoints also call `check_post_edit_permission()` (line 1034) per post.
- **`search_blocks()`** (line 1067) — Recursive search across block tree by text content and/or block name.
- **`filter_block_fields()`** (line 1110) — Sparse field selection for GET responses. Always preserves `innerBlocks` for tree structure.

### Preferences
**File**: `includes/class-preferences.php` (~345 lines)

Stored in WP option `gk_block_api_preferences`. Provides namespace-based scoring and a replacement map for legacy block migration.

- **Namespace scores** (line 46): `filter/` = 100, `core/` = 90, `gravityforms/` = 80, `gk-*` = 80 (wildcard), `stackable/` = 10, `ugb/` = 0, `jetpack/` = 0. Unknown = 30.
- **Score tiers** (line 307): >= 80 = preferred, >= 50 = acceptable, >= 10 = avoid, < 10 = legacy.
- **Replacement map** (line 70): Maps 20 legacy blocks (stackable/*, ugb/*) to their preferred replacements.
- **Pattern scoring** (line 199): Combines recency bonus (2026: +50, 2025: +30, 2024: +10), reference count multiplier (x5), and legacy penalty (-100) / no-legacy bonus (+20).
- **`update_preferences()`** (line 126) — Deep-merges sub-arrays for partial updates.

### Block_Safety
**File**: `includes/class-block-safety.php` (~132 lines)

Static block safety guard. Detects when attribute mutations on static blocks risk leaving rendered markup inconsistent.

- **`check_mutation()`** (line 66) — Returns warnings if render-affecting attributes change on a static block without accompanying innerHTML. Safe for dynamic blocks (always), editor-only attributes (`lock`, `className`, `align`, `fontSize`, etc.), or when new innerHTML is provided.
- **`is_dynamic_block()`** (line 109) — Checks `WP_Block_Type_Registry`. Unknown blocks are treated as dynamic (safe).

### Block_Registry
**File**: `includes/class-block-registry.php` (~196 lines)

Wraps `WP_Block_Type_Registry` with preference enrichment and usage data.

- **`get_block_types()`** (line 63) — Filters by namespace, category, preferred_only. Sorts by score descending, then alphabetically.
- **`format_block_type()`** (line 129) — Enriches with preference tier, usage count, replacement info, and reverse-lookup of blocks this one replaces.

### Pattern_Manager
**File**: `includes/class-pattern-manager.php` (~477 lines)

Manages both synced patterns (`wp_block` CPT) and registered patterns (`WP_Block_Patterns_Registry`).

- **`get_patterns()`** (line 57) — Unified query with filtering (search, synced/registered, min_score, category), sorting (score, usage, date, name), and limiting.
- **`format_synced_pattern()`** (line 250) — Includes reference count (cross-site search for `core/block` refs), legacy block detection, and preview HTML (first 500 chars).
- **`count_pattern_references()`** (line 398) — Direct `$wpdb` query searching published post_content for `<!-- wp:block {"ref":ID} /-->`.

### Block_Inventory
**File**: `includes/class-block-inventory.php` (~331 lines)

Site-wide block + pattern inventory cached in transient `gk_block_inventory` (1 hour TTL).

- **`get_stats()`** (line 54) — Returns `block_usage`, `namespace_totals`, `pattern_references`, `legacy_patterns`.
- **`build_stats()`** (line 107) — Scans all published posts of all public post types. Counts blocks recursively, tracks per-block post counts, and detects synced patterns with legacy blocks.

### Post_Manager (v1.2)
**File**: `includes/class-post-manager.php` (~696 lines)

Owns post lifecycle: create + update metadata/status/terms. Block-content edits stay on the per-block endpoints.

- **`create_post( $args )`** — required `title`, status enum (no `trash`), `future` requires future `date`, `content` xor `blocks`, parent must be hierarchical and not self, terms verified to exist in their taxonomy, `featured_media` must be image MIME (uses `wp_attachment_is_image()` with MIME fallback). Returns `{ id, post_type, status, title, slug, permalink, edit_link, before_revision_id: null, revision_id, warnings }`. On term-assignment failure, the inserted post is rolled back via `wp_delete_post` (orphan logged on rollback failure).
- **`update_post( $post_id, $args )`** — partial update. Status transitions: `trash` via `wp_trash_post`, untrash via `wp_untrash_post` (return value checked). Rejects `mixed_trash_payload` — `status: trash` cannot be combined with other fields. Validates `featured_media` BEFORE `wp_update_post` so partial state can't leak. Wraps core `WP_Error` from `wp_insert_post`/`wp_update_post` with HTTP 400 (core leaves status undefined → defaulted to 500 by REST infra).
- **Rate limiting**: shares the per-post writes bucket with the per-block tools — `Block_CRUD::check_rate_limit($post_id, 'write')` / `record_rate_limit`. 10 writes/min/post, 429 on overflow.
- **Block validation** delegates to `Block_CRUD::validate_block_def()` — single source of truth. Avoid-tier blocks emit warnings, legacy-tier hard-rejects.
- **Allow-list**: `default_allowed_post_types()` reads `gk_block_api_post_types_allowlist` option first. When unset, defaults to `post`, `page`, plus any post type with `show_in_rest: true`. Cleaned up in `uninstall.php`.

### Term_Manager (v1.2)
**File**: `includes/class-term-manager.php` (~107 lines)

Read-only term listing for taxonomy lookup. Capability: `edit_posts`.

- **`list_terms( $args )`** — wraps `get_terms()` + `wp_count_terms()`. Per-page max 200. Response: `{ taxonomy, total, page, per_page, terms[] }` where each term has `{ id, name, slug, description, parent, count, taxonomy, link }`. Validates `taxonomy` is registered.

### Media_Manager (v1.2)
**File**: `includes/class-media-manager.php` (~404 lines)

Three input modes with mode mutex enforced at request time:

- **Multipart** (`handle_multipart`) — `$_FILES[$args['file_field']]`. Pre-checks `wp_max_upload_size` and `wp_check_filetype_and_ext` before `media_handle_upload`; tmp file `@unlink`'d on early rejection.
- **URL sideload** (`handle_url`) — `wp_http_validate_url` + SSRF guard (see below) + 25 MB cap (`URL_DOWNLOAD_MAX_BYTES`). Timeout reduced from 300s default to 10s.
- **Base64** (`handle_base64`) — encoded length cap (`URL_DOWNLOAD_MAX_BYTES * 4 / 3`) BEFORE decode; decoded length cap (min of `wp_max_upload_size` and `URL_DOWNLOAD_MAX_BYTES`) before disk write; `file_put_contents` return checked.
- **Metadata** (`apply_metadata`): title/caption/description sanitized + assigned via `wp_update_post`; `alt_text` saved to `_wp_attachment_image_alt` meta.
- **Response shape**: `{ success, id, title, filename, url, source_url, mime_type, alt_text, caption, description, post_parent }` + image-only `{ width, height, sizes: { thumbnail, medium, large, full } }`.

**SSRF guard** (`guard_ssrf`): URL host is DNS-resolved via `dns_get_record` (with `gethostbyname` fallback). Reserved/private/link-local IPv4 ranges are rejected with `400 invalid_url` *before* `download_url()` runs:

| Range | Reason |
|---|---|
| `0.0.0.0/8` | "this network" |
| `10.0.0.0/8`, `172.16/12`, `192.168/16` | RFC1918 private |
| `127.0.0.0/8` | loopback |
| `169.254.0.0/16` | link-local — **AWS/GCP/Azure metadata** |
| `192.0.0.0/24` | IETF reserved |
| `198.18.0.0/15` | benchmark |
| `224.0.0.0/4` and beyond | multicast + reserved |

Block list is admin-extensible via the `gk_block_api_url_sideload_blocked_ranges` filter (returns array of `[start, end]` IPv4 dotted-string pairs). Hosts that fail to resolve are also rejected (paranoid default).

## Key Concepts

### Block Paths

Integer arrays that address blocks in the nested tree. `[0]` = first top-level block, `[0, 2]` = third child of first block, `[0, 2, 1]` = second child of that child. The mutate endpoint navigates via `$parent[$segment]['innerBlocks']` at each depth level (class-block-crud.php:762).

The GET endpoint returns both a flat `index` (sequential counter for PATCH/DELETE backwards compat) and a `path` array for the mutation endpoint.

Example block tree and paths:
```
[0]       core/group
[0, 0]      core/columns
[0, 0, 0]     core/column
[0, 0, 1]     core/column
[1]       core/paragraph
```

### Preference Scoring

Every block insertion or replacement checks the preference tier:
- **legacy** (score < 10): Hard reject with `WP_Error`. Suggests replacement.
- **avoid** (score 10-49): Allowed but returns a warning with `suggested_replacement`.
- **acceptable** (score 50-79): Silent pass.
- **preferred** (score 80+): Ideal choice.

The replacement map in Preferences maps specific legacy blocks to their preferred alternatives (e.g., `stackable/heading` to `core/heading`).

### Static Block Safety Guards

When `update-attrs` is used on a static block (one without a PHP render callback), the safety checker warns that changing render-affecting attributes without providing updated innerHTML may leave the saved markup stale. Editor-only attributes like `className`, `align`, `fontSize` are exempted because WordPress applies them at render time via block supports.

### Auto-Transform (WP_HTML_Tag_Processor)

The `auto_transform_html()` method (class-block-crud.php:1392) automatically keeps innerHTML in sync with attribute changes for known block types:

1. **Tag name swaps** (regex): `core/heading` level changes `<h2>` to `<h3>`, `core/list` ordered toggles `<ul>`/`<ol>`, `core/group` tagName changes wrapper element.
2. **HTML attribute transforms** (`WP_HTML_Tag_Processor`): `url`/`src`/`alt`/`preload` mapped to `href`/`src`/`alt`/`preload` on matching tags. Boolean attrs (`autoplay`, `loop`) on audio/video. `core/details` showContent toggles `open`.
3. **CSS inline style transforms**: `height`/`width` attributes update inline style properties.
4. **Text content transforms** (regex): `core/quote` citation updates `<cite>` text.

If auto-transform applies, the safety warning is suppressed. If it does not apply and the block is static, the safety warning fires.

### Render Mode

Pass `render=true` on `GET /posts/{id}/blocks` to get:
- `rendered_html` / `rendered_text` for dynamic blocks (via `render_block()`)
- `innerHTML_rendered` with shortcodes expanded (via `do_shortcode()`)
- `pattern_ref.blocks` with resolved synced pattern content
- `dynamic` boolean on every block

The handler sets up global `$post` context (class-block-crud.php:100) so post-specific shortcodes and template tags work correctly. It restores the original context afterward.

### Mixed Trash Payload Guard (v1.2)

`update_post` rejects calls that combine `status: 'trash'` with any other field — `mixed_trash_payload` 400. Trashing is a status-only operation; mixing fields used to silently mutate a trashed post's title/parent/etc. The guard makes the contract explicit: trash first, then update.

### Post-Type Allow-List (v1.2)

`POST /posts` accepts post types from a configurable allow-list, stored in the `gk_block_api_post_types_allowlist` WP option. When unset, defaults to `post`, `page`, plus any post type with `show_in_rest: true`. To restrict to docs-only post types:

```php
update_option( 'gk_block_api_post_types_allowlist', array( 'post', 'docs' ) );
```

The option is deleted in `uninstall.php`.

### Rate Limiting

Per-post, per-minute, stored as transients with 2-minute TTL (class-block-crud.php:1993-2013):
- **Writes** (POST/PATCH/DELETE on blocks; mutate; v1.2 `update_post`): 10/min/post (`RATE_LIMIT_WRITES`)
- **PUT** (full page rewrite): 2/min/post (`RATE_LIMIT_PUT`)

`Post_Manager::update_post` shares the writes bucket with the per-block tools, so mixing post-meta updates with block edits on the same post draws from the same budget. `create_post` and `upload_media` are not rate-limited in v1.2 (capability gates are sufficient).

Returns HTTP 429 `rate_limit_exceeded` when exceeded.

### Revision Tracking

Every write operation calls `save_post_content()` (class-block-crud.php:1702) which:
1. Captures the latest revision ID as `before_revision_id`
2. Calls `wp_update_post()` (WordPress auto-creates a revision)
3. Captures the new latest revision as `revision_id`

Both IDs are returned in every write response for rollback support.

### Write Operation Lifecycle

Every write method follows the same sequence (class-block-crud.php):

1. **Rate limit check** — `check_rate_limit($post_id, $type)` reads the per-post transient, counts operations in the rolling 1-minute window.
2. **Post lookup** — `get_post($post_id)`, returns 404 if missing.
3. **Parse** — `parse_blocks($post->post_content)` to get the block tree.
4. **Validate** — Block names checked against `WP_Block_Type_Registry::is_registered()`. Preference tier checked: `legacy` = hard error, `avoid` = warning.
5. **Mutate** — Modify the `$blocks` array by reference (merge attrs, splice, etc.).
6. **Serialize** — `serialize_blocks($blocks)` converts back to block comment markup.
7. **Save** — `save_post_content()` calls `wp_update_post()`, captures before/after revision IDs.
8. **Record** — `record_rate_limit()` appends timestamp to the transient.
9. **Respond** — Return `{ success, block, warnings, before_revision_id, revision_id }`.

### Response Shape (Write Operations)

All write endpoints return this structure (with operation-specific additions):

```json
{
  "success": true,
  "block": { "name": "core/heading", "attributes": { "level": 3 } },
  "warnings": [],
  "before_revision_id": 456,
  "revision_id": 789
}
```

Warnings are arrays of `{ type?, block?, message, suggested_replacement? }`. The `before_revision_id` points to the revision snapshot before the edit, enabling diff or rollback via `wp_restore_post_revision()`.

### Response Shape (Read — format_blocks)

Each block in the GET response includes:

```json
{
  "index": 0,
  "path": [0],
  "name": "core/group",
  "attributes": { "tagName": "section" },
  "section": "Hero Section",
  "dynamic": false,
  "innerHTML": "<section class=\"wp-block-group\">...</section>",
  "innerBlocks": [ ... ]
}
```

With `render=true`, dynamic blocks also include `rendered_html` and `rendered_text`. Blocks with shortcodes include `innerHTML_rendered`. Synced pattern references include `pattern_ref` with `id`, `name`, and optionally `blocks`.

### WordPress Storage Model

Block content is stored as HTML comments in `post_content`:
```html
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Title</h3>
<!-- /wp:heading -->
```

`parse_blocks()` converts this to the nested array structure the plugin operates on. `serialize_blocks()` converts back. The plugin never manipulates raw post_content HTML — it always round-trips through parse/serialize.

## Extension Patterns

### Adding a New REST Endpoint

1. Add a `register_rest_route()` call in `REST_Controller::register_routes()`.
2. Create the callback method on REST_Controller. Wrap body in try/catch, call `$this->handle_error()` on exception.
3. Use `check_permissions` for reads, `check_edit_permissions` for writes. For per-post checks, call `$this->check_post_edit_permission($post_id)` inside the callback.

### Adding a New Mutation Operation

1. Add the operation name to the `enum` array in the `/mutate` route registration (class-rest-controller.php:401).
2. Add a `case` in the `switch ($op)` block in `Block_CRUD::mutate_block_tree()` (starts at line 801).
3. Work with `$parent[$target_index]` by reference. Set `$result_block` with at least `name` and `attributes`.
4. If the operation changes sibling count, update the grandparent's `innerContent` null placeholders (see `unwrap-group` and `duplicate` for examples).

### Adding a New Auto-Transform Rule

Add to `Block_CRUD::auto_transform_html()` (line 1392). Choose the appropriate category:
- **Tag name swap**: Use regex (WP_HTML_Tag_Processor cannot change tag names).
- **HTML attribute**: Add to the `$attr_map` array (line 1446) or the `$bool_attrs` array (line 1500).
- **CSS inline style**: Add to `$css_prop_map` (line 1539).
- **Text content**: Use `preg_replace_callback` (not `preg_replace`) to avoid backreference injection.

Always return `null` if the transform does not apply — this signals the safety checker to evaluate the mutation.

### Modifying Preference Scores

Call `PUT /preferences` or use `Preferences::update_preferences()` with a partial array. Deep-merges sub-keys:

```php
$preferences->update_preferences([
    'namespace_scores' => ['my-plugin' => 75],
    'replacement_map'  => ['old/block' => 'new/block'],
]);
```

### Hooks & Filters

The plugin does not define custom WordPress hooks/filters. It relies on:
- `rest_api_init` — plugin initialization (gk-block-api.php:54)
- `wp_update_post()` — triggers WordPress revision creation
- `WP_Block_Type_Registry` — block type discovery
- `WP_Block_Patterns_Registry` — pattern discovery
- `wp_kses_post()` — innerHTML sanitization on all write paths

### Data Storage

| What | Where | TTL |
|------|-------|-----|
| Preference config | WP option `gk_block_api_preferences` | Permanent |
| Post-type allow-list (v1.2) | WP option `gk_block_api_post_types_allowlist` | Permanent |
| Block inventory cache | Transient `gk_block_inventory` | 1 hour |
| Rate limit counters | Transient `gk_block_api_rate_{post_id}` | 2 minutes |

Both options and the usage transient are cleaned on uninstall. Rate-limit transients expire naturally.

## Public-facing language

Do not call out specific third-party block types or namespaces as "legacy" in code comments, docblocks, error messages, REST responses, readme entries, or changelog text. The legacy tier is *site-configurable* via `Preferences::get_defaults()['namespace_scores']` — naming concrete namespaces hardcodes a value that lives in config and turns a policy decision into a public callout of a specific vendor.

Use generic phrasing instead:

| Avoid | Prefer |
|---|---|
| `// (example/*, legacy/*) is known-legacy` | `// the namespace is configured as legacy` |
| `legacy-namespace blocks (example/*, legacy/*) now …` (changelog) | `blocks whose namespace is configured as legacy now …` |
| Test fixture: hardcoded `'example/never-installed'` | Resolve a legacy namespace from `Preferences::get_defaults()` and build the block name at runtime |

Test code is the one place where a concrete namespace name appears in *fixture data* — but the surrounding docblock and assertion messages must still use generic language. See `BlockCrudTest::test_insert_blocks_legacy_namespace_rejects_as_legacy_even_when_not_registered()` for the canonical pattern: it picks the first legacy-tier namespace out of the default config at runtime instead of hardcoding one.

## Conventions

- **Regression test required for every bug fix.** When a bug is found, create a test that fully exercises every aspect of it: write it so it FAILS against the buggy code (reproduces the real symptom), make it pass with the fix, and confirm it has teeth by reverting the fix and watching it go red. Exercise the real mechanism (the live `authenticate` chain / a real `WP_REST_Request`, not just a direct method call), and cover every facet the bug touched (each capability/post-type, single-site and multisite, API and interactive). See the repo-root `AGENTS.md` → "Regression tests are mandatory" and `tests/Connect/AgentAuthTest.php` / `AgentRestCapabilityTest.php`.
- All classes use the `GravityKit\BlockAPI` namespace.
- Class files follow `class-{lowercased-underscored-name}.php` naming.
- Write operations always: check rate limit, validate post exists, validate block names against registry, check preference tiers, create revision, record rate limit.
- innerHTML is sanitized through `wp_kses_post()` on all write paths.
- Block names are validated against `WP_Block_Type_Registry::is_registered()` before insertion.
- Empty/whitespace-only blocks (null `blockName`) are filtered out of all responses and insertion arrays.
- The `innerContent` array uses `null` entries as placeholders for inner blocks. All mutations that change child count must maintain this invariant.

## Gotchas

- **Flat index vs path**: PATCH and DELETE use a flat sequential index (from `format_blocks`'s counter). The mutate endpoint uses a path array of raw `parse_blocks()` indices. These are NOT the same numbering — the flat index skips empty blocks, while path indices correspond to the raw parsed array positions including empty blocks. Always use `path` from the GET response when calling `/mutate`.
- **innerContent null invariant**: Container blocks store `innerContent` as `['<div>', null, null, '</div>']` where each `null` is a child block position. If you add/remove children without updating this array, `serialize_blocks()` will produce corrupt HTML. The `insert-child`, `duplicate`, `unwrap-group`, and `move` operations all handle this.
- **Auto-transform suppresses safety warnings**: If `auto_transform_html()` returns non-null, the safety checker is skipped entirely. If a new attribute affects rendering but is not covered by auto-transform, it will silently produce stale markup without warning.
- **Rate limit scope**: Limits are per-post, not per-user. Multiple agents editing the same post share the same budget.
- **Pattern insert mode**: When `synced=true`, inserts a `core/block` reference (changes propagate). When `synced=false`, inlines the pattern's blocks as independent copies. Registered patterns (non-CPT) are always inlined regardless of the `synced` flag.
- **Render mode global state**: `get_blocks()` temporarily sets `$GLOBALS['post']` and calls `setup_postdata()` when `render=true`. It restores the original, but nested REST calls during the same request could see unexpected post context.
- **Uninstall**: `uninstall.php` deletes the `gk_block_api_preferences` option and `gk_block_inventory` transient. Rate limit transients (`gk_block_api_rate_{post_id}`) are not cleaned — they expire naturally (2 min TTL).
- **Block_Inventory scans all content**: `build_stats()` queries ALL published posts across ALL public post types with `posts_per_page => -1`. On large sites this can be slow. Results are cached for 1 hour, but a `refresh=true` call triggers a full rescan.
- **wp_kses_post strips some HTML**: All innerHTML passes through `wp_kses_post()`, which strips script tags, event handlers, and other disallowed content. This is intentional for security but may surprise agents trying to insert embeds or custom scripts.
- **Move destination adjustment**: The `move` operation adjusts destination indices to account for the source block being removed first (class-block-crud.php:1260-1301). This handles both same-parent and cross-level moves, but the logic is complex — test moves carefully, especially when count > 1.
- **Preference enforcement is insert-only**: Legacy/avoid checks run on `insert_blocks`, `rewrite_post_blocks`, `replace-block`, `insert-child`, and `wrap-in-group`. They do NOT run on `update-attrs` or `update-html` — you can update attributes on a legacy block that already exists on the page.
