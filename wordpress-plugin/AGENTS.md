# AGENTS.md — GK Block API WordPress Plugin

> REST API for block-level WordPress CRUD (preference scoring, path-based mutations, static-block safety) **plus** the Connect onboarding flow that provisions a dedicated least-privilege agent account and delivers a credential to an MCP client out-of-band. This is the deep plugin reference; the repo-root `../AGENTS.md` is the architecture + connect overview.

## Quick Start

- **Namespace:** `GravityKit\BlockMCP` · **REST prefix:** `gk-block-api/v1`
- **Auth:** WordPress Application Passwords (Basic Auth over HTTPS). Reads require `edit_posts`; per-block writes also check `edit_post` on the post. The connect exchange route is intentionally unauthenticated (single-use sealed code).
- **Entry point:** `gk-block-mcp.php` — `spl_autoload_register` (class → `includes/class-{name}.php`); `init_rest_api()` on `rest_api_init`; admin/CLI wiring on `plugins_loaded`. No global singletons — services are built inside the hooks.
- **PHP:** 7.4+ · **WordPress:** 6.0+
- **Quality gates (every commit):** `composer lint` (phpcs: WordPress-Extra + WordPress-Docs + PHPCompatibilityWP @ `testVersion 7.4-`) → 0/0; `composer analyze` (PHPStan level 5, PHP 8.2, WP stubs) → [OK]; `composer test` (PHPUnit + SQLite drop-in) → green.

## File Inventory

```text
gk-block-mcp/
├── gk-block-mcp.php              # Bootstrap: autoloader, rest_api_init + admin wiring, CLI
├── uninstall.php                 # Full data + agent teardown (multisite-aware)
├── readme.txt                    # Canonical changelog
├── phpcs.xml.dist / phpstan.neon.dist / phpstan-bootstrap.php   # Static-analysis config
└── includes/                     # (line counts approximate)
    ── Block engine (the CRUD facade + its parts) ─────────────────────────
    ├── class-block-crud.php       # Facade over Reader/Writer/Mutator (~1100)
    ├── class-block-reader.php     # get_blocks / format_blocks / sourced attrs (~825)
    ├── class-block-writer.php     # insert/update/delete/replace/insert_pattern/save (~1930)
    ├── class-block-mutator.php    # 9-op path-based mutation engine (~970)
    ├── class-html-transformer.php # Auto-transform innerHTML on attr change (~475)
    ├── class-block-safety.php     # Static-block staleness guard (~130)
    ├── class-block-registry.php   # Block-type discovery + enrichment (~250)
    ├── class-pattern-manager.php  # Synced + registered pattern management (~655)
    ├── class-block-inventory.php  # Site-wide block/pattern inventory + storage-mode scan (~775)
    ├── class-preferences.php      # Namespace scoring, replacement map (~345)
    ── Post / term / media lifecycle (v1.2) ──────────────────────────────
    ├── class-post-manager.php     # create_post / update_post (+ trash toggle, explicit author arg) (~915)
    ├── class-term-manager.php     # list_terms (~175)
    ├── class-media-manager.php    # upload_media (multipart/URL/base64 + SSRF guard) (~680)
    ── HTTP + integrations ───────────────────────────────────────────────
    ├── class-rest-controller.php  # Route registration + HTTP layer (~2655)
    ├── class-instructions.php     # MCP instructions endpoint + addendum store (~260)
    ├── class-yoast-bridge.php     # Yoast SEO REST bridge (~590)
    ── Connect / onboarding ──────────────────────────────────────────────
    ├── class-connect-page.php     # Onboarding UI + admin-post handlers + exchange route (~2530)
    ├── class-agent-provisioner.php# Dedicated agent user + role + login block + purge (~405)
    ├── class-app-password-issuer.php # Mint an Application Password on a user (~75)
    ├── class-connections.php      # List/revoke connections + connection meta (~330)
    ├── class-mcpb-generator.php   # Claude Desktop .mcpb bundle generator (~255)
    └── class-settings-page.php    # Settings + Connect admin pages (~1280)
```

## Plugin Architecture

### Bootstrap & service graph

