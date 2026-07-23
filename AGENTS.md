# AGENTS.md — GK Block API + Block MCP

> Block-level WordPress content CRUD for AI agents, delivered as a two-part system: an npm-published MCP server (`@gravitykit/block-mcp`) that talks to a companion WordPress plugin (`gk-block-mcp`) over a private REST namespace. The plugin also ships a one-click **Connect** onboarding flow that provisions a dedicated, least-privilege agent account and hands the MCP client a credential out-of-band — no copy-pasting Application Passwords.

## Quick Start

**What this is:** Two components shipped as separate artifacts (an npm package + a WordPress plugin) that carry one shared version (lockstep).
1. **MCP server** (`src/`, built to `dist/index.cjs`) — a thin stdio MCP server that exposes ~30 tools, validates input, calls the plugin's REST API, and enriches responses with AI-friendly guidance (tier groupings, legacy warnings, error translation). Also contains the **connector** (`src/connect.ts`) — the `block-mcp connect` subcommand that drives a browser-Approve handshake and writes the client's MCP config.
2. **WordPress plugin** (`wordpress-plugin/gk-block-mcp/`) — registers the `gk-block-api/v1` REST namespace, owns block parsing/serialization/mutation, the preference/scoring engine, post/term/media lifecycle, and the Connect onboarding UI + credential flow.

**Main entry points:**
- MCP server: `src/index.ts` → built to `dist/index.cjs`; npm `bin` is `block-mcp`.
- Connector: `src/connect.ts` (`block-mcp connect …`).
- Plugin: `wordpress-plugin/gk-block-mcp/gk-block-mcp.php` (bootstraps on `rest_api_init` + admin hooks).

**Architecture style:** Hook-driven WordPress plugin (autoload via `spl_autoload_register`) behind a typed TypeScript MCP server. No framework; plain WP + the plain MCP SDK.

**Key namespaces:** PHP `GravityKit\BlockMCP`; REST `gk-block-api/v1`; npm `@gravitykit/block-mcp`.

**Versions:** the MCP server and plugin share one version (lockstep). `package.json` `version` (npm) always equals `readme.txt` `Stable tag` + `gk-block-mcp.php` `Version`/`GK_BLOCK_MCP_VERSION` (plugin). See **Versioning & Releases**.

```bash
cd MCPs/block-mcp && npm install
npm run build          # esbuild → dist/index.cjs
export WORDPRESS_URL=… WORDPRESS_USER=… WORDPRESS_APP_PASSWORD=…
npm start              # node dist/index.cjs (stdio)
```
The plugin must be active on the target site for the server to reach `gk-block-api/v1`.

## Repository Map

```text
MCPs/block-mcp/
├── AGENTS.md / CLAUDE.md (→ @AGENTS.md) / README.md
├── package.json                 # @gravitykit/block-mcp; esbuild build; bin: block-mcp
├── tsconfig.json                # ES2022, bundler resolution
├── src/
│   ├── index.ts                 # MCP server entry — aggregates tools, routes calls
│   ├── connect.ts               # `block-mcp connect` — loopback browser-Approve handshake + config write
│   ├── client.ts                # WordPressBlockClient — typed HTTP wrapper (axios, Basic Auth)
│   ├── types.ts                 # All TS interfaces
│   ├── enrichers.ts             # Syntax-highlight / response enrichment (shiki/core)
│   ├── error-translator.ts      # Maps REST error codes → actionable agent hints
│   ├── instructions.ts          # MCP server instructions / handshake addendum
│   ├── preferences.ts           # Client-side preference annotation (mirrors PHP)
│   └── tools/                    # discovery, read, write, mutate, patterns, posts, terms, media, yoast
├── dist/index.cjs               # Built bundle (esbuild, single CJS file) — shipped to npm
├── tests/                       # Vitest tests for the server + connector (connect.*.test.ts)
└── wordpress-plugin/gk-block-mcp/
    ├── gk-block-mcp.php          # Bootstrap: autoloader, rest_api_init wiring, admin wiring, CLI
    ├── uninstall.php             # Full data + agent teardown (multisite-aware)
    ├── readme.txt                # Canonical changelog (WordPress plugin readme)
    ├── phpcs.xml.dist            # WordPress-Extra + WordPress-Docs + PHPCompatibilityWP (testVersion 7.4-)
    ├── phpstan.neon.dist         # PHPStan level 5, analyze-as-PHP-8.2, WP stubs
    ├── phpstan-bootstrap.php     # Placeholder constants for static analysis
    ├── includes/                 # 22 classes (see Core Classes)
    └── tests/                    # PHPUnit suite (SQLite drop-in) — see tests/AGENTS.md
```

