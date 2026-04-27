# Docs Lifecycle Tools (v1.2)

> Adds four MCP tools so block-mcp owns the full author-to-publish docs lifecycle on its own: discovery → create → compose → upload media → reference media → iterate → edit metadata → publish/trash → revert.

**Status:** Draft, scoped 2026-04-27
**Target version:** `@gravitykit/block-mcp` 1.2.0 / `gk-block-api` 1.2.0
**Predecessor:** `SPEC.md` (v1.0–v1.1 — block-only CRUD)

---

## 1. Goal

Close the gaps in stages 2, 4, 7, 8, 9 of the docs lifecycle without expanding into adjacent surfaces (Yoast SEO meta, Redirection plugin, term creation, comments, users, plugin lifecycle).

| Stage | Today | After v1.2 |
|---|---|---|
| 1. Discovery | `find_posts`, `resolve_url`, `post_info` | + `list_terms` for category IDs |
| 2. Create empty post | ✗ | `create_post` |
| 3. Compose body | ✓ existing block tools | unchanged |
| 4. Upload screenshots | ✗ | `upload_media` |
| 5. Reference media | ✓ `insert_blocks` | unchanged |
| 6. Read / iterate | ✓ existing block tools | unchanged |
| 7. Edit metadata later | ✗ | `update_post` |
| 8. Publish | ✗ | `update_post` (`status: "publish"`) |
| 9. Trash | ✗ | `update_post` (`status: "trash"`) |
| 10. Revert | ✓ `revert_to_revision` | unchanged |

## 2. Out of scope

- **Yoast SEO metadata** — handled by the existing `yoast-seo` MCP.
- **Redirects** — Redirection plugin's domain.
- **Term creation/edit/delete** — admin task, do in wp-admin.
- **Comments / users / plugins / themes / options** — outside docs workflow.

## 3. New REST endpoints

All under `gk-block-api/v1`, same auth (Application Passwords + Basic Auth) and same error envelope (`handle_error()`) as existing endpoints.

### 3.1 `POST /posts` — create_post

**Purpose:** Create a new post or page in a chosen status, optionally with initial content (HTML or structured blocks), category, parent, slug, excerpt, featured image.

**Capability:** `current_user_can( get_post_type_object( $post_type )->cap->create_posts )`. Falls back to `edit_posts` cap when post type's cap object is missing.

**Request body (JSON):**
```json
{
  "title": "Getting Started with GravityView",
  "post_type": "post",
  "status": "draft",
  "content": "<!-- wp:paragraph -->...<!-- /wp:paragraph -->",
  "blocks": [{ "name": "core/paragraph", "attributes": { "content": "Hi" } }],
  "slug": "getting-started",
  "parent": 0,
  "excerpt": "Quick intro to GravityView.",
  "featured_media": 0,
  "categories": [12, 34],
  "tags": [56],
  "terms": { "doc_section": [78] },
  "date": "2026-04-27T10:00:00",
  "menu_order": 0,
  "comment_status": "closed",
  "ping_status": "closed",
  "author": 1
}
```

**Field rules:**
- `title` is **required** and non-empty (after `sanitize_text_field`).
- `post_type` defaults to `post`. Must be a registered, public-or-private post type with `show_in_rest: true` OR present in an explicit allow-list option `gk_block_api_post_types_allowlist` (default: `['post', 'page']` plus any post type whose `show_in_rest` is true).
- `status` defaults to `draft`. Allowed: `draft`, `pending`, `private`, `publish`, `future`. (`trash` rejected here — use `update_post`.) `future` requires a `date` in the future.
- `content` and `blocks` are mutually exclusive. If `blocks`, each is validated against `WP_Block_Type_Registry::is_registered()` and preference tier (legacy = hard reject, avoid = warning) using the existing `Block_CRUD::validate_block_for_insert()` path. The plugin serializes via `serialize_blocks()`.
- `slug` is sanitized via `sanitize_title()`. WordPress will append `-2` etc. on collision; we surface the **actual** saved slug in the response.
- `parent` accepts `0` for none. Validated as an existing post of a hierarchical type; otherwise `WP_Error` `invalid_parent`.
- `categories`, `tags` accept arrays of term IDs. The plugin verifies each term exists in its taxonomy; unknown IDs are rejected with `WP_Error` `invalid_term`.
- `terms` is a generic map for non-built-in taxonomies on custom post types: `{ taxonomy_slug: [term_ids] }`. Same existence validation.
- `featured_media` is an attachment ID. Verified via `wp_attachment_is_image()` OR mime-type starts with `image/`. Otherwise `WP_Error` `invalid_featured_media`.
- `author` defaults to current user. Setting another user requires `edit_others_posts` cap on that post type.
- `comment_status`, `ping_status` accept `open` or `closed`.