`gk-block-mcp.php` wires everything on three hooks:

1. **`init_rest_api()` on `rest_api_init`:**
   ```text
   Preferences → Block_Registry(+Block_Inventory)
   Preferences → Pattern_Manager
   Preferences + Block_Safety + HTML_Transformer + Block_Inventory → Block_CRUD
   {Registry, Pattern_Manager, Block_CRUD, Block_Inventory, Block_Mutator,
    Post_Manager, Term_Manager, Media_Manager, Preferences} → REST_Controller->register_routes()
   Yoast_Bridge->register_routes()
   Connect_Page->register_rest_routes()        # the /connect/exchange route
   ```
2. **Admin on `plugins_loaded`:** `Settings_Page(new Block_Inventory())->register()` (needs `admin_menu`, which fires before `admin_init`) and `Connect_Page->register()` (the `admin_post_*` handlers).
3. **WP-CLI:** an explicit bootstrap, because `rest_api_init` doesn't fire under `wp`.

### Block engine = a facade over three engines

`Block_CRUD` is a **facade**. Its constructor builds `Block_Reader` (reads/formatting) and `Block_Writer` (writes/save), and the REST controller also holds a `Block_Mutator` (path mutations). Callers go through `Block_CRUD`'s public methods or the controller; the three engines share state via the owning `Block_CRUD` instance (`build_block_from_def`, `assign_missing_refs_recursive`, rate-limit transients, revision tracking).

```text
REST_Controller
├── Block_CRUD (facade)
│   ├── Block_Reader   (Preferences, Block_Inventory)
│   └── Block_Writer   (Preferences, Block_Safety, HTML_Transformer)
├── Block_Mutator
├── Block_Registry → Preferences, Block_Inventory
├── Pattern_Manager → Preferences
├── Post_Manager → Block_CRUD
├── Term_Manager · Media_Manager · Preferences
```

## Connect / Onboarding

The 2.0 flow connects an AI client in a few clicks with no Application-Password copy-pasting and confines the AI to a dedicated least-privilege account.

### Classes
- **`Agent_Provisioner`** — `ensure()` provisions/returns the dedicated user `block-mcp` (`LOGIN`) with role `block_mcp_agent` (`ROLE`). Caps come through `gk/block-mcp/agent/caps`: `read`, `edit_posts`, `edit_others_posts`, `edit_published_posts`, `publish_posts`, the four `*_pages` equivalents, `upload_files` — **NO `delete_*`, NO `unfiltered_html`, NO `manage_options`, NO `manage_categories`**. `block_agent_login()` (on `authenticate`) blocks interactive sign-in (fail-closed). The role persists in `wp_user_roles` across deactivation; `purge()` (gated by `gk/block-mcp/agent/remove-on-uninstall`) removes the user + role + options. `gk/block-mcp/agent/role` lets an operator own the slug.
- **`App_Password_Issuer`** — `issue($user_id, $label)` mints an Application Password via core; returns the one-time plaintext + UUID (never persisted in the clear).
- **`Connections`** — `list($user_id)` / `list_self_hosted($agent_id)` enumerate Block-MCP-prefixed credentials (`NAME_PREFIX = 'Block MCP'`); `revoke()` / `revoke_by_uuid()` delete them (host resolved from meta); `record_meta()` / `get_meta()` / `forget_meta()` / `purge_all_recorded()` manage the network option `gk_block_api_connection_meta` (UUID → `{ user_id, created_by, created_at }`). No byline subsystem — `author_to_credit()` was removed in 2.0.
- **`MCPB_Generator`** — `manifest($creds)` (manifest_version `0.3`; each `user_config` option needs `type`+`title`+`description`) and `build($creds, $server_path)` (streams the `.mcpb` zip).
- **`Connect_Page`** — the onboarding UI + the handlers + the exchange route (methods below).

### Connect_Page surface