## Architecture

### Two-Component Design

```text
AI client  ──stdio──▶  MCP server (TypeScript)  ──HTTPS Basic Auth──▶  WordPress plugin (PHP)
                       src/index.ts                                     wordpress-plugin/gk-block-mcp/
                       validates + enriches                             parses/serializes blocks,
                                                                        manages revisions, enforces
                                                                        preferences, owns the data
```

The MCP server is a thin orchestration layer: it validates inputs, delegates to the REST API via `WordPressBlockClient` (`src/client.ts`), and annotates responses (`src/enrichers.ts`, `src/preferences.ts`, `src/error-translator.ts`). All heavy lifting — block parsing, serialization, mutation, safety checks, rate limiting, revision tracking — lives in the PHP plugin.

### Plugin initialization flow

`gk-block-mcp.php` registers `spl_autoload_register` mapping `GravityKit\BlockMCP\Some_Class` → `includes/class-some-class.php`. Three wiring points:

1. **`init_rest_api()` on `rest_api_init`** (`gk-block-mcp.php:129`) — builds the service graph and registers routes: `Preferences → Block_Registry(+Block_Inventory)`, `Pattern_Manager`, `Block_CRUD(Preferences, Block_Safety, HTML_Transformer, Block_Inventory)`, then `REST_Controller(...)->register_routes()`, then `Yoast_Bridge->register_routes()`, then **`( new Connect_Page() )->register_rest_routes()`** (the connector credential-exchange route).
2. **Admin wiring on `plugins_loaded`** (`gk-block-mcp.php:179`) — `Settings_Page` (needs `admin_menu`, which fires before `admin_init`) and `Connect_Page` register their admin-post handlers + settings UI.
3. **WP-CLI bootstrap** — `rest_api_init` doesn't fire under `wp`, so CLI commands wire the graph explicitly.

All service objects are constructed inside these hooks — no global singletons.

### Connect / onboarding flow (the credential handshake)

The headline 2.0 feature. Goal: connect an AI client in a few clicks, **without the user ever copy-pasting an Application Password**, and with the AI confined to a dedicated least-privilege account.

**Actors:**
- **`Agent_Provisioner`** (`class-agent-provisioner.php`) — ensures a dedicated WP user `block-mcp` (`LOGIN`), with a custom role `block_mcp_agent` (`ROLE`). The role's caps come through the `gk/block-mcp/agent/caps` filter: `read`, `edit_posts`, `edit_others_posts`, `edit_published_posts`, `publish_posts`, the `*_pages` equivalents, plus `upload_files`. **Deliberately NO `delete_*`, NO `unfiltered_html`, NO `manage_options`.** An `authenticate` filter blocks interactive sign-in (fail-closed). The role lives in `wp_user_roles` so it survives plugin deactivation. `gk/block-mcp/agent/role` lets an operator manage their own role slug.
- **`App_Password_Issuer`** (`class-app-password-issuer.php`) — mints an Application Password on a target user via core `WP_Application_Passwords`. The one-time plaintext is surfaced to the caller exactly once and never persisted in the clear.
- **`Connections`** (`class-connections.php`) — lists/revokes Block-MCP-prefixed Application Passwords (`NAME_PREFIX = 'Block MCP'`) and stores the facts core can't: which account holds each connection and who approved it (`META_OPTION = gk_block_api_connection_meta`, a **network** option keyed by app-password UUID → `{ user_id, created_by, created_at }`).
- **`MCPB_Generator`** (`class-mcpb-generator.php`) — builds a Claude Desktop `.mcpb` bundle (manifest_version `0.3`) whose `user_config` is pre-filled with the issued credential. Each `user_config` option MUST carry `type` + `title` + `description` (all three required by the v0.3 schema).
- **`Connect_Page`** (`class-connect-page.php`, the largest class) — the onboarding UI + four admin-post handlers (`ACTION_CONNECT`, `ACTION_AUTHORIZE`, `ACTION_REVOKE`, `ACTION_EXCHANGE` + its `_nopriv` variant) + the REST exchange route.