**Response (200):**
```json
{
  "success": true,
  "id": 1234,
  "post_type": "post",
  "status": "draft",
  "title": "Getting Started with GravityView",
  "slug": "getting-started",
  "permalink": "https://example.com/?p=1234",
  "edit_link": "https://example.com/wp-admin/post.php?post=1234&action=edit",
  "before_revision_id": null,
  "revision_id": 1235,
  "warnings": []
}
```

**Errors:**
- `400 missing_title`, `invalid_post_type`, `invalid_status`, `invalid_parent`, `invalid_term`, `invalid_featured_media`, `legacy_block` (avoid block warnings appear in `warnings[]`, not as errors).
- `403 rest_cannot_create` — capability denied.
- `500 wp_insert_post_failed` — wraps `is_wp_error( $id )` from core.

### 3.2 `PATCH /posts/{id}` — update_post

**Purpose:** Partial update of post metadata or status. Block content edits stay on the existing per-block endpoints; this is **metadata-only** plus optional full-content replacement.

**Capability:** `current_user_can( 'edit_post', $id )`. Status transitions to `publish` additionally require `current_user_can( 'publish_posts' )` (or post-type-specific equivalent).

**Path:** `/posts/{id}` where `{id}` is `\d+`.

**Note on route conflict:** Existing routes include `PATCH /posts/{id}/blocks/{index}` (update_block). This new route has a more specific suffix-less path; WP route registration is order-sensitive and the more-specific block-index route already wins. We register the new bare route after the existing block routes to avoid any precedence ambiguity, and we test that PATCH `/posts/123/blocks/0` still hits update_block.

**Request body (JSON):**
```json
{
  "title": "New Title",
  "status": "publish",
  "slug": "new-slug",
  "parent": 999,
  "excerpt": "Updated excerpt",
  "featured_media": 0,
  "categories": [12],
  "tags": [],
  "terms": { "doc_section": [78] },
  "date": "2026-05-01T10:00:00",
  "menu_order": 5,
  "comment_status": "open",
  "ping_status": "closed",
  "author": 2
}
```

**Field rules:**
- All fields optional. Only non-`null`, non-`undefined` keys are touched. To explicitly clear `featured_media`, send `0`. To clear `tags`/`categories`, send `[]`.
- `status` allowed: `draft`, `pending`, `private`, `publish`, `future`, `trash`. Trashing uses `wp_trash_post()` so trash hooks fire correctly. Untrashing (`trash` → anything else) uses `wp_untrash_post()` then sets the desired status.
- `slug` collision: same as create_post — WordPress appends suffix; response carries the saved slug.
- `parent` cannot equal the post's own ID (would create cycle); validated.
- `terms`/`categories`/`tags` semantics identical to create_post.
- Rate limited via existing per-post writes bucket (`RATE_LIMIT_WRITES`, 10/min).

**Response (200):**
```json
{
  "success": true,
  "id": 1234,
  "post_type": "post",
  "status": "publish",
  "title": "New Title",
  "slug": "new-slug",
  "permalink": "https://example.com/2026/05/01/new-slug/",
  "edit_link": "...",
  "transitioned_to_publish": true,
  "before_revision_id": 1235,
  "revision_id": 1240,
  "warnings": []
}
```