| Method | Role |
|---|---|
| `register()` | Hooks the four `admin_post_*` handlers (+ `_nopriv` exchange) |
| `register_rest_routes()` / `rest_exchange()` | `POST /connect/exchange` (permission `__return_true`) |
| `provision_credentials($client, $identity)` | Mint on agent or self per identity (`agent` / `self`; `self` clamps to `agent` when `gk/block-mcp/identity/allow-self` is false); record meta; return creds |
| `handle_connect()` (`ACTION_CONNECT`) | `.mcpb` download / setup-artifact path |
| `handle_authorize()` (`ACTION_AUTHORIZE`) | Browser-Approve: validate loopback callback, provision, redirect a single-use *code* |
| `handle_exchange()` (`ACTION_EXCHANGE` + `_nopriv`) | Redeem the code once → `{ success, data:{ site, user, password } }` |
| `handle_revoke()` / `do_revoke($uuid)` (`ACTION_REVOKE`) | Disconnect; resolve host from meta |
| `is_loopback_callback($url)` | Exact loopback host + numeric port + no userinfo; refuses `127.0.0.1.evil.com`, `localhost@evil.com` |
| `connection_state()` | `needs_https` / `connected` / `ready` |
| `render_section()` / `render_page()` | Connect tab; focused consent screen when `?gk_authorize` |
| `gc_records()` | Opportunistic sweep of expired sealed credential records |