**The browser-Approve handshake (Claude Code / Cursor / ChatGPT / "configure myself"):**
1. The connector (`src/connect.ts`) starts a loopback HTTP server on `127.0.0.1:<port>`, opens the admin authorize URL (`?gk_authorize=1&callback=…&state=…&client=…`), and waits.
2. `Connect_Page::render_page()` detects `?gk_authorize` and renders a **focused consent screen** (no settings tabs/chrome) via `render_authorize_screen()`. The admin picks an **identity** (below) and clicks Approve.
3. `handle_authorize()` (admin-post `ACTION_AUTHORIZE`, nonce + `manage_options`) validates the callback resolves to a loopback address (`is_loopback_callback()` — refuses `127.0.0.1.evil.com`, `localhost@evil.com`, missing port, userinfo), calls `provision_credentials($client, $identity)`, then stores the credential under a **single-use, short-TTL code** and redirects only the *code* (never the password) to the loopback callback.
4. The connector POSTs the code to the REST route **`POST /wp-json/gk-block-api/v1/connect/exchange`** (`permission_callback => __return_true`; security is the single-use code, not auth) via the permalink-independent `?rest_route=` form. `handle_exchange()` redeems it once and returns `{ success, data: { site, user, password } }`.
5. The connector writes `WORDPRESS_URL` / `WORDPRESS_USER` / `WORDPRESS_APP_PASSWORD` into the client's MCP config (`creds.user` — so own-account connections write the human's login).

**The .mcpb path (Claude Desktop):** one-click download. `handle_connect()` (admin-post `ACTION_CONNECT`) → `provision_credentials()` → `MCPB_Generator::build()` streams the bundle with the credential pre-filled; the user double-clicks to install.

**Identity model (two options on the Approve screen):**

| Option | Credential minted on | Caps | Byline (`post_author` on created posts) |
|---|---|---|---|
| `agent` *(default, recommended)* | the dedicated `block-mcp` user | least-privilege | "Block MCP" |
| `self` ("Your own account") | **the approving user** | their full caps | the approving human |

`provision_credentials($client, $identity)` validates the identity (`array( 'agent', 'self' )`, falling back to `agent`), chooses the target user, mints the password, and records the choice via `Connections::record_meta()`. There is **no byline subsystem** — `Connections::author_to_credit()` was removed and `Post_Manager::create_post()` no longer remaps `post_author` from connection meta; created content authors as the authenticating account. An explicit `author` argument on create still sets authorship (gated on the actor holding `edit_others_{type}`). Two related controls govern the high-risk `self` mode: the **`gk/block-mcp/identity/allow-self`** filter (default `true`) removes the "Your own account" option AND clamps any `self` request back to the agent when it returns false; and a JS **confirm-gate** (an acknowledgment checkbox) disables Approve until the user accepts that `self` mints an Application Password with their account's full access (the server validates the identity independently). The connections list spans both hosts (`Connections::list()` + `list_self_hosted()`); revoke resolves the host from meta (`revoke_by_uuid()`); uninstall revokes own-account credentials at the source (`purge_all_recorded()`).

**Credential at rest:** the single-use exchange code and paste-mode password are sealed (AES-256-GCM, HKDF key from `wp_salt('auth')`) and stored as non-autoloaded `wp_options` (`gk_block_api_xchg_*`, `gk_block_api_paste_pw_*`) with embedded `expires_at` + opportunistic GC (`gk_block_api_cred_gc_at`). Seal mode is filterable via `gk/block-mcp/credential/seal-mode`. The minted password must NEVER reach JS, URLs, browser history, or be POSTed off-origin.

### Block CRUD engine

`Block_CRUD` (`class-block-crud.php`) is a **facade** over three engines built in its ctor:
- **`Block_Reader`** — `get_blocks()` / `format_blocks()`: parse `post_content`, format with flat index + nested path, optional render mode, sourced-attribute extraction.
- **`Block_Writer`** — `update_block(s)`, `insert_blocks`, `delete_blocks`, `replace_all_blocks`, `insert_pattern`, `build_block_from_def`, `save_post_content` (revision tracking), rate limiting.
- **`Block_Mutator`** (`class-block-mutator.php`) — the 9-operation path-based mutation engine (`update-attrs`, `update-html`, `replace-block`, `remove-block`, `wrap-in-group`, `unwrap-group`, `insert-child`, `duplicate`, `move`).