**Errors:**
- `400 invalid_status`, `invalid_parent`, `invalid_term`, `invalid_featured_media`, `cycle_parent`.
- `403 rest_cannot_edit`, `rest_cannot_publish`.
- `404 post_not_found`.
- `429 rate_limited`.

### 3.3 `GET /terms` — list_terms

**Purpose:** Find term IDs for a given taxonomy. Read-only.

**Capability:** `edit_posts`.

**Query parameters:**
| Name | Type | Default | Notes |
|---|---|---|---|
| `taxonomy` | string | `category` | Must be registered. |
| `search` | string | — | LIKE match against `name`. |
| `parent` | int | — | Filter by parent term ID. |
| `hide_empty` | bool | `false` | Pass-through to `get_terms()`. |
| `per_page` | int | `100` | Max `200`. |
| `page` | int | `1` | 1-indexed. |
| `orderby` | enum | `name` | `name`, `count`, `term_id`, `slug`. |
| `order` | enum | `asc` | `asc`, `desc`. |
| `include` | int[] | — | Specific term IDs. |
| `slug` | string | — | Exact slug match. |

**Response (200):**
```json
{
  "taxonomy": "category",
  "total": 42,
  "page": 1,
  "per_page": 100,
  "terms": [
    {
      "id": 12,
      "name": "Documentation",
      "slug": "documentation",
      "description": "User-facing docs",
      "parent": 0,
      "count": 28,
      "taxonomy": "category",
      "link": "https://example.com/category/documentation/"
    }
  ]
}
```

**Errors:**
- `400 invalid_taxonomy` — taxonomy not registered.

### 3.4 `POST /media` — upload_media

**Purpose:** Add an item to the WP media library and return the attachment ID + URL so an agent can reference it in a `core/image` block.

**Capability:** `upload_files`.

**Three input modes** (mutually exclusive — exactly one required):
1. **Multipart form-data** — `Content-Type: multipart/form-data`, file under field `file`, plus regular form fields for `title`, `alt_text`, `caption`, `description`, `post_id`, `filename`.
2. **URL sideload** — JSON body with `url` set to a publicly-fetchable URL. Server downloads via `wp_safe_remote_get()` (with size cap) and writes to uploads.
3. **Base64 inline** — JSON body with `data_base64` plus `filename` (required) and optional `mime_type`. Decoded server-side.

**Common metadata fields (any mode):**
- `title` — defaults to filename without extension.
- `alt_text` — stored in `_wp_attachment_image_alt` meta (critical for accessibility).
- `caption` — sets `post_excerpt`.
- `description` — sets `post_content`.
- `post_id` — attaches to a parent post (sets `post_parent`).
- `filename` — overrides the inferred filename.