### Identity model
`provision_credentials($client, $identity)` offers **two** identities: `agent` (mint on the dedicated `block-mcp` user, least-privilege, content authored by "Block MCP") and `self` (mint on the **approving user**, their full caps, content authored by them). The middle `agent_as_me` byline option was removed in 2.0 — there is no byline subsystem; `Post_Manager::create_post()` no longer remaps `post_author` from connection meta, and content authors as the authenticating account. An explicit `author` argument on create still sets authorship (gated on the actor's `edit_others_{type}`). The high-risk `self` mode is governed by the **`gk/block-mcp/identity/allow-self`** filter (default `true`): returning false removes the "Your own account" card from the Approve screen AND clamps any `self` request back to `agent`. A JS confirm-gate (acknowledgment checkbox) also disables Approve until the user accepts that `self` mints an Application Password with full account access (the server validates the identity independently).

### Credential at rest
The single-use exchange code + paste-mode password are sealed (AES-256-GCM, HKDF from `wp_salt('auth')`) into non-autoloaded options `gk_block_api_xchg_*` / `gk_block_api_paste_pw_*` with embedded `expires_at` + GC marker `gk_block_api_cred_gc_at`. Seal mode is filterable via `gk/block-mcp/credential/seal-mode`. The minted password must NEVER reach JS, URLs, browser history, or be POSTed off-origin.

## REST API Reference

All routes under `gk-block-api/v1`.

### Discovery / read

| Method | Route | Handler |
|---|---|---|
| GET | `/block-types` · `/block-types/{namespace}` | block types + preference scores |
| GET | `/posts/{id}/blocks` | page blocks (params: `fields`, `search`, `block_name`, `render`) |
| GET | `/resolve?url=` · `/post-info` · `/find-posts` | URL→post resolution, post metadata, post search |
| GET | `/patterns` · `/patterns/search` · `/patterns/{id}` | pattern listing/search/detail |
| GET | `/site-usage` | block/pattern usage stats |

### Write / mutation

| Method | Route | Handler |
|---|---|---|
| POST | `/posts/{id}/blocks` | `insert_blocks` |
| POST | `/posts/{id}/blocks/batch-update` | atomic N updates, one revision (cap `MAX_BATCH_SIZE`=50) |
| PATCH | `/posts/{id}/blocks/{index}` | `update_block` |
| DELETE | `/posts/{id}/blocks/{index}` | `delete_block` |
| PUT | `/posts/{id}/blocks` | `replace_all_blocks` (stricter `put` rate limit) |
| POST | `/posts/{id}/mutate` | 9-op path mutation |
| POST | `/posts/{id}/insert-pattern` | synced ref or inlined |
| POST | `/posts` · PATCH `/posts/{id}` | create / update (status, terms, explicit author arg) |
| GET | `/terms` · POST `/media` | term listing · media upload (SSRF-guarded) |
| POST | `/storage-modes/scan` | dual-storage classification scan (`manage_options`) |

### Integrations / connect

| Method | Route | Handler / notes |
|---|---|---|
| GET | `/instructions` | MCP instructions addendum — served **unauthenticated**, per-IP rate-limited |
| GET/POST/PATCH | `/yoast/{id}` · POST `/yoast/bulk` | `Yoast_Bridge` get/update/bulk SEO |
| POST | `/connect/exchange` | redeem single-use sealed code — **`__return_true`** (only unauthenticated write route, by design) |

## Core Classes

**`Block_Reader`** — `get_blocks($post_id, $render)`, `format_blocks()`. Parses `post_content`, formats with flat `index` (skips empties) + nested `path` (raw indices), render mode (`render_block`, shortcode expansion, synced-pattern resolution), and sourced-attribute extraction (`extract_sourced_attributes`).

**`Block_Writer`** — `update_block(s)`, `insert_blocks`, `delete_blocks`, `replace_all_blocks`, `insert_pattern`, `build_block_from_def`, `save_post_content` (revision before/after). Validates block names against `WP_Block_Type_Registry`; enforces preference tiers (legacy = HTTP 400, avoid = warning); rate-limited (10 writes/min, 2 PUT/min per post).

**`Block_Mutator`** — `mutate($blocks, $op, $path, $params)`: `update-attrs`, `update-html`, `replace-block`, `remove-block`, `wrap-in-group` (tagName allowlist → `<div>` fallback), `unwrap-group`, `insert-child`, `duplicate`, `move`. Navigates `$parent[$seg]['innerBlocks']`; maintains the `innerContent` null-placeholder invariant.

**`HTML_Transformer`** — `auto_transform_html()`: tag swaps (regex), HTML-attr/style transforms (`WP_HTML_Tag_Processor` — never pre-escape), text-content transforms. Returns `null` when nothing applies (safety warning fires).

**`Block_Safety`** — `check_mutation()`: warns when render-affecting attrs change on a static block without new innerHTML. Dynamic/unknown blocks + editor-only attrs are safe.

**`Block_Registry` / `Pattern_Manager` / `Block_Inventory` / `Preferences`** — discovery, scoring, inventory, and the namespace-score/replacement-map config (option `gk_block_api_preferences`). `Block_Inventory::scan_storage_modes()` classifies blocks as static/dynamic/dual.

**`Post_Manager`** — `create_post()` (title required, post-type allow-list, status enum, parent/term/featured validation, optional explicit `author` arg gated on `edit_others_{type}`), `update_post()` (status transitions via `wp_trash_post`/`wp_untrash_post`; `mixed_trash_payload` guard; **trash gated by `Post_Manager::trashing_enabled()` / option `gk_block_api_allow_trash`, filtered through `gk/block-mcp/post/allow-trash`**, default off). Shares the per-post write rate bucket.

**`Term_Manager`** — `list_terms()` (cap `edit_posts`, per-page ≤200). **`Media_Manager`** — `upload_media()` (multipart / URL sideload / base64; SSRF guard rejecting reserved/private/link-local IPs before `download_url()`; MIME allow-list; size caps; `gk/block-mcp/media/sideload-blocked-ranges` filter).

**`REST_Controller`** — registers every route; `check_permissions` (read) / `check_edit_permissions` + `check_post_edit_permission` (write); `handle_error()` envelope; sparse-field selection; recursive search.

## Key Concepts

- **Flat index vs path:** `index` skips empty blocks; `path` uses raw `parse_blocks()` indices. Use `path` for `/mutate`, `index`/`ref` for per-block writes.
- **`innerContent` null invariant:** one `null` per child; mutations changing child count must maintain it.
- **Preference tiers:** legacy (<10) hard-reject on insert/replace; avoid (10–49) warn; acceptable (50–79) silent; preferred (80+). Enforcement is insert-only.
- **Auto-transform suppresses the safety warning** when it applies; static blocks whose render-affecting attrs change without innerHTML and aren't covered will silently go stale.
- **Render mode** sets up `$GLOBALS['post']`/`setup_postdata` (restored after) so shortcodes/template tags resolve.
- **Rate limiting is per-post, not per-user** (transient `gk_block_api_rate_{post_id}`, 2-minute TTL, sliding window).
- **Mixed-trash guard:** `update_post` rejects `status:trash` combined with other fields (`mixed_trash_payload`); the trash *gate* (`trash_disabled` 403) fires first when the toggle is off.
- **Agent identity gate:** the agent has no `delete_*` cap, but `wp_trash_post()` checks none — so the option `gk_block_api_allow_trash` (filtered through `gk/block-mcp/post/allow-trash`) is the application-level gate. Assigning an explicit `author` other than the actor requires the actor's `edit_others_{type}`.

## Extension Patterns

- **New REST endpoint:** `register_rest_route()` in `REST_Controller::register_routes()` → handler (try/catch → `handle_error()`) → `check_permissions`/`check_edit_permissions` + `check_post_edit_permission`.
- **New mutation op:** add to the route `enum` + `Block_Mutator::mutate()` switch; update grandparent `innerContent` if child count changes.
- **New auto-transform:** add to `HTML_Transformer::auto_transform_html()` (regex for tag swaps; `WP_HTML_Tag_Processor` for attrs); return `null` when inapplicable.
- **Restrict/extend the agent:** filter `gk/block-mcp/agent/caps` (e.g. add `delete_posts`) or `gk/block-mcp/agent/role`. Forbid full-account credentials with `gk/block-mcp/identity/allow-self` → `__return_false`. Enable trashing via Settings (option `gk_block_api_allow_trash`) or the `gk/block-mcp/post/allow-trash` filter.
- **Modify preferences:** `Preferences::update_preferences()` (deep-merges sub-keys).

## Conventions

- **Regression test required for every bug fix** — must FAIL pre-fix (real symptom), pass post-fix, and have teeth (revert → red). Exercise the real mechanism (live `authenticate` chain / a real `WP_REST_Request`, not a bare method call) and cover every facet (each cap/post-type, single-site + multisite, API + interactive). See `gk-block-mcp/tests/AGENTS.md`, `tests/Connect/AgentAuthTest.php`, `tests/Connect/AgentRestCapabilityTest.php`.
- Class files `class-{lowercased-underscored}.php`; namespace `GravityKit\BlockMCP`; service classes return `WP_Error`.
- Write/innerHTML paths sanitize via `wp_kses_post()`; block names validated against `WP_Block_Type_Registry`.
- **Public-facing language:** never name a concrete third-party namespace as "legacy" in comments/errors/responses/changelog — the tier is site-configurable. Use generic phrasing; resolve a legacy namespace from `Preferences::get_defaults()` at runtime in fixtures.
- **Comments are public artifacts** — present-tense behaviour + hard contracts only; no history/journal, no off-tree spec pointers, no future-architecture speculation.

## Gotchas

- **`/connect/exchange` is `__return_true` by design** — security is the single-use, short-TTL, sealed code, not auth. Don't add a permission callback.
- **Connection meta is a *network* option** — clean up with `delete_network_option()` (falls back to `wp_options` on single-site). Own-account credentials are revoked at the source by `Connections::purge_all_recorded()` on uninstall (a dangling Application Password keeps authenticating to core REST after the plugin is gone).
- **`.mcpb` v0.3 requires `description`** on every `user_config` option, despite the prose docs — omitting it makes Claude Desktop reject the bundle.
- **The agent role survives deactivation** (`wp_user_roles`); only uninstall / `purge()` removes it.
- **`unserialize(serialize())` deep-clone** in `duplicate` assumes serializable values (always true for parsed blocks).
- **Pattern reference counting uses `LIKE`** on `post_content` — accurate but unindexed; rely on the cache on large sites.
- **`wp_kses_post()` strips scripts/handlers** from innerHTML — intentional, but surprises agents inserting embeds.
- **Block inventory scan is expensive** (`posts_per_page => -1`); the 1-hour transient matters — use `refresh: true` sparingly.

## Related Resources

- `../AGENTS.md` — repo-root architecture + connect overview (+ MCP server side).
- `gk-block-mcp/tests/AGENTS.md` — PHPUnit conventions and regression-test discipline.
- `gk-block-mcp/readme.txt` — canonical changelog.