Supporting: `HTML_Transformer` (auto-transform innerHTML on attr change via `WP_HTML_Tag_Processor` + regex), `Block_Safety` (static-block staleness guard), `Block_Registry` / `Pattern_Manager` / `Block_Inventory` (discovery + scoring), `Preferences` (namespace scoring, replacement map). `Post_Manager` / `Term_Manager` / `Media_Manager` own post/term/media lifecycle (v1.2). `Instructions` + `Yoast_Bridge` are the instructions endpoint and the Yoast SEO REST bridge.

### Data model & storage

| What | Where | Notes |
|---|---|---|
| Block content | `post_content` (HTML block comments) | always round-tripped through `parse_blocks()`/`serialize_blocks()` |
| Preferences | option `gk_block_api_preferences` | namespace scores, replacement map |
| Post-type allow-list | option `gk_block_api_post_types_allowlist` | gates `create_post` |
| Trash toggle | option `gk_block_api_allow_trash` ('0'/'1', default off) | gates `update_post(status:trash)` |
| Media uploads switch | option `gk_block_api_uploads_enabled` ('0'/'1') | |
| MCP instructions addendum | options `gk_block_api_instructions(_updated_at)` | served unauthenticated at handshake |
| Storage-mode scan | options `gk_block_api_storage_modes(_last_run)` + `_manual` | dual-storage classification |
| Agent user id | option `gk_block_api_agent_user_id` (autoload off) | persists across revokes |
| Connection meta | **network** option `gk_block_api_connection_meta` | UUID → approver + byline + host |
| Sealed credentials | options `gk_block_api_xchg_*`, `gk_block_api_paste_pw_*` + `gk_block_api_cred_gc_at` | single-use, TTL, sealed |
| Inventory cache | transient `gk_block_inventory` | 1-hour TTL |
| Rate limits | transients `gk_block_api_rate_{post_id}`, `gk_block_api_instr_rl_{ip}` | sliding window |

### REST API

All under `gk-block-api/v1`. Reads require `edit_posts`; per-block writes require `edit_post` on the post. Highlights: `/posts/{id}/blocks` (GET/POST/PUT/DELETE/PATCH + `/batch-update`, `/mutate`, `/insert-pattern`), `/posts` + `/posts/{id}` (create/update), `/block-types`, `/patterns(/search|/{id})`, `/site-usage`, `/resolve`, `/terms`, `/media`, `/templates` + `/template` (read-only FSE templates/parts, block themes only), `POST /template` + `POST /template/reset` (gated template writes: option `gk_block_api_template_edits`, off by default, plus the dedicated `gk_block_mcp_edit_templates` capability or `edit_theme_options` — not `edit_posts`), and the unauthenticated **`POST /connect/exchange`** (single-use-code redemption — the only `__return_true` route, by design). See `wordpress-plugin/AGENTS.md` for the full route table.

### MCP server tools

`src/index.ts` aggregates `*_TOOLS` arrays from `src/tools/*.ts` and routes via `Set<string>` lookups: `discovery` (list/get block types, patterns, site usage), `read` (get_page_blocks), `write` (update/insert/delete/replace/rewrite/revert), `mutate` (edit_block_tree), `patterns` (insert_pattern, create_pattern), `posts` (create/update_post), `terms` (list_terms), `media` (upload_media), `yoast` (get/update/bulk_update SEO), `templates` (list_templates, get_template — read-only; update_template, reset_template — gated writes). Each handler validates args, calls a `WordPressBlockClient` method, enriches, and returns MCP text content.

## Conventions

### File & class naming
- PHP: `class-{lowercased-underscored-name}.php` for a class in `GravityKit\BlockMCP`. Service classes return `WP_Error`; `REST_Controller` wraps exceptions via `handle_error()`.
- TS: ESM source, `.js` import suffixes, esbuild → single CJS bundle (`dist/index.cjs`). No `dotenv` (breaks the esbuild ESM→CJS bundle; env comes from the parent process).