**Validation:**
- File size limit: `wp_max_upload_size()` for multipart and base64; URL sideload uses a configurable cap (default 25 MB) to prevent runaway downloads.
- MIME type: passed through `wp_check_filetype_and_ext()`. Disallowed types are rejected (uses WP's `upload_mimes` filter chain — agents can lean on whatever the site already permits).
- URL sideload: the URL must pass `wp_http_validate_url()` and resolve to an `http(s)` scheme.

**Response (200):**
```json
{
  "success": true,
  "id": 5678,
  "title": "Screenshot 2026-04-27",
  "filename": "screenshot-2026-04-27.png",
  "url": "https://example.com/wp-content/uploads/2026/04/screenshot-2026-04-27.png",
  "source_url": "https://example.com/wp-content/uploads/2026/04/screenshot-2026-04-27.png",
  "mime_type": "image/png",
  "alt_text": "Filtering view results",
  "width": 1920,
  "height": 1080,
  "sizes": {
    "thumbnail": { "url": "...", "width": 150, "height": 150 },
    "medium":    { "url": "...", "width": 300, "height": 169 },
    "large":     { "url": "...", "width": 1024, "height": 576 },
    "full":      { "url": "...", "width": 1920, "height": 1080 }
  },
  "post_parent": 1234
}
```

`width` / `height` / `sizes` are present only for image attachments.

**Errors:**
- `400 missing_file`, `invalid_filename`, `invalid_url`, `invalid_base64`, `disallowed_mime`, `file_too_large`.
- `403 rest_cannot_upload`.
- `502 url_fetch_failed` — sideload network/HTTP error.
- `500 sideload_failed` — `media_handle_sideload()` error.

## 4. New MCP tools (TypeScript)

Tool naming follows existing conventions: lowercased verbs, snake_case nouns. JSON Schema validated by the SDK before dispatch; the handler does additional cross-field validation.

### 4.1 `create_post`
Input schema mirrors `POST /posts` body. Required: `title`. Mutually-exclusive client-side check: `content` xor `blocks`.

### 4.2 `update_post`
Input: `post_id` (required) + same fields as `PATCH /posts/{id}` body. At least one mutating field must be provided (else returns a hint without a network call).

### 4.3 `list_terms`
Input mirrors query parameters. `taxonomy` defaults to `category`.

### 4.4 `upload_media`
Input modes:
- `path` — local filesystem path on the MCP host. The TS layer reads the file, derives MIME from extension, and POSTs as multipart.
- `url` — server-side sideload.
- `data_base64` + `filename` — pass-through.

Plus shared metadata fields. Exactly one of `path`/`url`/`data_base64` is required (validated client-side before HTTP).

## 5. PHP class additions

```
includes/
├── class-post-manager.php   (NEW) — create_post, update_post
├── class-term-manager.php   (NEW) — list_terms
├── class-media-manager.php  (NEW) — upload_media (multipart, sideload, base64)
└── class-rest-controller.php (extended with 4 new routes + handlers)
```

Bootstrap (`gk-block-api.php`) wires:
```php
$post_manager  = new Post_Manager( $preferences, $block_crud );
$term_manager  = new Term_Manager();
$media_manager = new Media_Manager();
$controller = new REST_Controller(
    $block_registry, $pattern_manager, $block_crud, $usage_stats, $block_mutator,
    $post_manager, $term_manager, $media_manager
);
```

`Post_Manager` reuses `Block_CRUD` for the `blocks → serialized content` path, including preference enforcement. `Term_Manager` is a thin wrapper around `get_terms()` + `wp_count_terms()`. `Media_Manager` wraps `media_handle_sideload()` (multipart and base64 paths write a temp file first; URL path uses `wp_get_image_editor()` only for size metadata).

## 6. TS module additions

```
src/
├── tools/
│   ├── posts.ts    (NEW) — create_post + update_post tool defs and handler
│   ├── terms.ts    (NEW) — list_terms tool def and handler
│   └── media.ts    (NEW) — upload_media tool def and handler
├── client.ts       (extended) — createPost(), updatePost(), listTerms(), uploadMedia()
├── types.ts        (extended) — request/response types for above
└── index.ts        (extended) — wire the three new tool sets
```

Multipart in `client.ts` uses Node's built-in `FormData` (Node >= 18) and `Blob`. axios accepts `FormData` natively for the request body and sets `Content-Type` correctly.

## 7. Auth, rate limits, sanitization

- Application Password basic auth, same as today.
- Rate limits:
  - `update_post` — uses existing per-post writes bucket (10/min).
  - `create_post`, `upload_media`, `list_terms` — no additional rate limit in v1.2 (caps are sufficient).
- Sanitization:
  - All strings via `sanitize_text_field()`; HTML content via `wp_kses_post()`.
  - Slugs via `sanitize_title()`.
  - Term IDs cast via `absint()`.
  - URLs validated via `wp_http_validate_url()`.
  - Filenames via `sanitize_file_name()`.

## 8. Test plan

### 8.1 PHP (PHPUnit)
- `PostManagerTest.php` — create with defaults, with blocks, with categories/tags, with parent, status transitions (draft→publish, →trash, →untrash), invalid post type, invalid term, slug collision, capability denial, partial update, cycle parent, status `publish` requires publish cap.
- `TermManagerTest.php` — default taxonomy, custom taxonomy, search filter, parent filter, pagination, invalid taxonomy.
- `MediaManagerTest.php` — multipart happy path (using `WP_UnitTest_Factory` test image), base64 happy path, URL sideload (mock `pre_http_request`), disallowed mime, oversize file, invalid base64, missing inputs.
- `RestSummaryTest.php` (extended) — assert all four new routes are registered with expected methods and arg schemas.

### 8.2 TypeScript (Vitest)
- `posts.test.ts` — tool schema validation (missing title, content+blocks mutex), handler dispatches correct client method, response wraps with `success`.
- `terms.test.ts` — taxonomy default, query param mapping, error path.
- `media.test.ts` — `path`/`url`/`data_base64` mutex, MIME inference, multipart construction (mocked axios).

### 8.3 End-to-end on gkclone
gkclone runs via wp-env at `http://localhost:7701`. Plugin path: `synced/plugins/gk-block-api/` (mapped to wp-content/plugins).

Smoke script (`scripts/e2e-gkclone.mjs`, run manually) exercises:
1. `resolve_url('/sample-page/')` → confirm fixture.
2. `list_terms({ taxonomy: 'category', search: 'uncategorized' })` → grab Uncategorized ID.
3. `create_post({ title: 'block-mcp e2e <ts>', status: 'draft', categories: [<id>], blocks: [{ name: 'core/heading', attributes: { content: 'E2E', level: 2 } }] })` → capture post ID.
4. `upload_media({ path: '/path/to/fixture.png', alt_text: 'fixture', post_id: <id> })` → capture attachment ID.
5. `insert_blocks({ post_id, after: 0, blocks: [{ name: 'core/image', attributes: { id: <atch>, url: <url>, alt: 'fixture' } }] })`.
6. `get_page_blocks({ post_id })` → assert image block present.
7. `update_post({ post_id, status: 'publish' })` → assert permalink resolves (HEAD request, expect 200).
8. `update_post({ post_id, status: 'trash' })` → assert permalink 404.
9. `update_post({ post_id, status: 'draft' })` → untrash, assert.
10. Final cleanup: `update_post({ post_id, status: 'trash' })` and delete the uploaded media via WP-CLI inside wp-env.

Smoke script asserts each step with descriptive failure messages. Exits non-zero on any failure.

## 9. Versioning, docs, release

- Bump `gk-block-api` and `@gravitykit/block-mcp` to `1.2.0`.
- Update `README.md` MCP Tools table and Features section.
- Update `wordpress-plugin/AGENTS.md` and `MCPs/block-mcp/AGENTS.md` REST tables and class inventory.
- Add `gk_block_api_post_types_allowlist` option to `uninstall.php` cleanup.
- Add a "Docs lifecycle" example to `README.md` showing a 4-step author flow.

## 10. Open questions (resolved)

1. **Why not just use `/wp/v2/`?** — Consistency: agents see one base URL. Also lets us enforce block preference rules on `create_post`'s `blocks` input via the same `Block_CRUD` path used by `insert_blocks`.
2. **Do we need a `delete_post`?** — No. `update_post` with `status: trash` covers the docs flow. Hard delete is a destructive admin action and stays in wp-admin.
3. **Should `create_post` accept Yoast meta?** — No. The yoast-seo MCP owns that. We could add a thin pass-through later if friction warrants.
4. **Should `upload_media` resize / optimize?** — No. WordPress generates intermediate sizes via `wp_generate_attachment_metadata()` for free. Optimization (Imagify, ShortPixel) is a separate concern.