### Hook / filter naming
Custom **hooks** follow the GravityKit slash convention `gk/block-mcp/{domain}/{leaf}` (e.g. `gk/block-mcp/agent/caps`, `gk/block-mcp/media/sideload-blocked-ranges`). **Option keys, constants, transients, and other global identifiers keep the `gk_block_api_*` prefix** — only `apply_filters`/`do_action`/`add_filter` hook *names* use the slash form. `WordPress.NamingConventions.PrefixAllGlobals` allows `gk_block_api`, `GK_BLOCK_API`, `GravityKit\BlockMCP`, plus the **hook-only** prefix `gk/block-mcp` (`gk/block-mcp` isn't a valid PHP identifier, so `InvalidPrefixPassed` is excluded and it's used only as a hook prefix). `ValidHookName` adds `/` and `-` as word delimiters so the slash/dash names don't trip the all-underscore rule (see `phpcs.xml.dist`). Key filters: `gk/block-mcp/agent/caps`, `gk/block-mcp/agent/role`, `gk/block-mcp/identity/allow-self`, `gk/block-mcp/post/allow-trash`, `gk/block-mcp/credential/seal-mode`, `gk/block-mcp/mcpb/manifest`, `gk/block-mcp/media/sideload-blocked-ranges`, `gk/block-mcp/media/uploads-enabled`, `gk/block-mcp/agent/remove-on-uninstall`. The one custom **action** is `gk/block-mcp/block/refs-persisted`; otherwise the plugin relies on core extension points (`rest_api_init`, `admin_post_*`, `wp_kses_post`, `parse_blocks`/`serialize_blocks`, `wp_update_post`).

### Code style
- **Assign checks to named variables before the conditional** — don't inline function calls / compound expressions in `if`/`while`/`?:`. Exception: short-circuit guards where ordering is load-bearing (a null/`isset` guard before a dereference stays inline).
- `sanitize_text_field()` for strings, `absint()` for IDs, `wp_kses_post()` for innerHTML on every write path.
- Boolean settings stored as the strings `'0'`/`'1'` (a hidden `'0'` input pairs with the `'1'` checkbox) — `update_option()` can't reliably persist literal `false` against a missing option. Normalize via `Settings_Page::normalize_checkbox_option()`.
- **Don't use `wp_generate_password()` as a random seed** (filterable; not a CSPRNG). Use `random_bytes()` / `wp_generate_uuid4()` / `wp_hash($seed, 'nonce')`.

### Comments & docblocks are public artifacts
Write them as standalone documentation of what the code does **today** — never as a journal of how it got there. No review-tool attributions, no `docs/specs/…` load-bearing pointers, no Linear/issue numbers, no "future SaaS revision will…" speculation, no internal scale assumptions ("≤ a few hundred posts"). Three tests before writing a comment: would it belong in a PR/ticket/postmortem? does it describe history or speculate about future architecture? does it require knowing an off-tree artifact to parse? If yes — rewrite or move it. Document hard contracts, non-obvious WHY, and present-tense behaviour.

### Public-facing language
Don't name specific third-party block namespaces as "legacy" in comments, error messages, REST responses, or changelog text — the legacy tier is site-configurable via `Preferences`. Use generic phrasing ("the namespace is configured as legacy"). Test *fixtures* may use a concrete namespace, but resolve it from `Preferences::get_defaults()` at runtime rather than hardcoding.

### Version annotations
`@since {version}` annotates **shipped public members** — production classes, methods, hooks, and REST routes. Update `@since` when adding such a member; new code uses `@since 2.1.0` as a placeholder until the release version is settled. **Test files (`tests/**`) do not carry `@since`** — they pin contracts, not API surface, and the suite is uniformly free of it.

## Extension Patterns

### Add a REST endpoint
1. `register_rest_route( self::NAMESPACE, '/my-endpoint', [...] )` in `REST_Controller::register_routes()`.
2. Add the handler (try/catch → `handle_error()`). Use `check_permissions()` (read) or `check_edit_permissions()` + `check_post_edit_permission($id)` (write).
3. Delegate to a service class; add the client method + types to `src/client.ts` / `src/types.ts`.

### Add an MCP tool
1. Add the tool def to the right `*_TOOLS` array in `src/tools/*.ts` (`name`, `description`, `inputSchema`).
2. Add the `case` to the module's `handle*Tool()`; call a `WordPressBlockClient` method; enrich.
3. `npm run build`.
4. **Wire it into the WordPress Abilities API too** — `buildManifest()` in `scripts/export-abilities-manifest.mjs` only exports tool groups it explicitly imports/spreads; a new `*_TOOLS` array (a whole new module) needs its own `import` + spread there, or it never reaches `tools.manifest.json` and is invisible to `Abilities_Registry`/the MCP Adapter regardless of step 3. Map the new tool(s) to a `permission` key in `permissionFor()` — reuse an existing bucket (`read`, `edit_post`, `create_post`, `upload_files`, `manage_options`) when an existing REST permission callback already enforces the right check, or add a new bucket (see `TEMPLATE_EDIT`) plus a matching `case` in `Abilities_Registry::check_tool_permission()` that calls the *same* `REST_Controller` permission-callback method the REST route uses — never re-implement the gate logic in the ability's permission check, or the two surfaces can drift out of parity. Then add the dispatch `case` + `execute_*()` method to `Tool_Executor::execute()` (delegates to the controller via `call_controller()`) — a manifest entry with no `Tool_Executor` case registers as an ability but 400s "Unknown Block MCP tool" the moment anything calls it. Run `node scripts/export-abilities-manifest.mjs` (regenerates the manifest) and `npm run build` (bakes the bundle); commit both.

### Add a mutation operation
Add to the `MutationOp` union (`src/types.ts`), the `enum` in `REST_Controller` + the `edit_block_tree` `inputSchema` + `VALID_OPS` (`src/tools/mutate.ts`), and a `case` in `Block_Mutator::mutate()`. Maintain the `innerContent` null-placeholder invariant when child count changes.

### Add an auto-transform
Add a branch to `HTML_Transformer::auto_transform_html()` — tag swaps use regex (`WP_HTML_Tag_Processor` can't rename tags); attribute/style transforms use the processor (never pre-escape values); return `null` when nothing applies (so the safety warning fires).

### Restrict / extend the agent
Filter `gk/block-mcp/agent/caps` to add/remove capabilities (e.g. grant `delete_posts` to allow hard deletes), or `gk/block-mcp/agent/role` to manage your own role slug. Enabling **Move posts to trash** in Settings flips the option `gk_block_api_allow_trash` (also filterable via `gk/block-mcp/post/allow-trash`), which gates `Post_Manager::update_post(status:trash)` at the application layer (the agent has no `delete_*` cap, but `wp_trash_post()` doesn't check caps, so the toggle is the real gate).

## Development

### Setup & build
```bash
cd MCPs/block-mcp && npm install
npm run build      # esbuild → dist/index.cjs (shipped to npm)
npm run dev        # watch
npm start          # node dist/index.cjs (stdio)
```
Env: `WORDPRESS_URL`, `WORDPRESS_USER`, `WORDPRESS_APP_PASSWORD` (passed by the parent process). The plugin must be active on the target site.

### Testing
- **Server/connector:** Vitest under `tests/` (`connect.test.ts`, `connect.security.test.ts`, `connect.runconnect.test.ts` exercise the loopback handshake with `fetch` stubbed — nothing leaves the machine).
- **Plugin:** PHPUnit (`composer test`) on a SQLite drop-in. **Regression tests are mandatory** — every bug fix ships a test that FAILS pre-fix (reproduces the real symptom), passes post-fix, and has teeth (revert the fix → it goes red). Exercise the real mechanism (live `authenticate` chain / a real `WP_REST_Request`, not just a direct method call) and cover every facet (each cap/post-type, single-site AND multisite, API AND interactive). See `wordpress-plugin/gk-block-mcp/tests/AGENTS.md` and the `tests/Connect/Agent*Test.php` auth-chain pattern.
- Local WP: gkclone (deploy = rsync the plugin to the synced plugins dir + `opcache_reset`).

### Static analysis (run before every commit)
```bash
composer lint      # phpcs: WordPress-Extra + WordPress-Docs + PHPCompatibilityWP (testVersion 7.4-)
composer lint:fix  # phpcbf
composer analyze   # PHPStan (level 5, analyze-as-PHP-8.2, WordPress stubs)
```
`composer lint` must finish with **0 errors / 0 warnings**; `composer analyze` must finish **[OK]**; `composer test` must finish **green** — at every commit. `phpcs.xml.dist` excludes `tests/`, `scripts/`, and `phpstan-bootstrap.php` (test/CLI/tooling code follows its own conventions; web-context sniffs don't apply). PHPStan config lives in `phpstan.neon.dist` + `phpstan-bootstrap.php` (placeholder constants); `WP_DEBUG`/`WP_DEBUG_LOG`/`WP_CLI` are marked `dynamicConstantNames` so config-constant guards aren't reported as always-true/false.

## Versioning & Releases

The plugin and the MCP server share **one version (lockstep)** — `package.json` `version` always equals the plugin version. They ship as separate artifacts (an npm package and a WP plugin), but the plugin *bundles* the built server (`.mcpb` + Connect flow serve `assets/mcp-server/index.cjs`, not npm), so they are one product and one number identifies everything: the `v{version}` tag, the GitHub release, the npm package, the `.mcpb` manifest, and the server's MCP handshake (`serverInfo.version`). The plugin follows WP plugin conventions (`readme.txt` is the canonical changelog).

**Semver:** MAJOR = breaking REST/tool changes; MINOR = additive endpoints/tools/fields/settings; PATCH = fixes/hardening/refactors/i18n/tests.

**Every version bump updates all five, in one commit:**
1. `package.json` `version` (the npm package)
2. `gk-block-mcp.php` `* Version:` header
3. `gk-block-mcp.php` `GK_BLOCK_MCP_VERSION` constant
4. `readme.txt` `Stable tag:`
5. `readme.txt` `== Changelog ==` (a `= {version} on {date} =` entry with a one-sentence summary, grouped `Added`/`Improved`/`Fixed`/`Updated`, newest at top)

`package.json` also has a copy in `package-lock.json` — bump **both** root entries (top-level `version` and `packages."".version`); don't blind-replace the version string, since a dependency can coincidentally share it. Then run **`npm run build`**: it bakes the version into `dist/index.cjs` and (via `postbuild`) refreshes the tracked bundle `wordpress-plugin/gk-block-mcp/assets/mcp-server/index.cjs`. Commit the rebuilt bundle. CircleCI does NOT rebuild the server — the ZIP and `.mcpb` ship the tracked copy as-is — so a bundle whose baked version doesn't match the plugin header ships stale; treat a mismatch as a release blocker. Lockstep holds even when only one side changed: a PHP-only release still publishes a new npm version (its only delta is the baked version string, and `npm-publish.yml` skips an already-published version, so a re-push is idempotent), and a server-only fix still cuts a plugin release, which its bundled users need anyway.

**No `== Upgrade Notice ==` section.** GravityKit plugins update through Foundation, not the WP.org directory, so it is never surfaced to users; the GitHub release notes are sourced from the Changelog entry instead. `readme.txt` ends at `== Changelog ==`.

**Releasing = merging the version bump to `main`.** From that merge, three pieces fire:
- **CircleCI's `build_package_release` job (`.circleci/config.yml`) is the sole creator of the `v{version}` tag and GitHub release.** It builds the ZIP, then runs `npx @gravitykit/gktools release` (tags `v{version}`, creates the release with that version's `readme.txt` Changelog as the body, posts to `#release-announcements`) and `gktools announce` (signs the build via the Release Manager). Commit-message switches: `[skip release]` skips the GitHub release, `[skip notify]` skips the announce. Release builds off `main` ship a clean version; other branches carry the commit hash.
- `build-plugin-zip.yml` only builds the plugin ZIP and the rolling `latest` prerelease now; it no longer creates the version tag or release (CircleCI owns that).
- `npm-publish.yml` publishes `@gravitykit/block-mcp` (npm Trusted Publishing/OIDC, no token secret) on every push to `main` and on release tags, skipping any version already on the registry. Under lockstep the npm version equals the plugin version, so the merge that releases the plugin publishes the matching npm package in the same push, and the skip-if-present guard makes the release-tag trigger idempotent.

On feature branches, the changelog header is `= develop =` until release.

## Gotchas

1. **Flat index vs path.** `index` (from `format_blocks`) skips empty/whitespace blocks; `path` uses raw `parse_blocks()` indices (preserving them). Use `path` for `/mutate`, `index`/`ref` for the per-block write tools.
2. **`innerContent` null invariant.** Container blocks store `innerContent` like `['<div>', null, null, '</div>']` — one `null` per child. Any mutation that changes child count must maintain it or `serialize_blocks()` corrupts.
3. **The exchange route is `__return_true` by design.** Security is the single-use, short-TTL, sealed code — not auth. Don't "fix" it by adding a permission callback.
4. **Loopback callback validation is exact.** `is_loopback_callback()` requires a real loopback host + numeric port + no userinfo. The minted password is redirected only as a single-use *code*, never inline.
5. **The agent can't delete — but can trash.** `wp_trash_post()` performs no cap check, and `update_post` only needs `edit_post`. The `gk_block_api_allow_trash` toggle is the application-level gate; it's OFF by default.
6. **Connection meta is a network option.** Consistent with the network-wide connection list on multisite; `delete_network_option()` (falls back to `wp_options` on single-site) is the correct cleanup. Own-account credentials live on real users — uninstall revokes them via `Connections::purge_all_recorded()` (a dangling Application Password keeps authenticating to core REST after the plugin is gone).
7. **.mcpb manifest v0.3 requires `description`** on every `user_config` option (alongside `type`+`title`), despite the prose docs calling it optional. Omitting it → Claude Desktop rejects the bundle with "user_config: Required, Required, Required".
8. **Rate limiting is per-post, not per-user.** Multiple agents editing the same post share the budget (10 writes/min, 2 full rewrites/min).
9. **Legacy-tier blocks are hard-rejected** on insert/replace (HTTP 400); avoid-tier warns but succeeds. Enforcement is insert-only — `update-attrs`/`update-html` don't re-check tiers.
10. **No `dotenv` in the server.** It breaks the esbuild ESM→CJS bundle. Env vars come from the parent process only.
11. **The agent role survives deactivation.** It lives in `wp_user_roles`; only `uninstall.php` (or `Agent_Provisioner::purge()`, gated by the `gk/block-mcp/agent/remove-on-uninstall` filter) tears it down.
12. **External-contributor PR branches live on forks.** `git fetch origin <branch>` 404s for a fork-hosted PR head — use `gh pr checkout <n>` (fetches `refs/pull/<n>/head` and sets the fork as the push remote). Push follow-up fixes to the fork (`git push git@github.com:<owner>/block-mcp.git <branch>:<branch>`); this works while the PR has maintainer-edit enabled.
13. **MCP `destructiveHint: false` promises additive-only.** Any tool that overwrites or removes existing content must declare `destructiveHint: true` in its `src/tools/*` annotations — a `false` hint tells MCP clients the tool never touches existing data, so they skip confirmation. Don't copy annotations from an update-style tool without checking: `update_block`/`update_blocks` shipped with a wrong `false`.

14. **The Adapter serves Block MCP's abilities from Block MCP's OWN endpoint, not a generic Adapter URL.** When enabled, `Abilities_Registry` calls `McpAdapter::instance()->create_server( 'gk-block-mcp', 'gk-block-mcp', 'mcp', … )`, so the tools live at `/wp-json/gk-block-mcp/mcp` (Block MCP's namespace/route, mounted via the Adapter). That is ours, distinct from the Adapter's own default server at `/wp-json/mcp/mcp-adapter-default-server`. The WordPress MCP Adapter is a **separate** plugin (github.com/WordPress/mcp-adapter, installed from its GitHub Releases, not the WP.org directory) that requires WordPress 6.9 and is not bundled with core, so gate the Connect/settings UI on `class_exists( '\WP\MCP\Core\McpAdapter' )`, never on the WP version alone. Transport is streamable HTTP (POST plus an `Mcp-Session-Id` session header; GET returns 405; no SSE); auth is a WordPress Application Password over Basic (no OAuth in adapter 0.5.0), so standard streamable-HTTP clients (Claude Code, Cursor) connect without extra config. [live-verified + endpoint correction, 2026-07]

## Related Resources

- `wordpress-plugin/AGENTS.md` — full REST route table + per-class plugin reference.
- `wordpress-plugin/gk-block-mcp/tests/AGENTS.md` — PHPUnit conventions (naming, docblocks, regression-test discipline).
- `README.md` — human-facing getting-started.
- `docs/specs/` — versioned design specs.
- WordPress Block API: https://developer.wordpress.org/block-editor/reference-guides/block-api/ · MCP: https://modelcontextprotocol.io · MCPB manifest schema: https://github.com/anthropics/mcpb
