# AGENTS.md — gk-block-api PHPUnit suite

> Operational conventions for adding and editing tests. Production-code
> conventions live in `../AGENTS.md` and the repo-root `../../AGENTS.md`.
> 58 test files across 11 directories; runs on a SQLite drop-in via `composer test`.

## Test naming

- One method per assertion family. `test_<thing>_<expected_behaviour>()`.
- Keep names declarative: what the contract is, not what's-being-tested-as-a-task. Bad: `test_bug_fix_for_validation`. Good: `test_validate_block_def_empty_name_rejects`.
- Regression tests for a documented bug fix should include the symptom in the name: `test_*_does_not_*`, `test_*_rejects_*`, `test_*_normalizes_*`.

## Docblocks, not inline comments

**Every test method gets a docblock that states the contract being asserted.** Why the test exists, what regression it pins, what the failure mode was. The body of the test stays focused on Arrange / Act / Assert.

**Don't bury the why in inline comments inside the method body.** Reviewers and future-you read the docblock first; the body is for the mechanics.

Good:

```php
/**
 * Wrapper tagName must come from a fixed allowlist.
 *
 * Without an allowlist check, `script` / `iframe` / `object` slipped past
 * sanitize_key. The wrapper innerHTML is built by raw string concatenation
 * (no wp_kses_post in the immediate response), so attacker-controlled
 * tagName could embed active markup in the response payload. The fix
 * silently falls back to <div> for disallowed tags rather than erroring,
 * so the mutate op still succeeds — this test pins both halves of that
 * contract.
 */
public function test_wrap_in_group_tag_allowlist_rejects_arbitrary_tags() {
    // Arrange / Act / Assert — no narrative comments here.
    $this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
    $result = $this->mutator->mutate( ... );
    $this->assertStringContainsString( '<div', $result[0]['innerHTML'] );
}
```

Bad:

```php
public function test_wrap_in_group_tag_allowlist_rejects_arbitrary_tags() {
    // `script`, `iframe`, `object`, etc. would previously slip in via
    // sanitize_key without an allowlist check. The wrapper innerHTML is
    // built by raw string concatenation (no wp_kses_post in the
    // immediate response), so attacker-controlled tagName could embed
    // active markup in the response payload.
    $this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
    // ...
}
```

Three reasons to prefer docblocks:

1. **Reviewers see the contract in one place.** The docblock is the test's contract; the body is the proof. Inline narrative makes both jobs harder.
2. **`@covers`, `@dataProvider`, `@group`, `@depends`** all live on the docblock. Co-locating the rationale there keeps annotations + intent together.
3. **IDE quick-look / `phpunit --testdox`** reads docblocks. Failures surface the description, not the line of inline comment buried 10 lines down.

Inline `//` comments inside test bodies are still fine for **mechanical** asides — e.g. `// reset the cache between asserts`, `// raw md5 to mirror the production key shape`. They are not fine for **why this test exists** narrative.

## Test layout

Tests go in the directory that mirrors the production seam, in the file that owns the class under test. Don't create grab-bag files like `PhpBugFixesRegressionTest.php` — each regression test belongs in the file that owns the contract it pins.

| Area | Directory | Notable files |
|---|---|---|
| Block engine | `tests/Block/` | `BlockCrudTest` (facade), `BlockMutatorTest`, `HtmlTransformerTest`, `BlockSafetyTest`, `BlockRefsTest`, `PatternReferenceCountsTest`, `CoreBlocksConformanceTest`, `FormatBlockFilterTest`, `BlockBindingsTest` |
| Block_Reader (by concern) | `tests/Block/` | `BlockReaderMemoTest`, `BlockReaderLazyRefsTest`, `BlockReaderSchemaAwareAttrsTest`, `BlockReaderSourceDispatcherTest`, `BlockReaderExceptionGuardTest` |
| Block_Writer | (no direct class) | exercise via `tests/Block/BlockCrudTest.php` (facade) or `tests/REST/*` |
| Block enrichers (`includes/block-enrichers/*`) | `tests/Block/Enrichers/` | `*EnricherTest` (core block, core image, Yoast FAQ) |
| Post / term / media (v1.2) | `tests/Post/`, `tests/Media/` | `PostManagerTest`, `TermManagerTest`, `MediaManagerTest` |
| Preferences | `tests/Preferences/` | `PreferencesTest` |
| Instructions endpoint | `tests/Instructions/` | `InstructionsTest` |
| Yoast bridge | `tests/Integrations/` | `YoastBridgeTest`, `YoastScopingTest` |
| REST wire-up (per concern) | `tests/REST/` | `IfMatchTest`, `RestSummaryTest`, `WriteHandlerErrorEnvelopeTest`, `PostVisibilityTest`, `PatternsRefreshAuthTest`, `InstructionsRouteTest`, `YoastFaqEnricherRestTest` |
| **Connect / onboarding** | `tests/Connect/` | `ConnectPageTest`, `ConnectionsTest`, `AgentProvisionerTest`, `AgentRoleTest`, `AgentAuthTest`, `AgentRestCapabilityTest`, `AppPasswordIssuerTest`, `CredentialSealTest`, `McpbGeneratorTest`, `SettingsPageTabsTest`, `UninstallCleanupTest`, `UninstallEndToEndTest` |
| Adversarial (hostile input) | `tests/Security/` | `XssBypassTest`, `SsrfTest`, `IdorTest`, `BlockCommentInjectionTest`, `UploadsDisabledTest`, `ResourceExhaustionTest` |
| Adversarial (scale/chaos) | `tests/Stress/` | `MutationChaosTest`, `RateLimitBurstTest`, `DeepNestingStressTest`, `WideTreeStressTest`, `MaxBlockDepthTest`, `RefCollisionStressTest`, `PatternRecursionStressTest`, `AutoTransformCombinatoricsTest`, `UnicodePathologiesTest` |

## Test base + helpers

- **`BlockApiTestCase`** (`tests/BlockApiTestCase.php`, extends `WP_UnitTestCase`) — wires the full filter graph + SQLite drop-in. Use it when the test needs `$this->crud` / `$this->mutator` and the helpers `make_block_post()`, `block_tree()`, `block_tree_visible()` (and `block()` to build a block array). `set_up()` registers core blocks.
- **`RestControllerTestCase`** (extends `BlockApiTestCase`) — adds `$this->controller` and dispatches real `WP_REST_Request`s. Extend it for `tests/REST/*`.
- **Extend `WP_UnitTestCase` directly** for layers below `Block_CRUD` (e.g. `Block_Safety`, `Block_Inventory`, `Yoast_Bridge`) and for the Connect suite (which sets its own actors).
- Use `self::factory()->post->create(...)` / `->user->create(...)` for fixtures; the base teardown cleans up. Tests roll back the DB per test (transactions), so options/users set in one test don't leak.
- Reflection on private methods: the PHP 7.4 floor requires `->setAccessible( true )` (a no-op on 8.1+, but the suite must still pass under 7.4).
- Harness files: `bootstrap-wp.php`, `wp-tests-config.php`, `install-sqlite-dropin.php`, `phpunit.xml` (+ `phpunit/multisite.xml`, `phpunit/yoast.xml`).

## Connect / auth & credential testing patterns

The connect suite proves auth at the layer that actually broke historically — **exercise the real mechanism, not a proxy.**

- **Live auth chain** (`AgentAuthTest`): run the real `authenticate` filter chain, not a direct method call. A class-level unit test once stayed green while Application-Password auth was broken because nothing drove the live path.
- **End-to-end REST as the agent** (`AgentRestCapabilityTest`): set `$_SERVER['PHP_AUTH_USER']` / `['PHP_AUTH_PW']` / `['REMOTE_ADDR']`, null out `$GLOBALS['current_user']`, and dispatch a real `WP_REST_Request` — asserting the agent can hit the allowed routes and is forbidden from `manage_options` routes. (A prior test that unset `REMOTE_ADDR` caused a full-suite-only failure; always set it.)
- **Byline / `author_to_credit` tests**: simulate the authenticated app-password request by setting `$GLOBALS['wp_rest_application_password_uuid'] = $uuid` (the global `rest_get_authenticated_app_password()` reads), record `Connections::record_meta($uuid, …)`, then assert `create_post` sets `post_author`. Always `unset()` the global in `tear_down`.
- **Credential seal** (`CredentialSealTest`): round-trip, format/IV uniqueness, tamper/truncation/wrong-key rejection, single-use, expiry, and that the option is stored non-autoloaded (assert the autoload value is NOT in `{'yes','on','auto','auto-on'}` — WP 6.6+ stores `'off'`).
- **.mcpb manifest** (`McpbGeneratorTest`): assert every `user_config` option carries `type` + `title` + `description` (all three required by the v0.3 schema; the missing `description` was a real install-blocking bug).

## Discipline

- **`composer test` must finish green** at every commit. Never push a red branch.
- **`composer lint` (phpcs) 0 errors / 0 warnings** and **`composer analyze` (PHPStan) [OK]** at every commit. CI enforces all three.
- **Test count grows or stays — never shrinks** without an explicit "removing redundant coverage" commit message.
- Regression tests for a specific bug: state the symptom + the fix in the docblock so future readers can trace it.

## What an end-to-end regression test looks like

For a bug where the fix is in `Block_Writer::insert_pattern`:

1. The test lives in `tests/Block/BlockCrudTest.php` (the facade exposes `insert_pattern`).
2. Docblock states: "what was wrong, what the fix is, what this test pins."
3. Body sets up the failing condition (e.g. a draft `wp_block` CPT entry), calls the public method via the facade, asserts the `WP_Error` code matches the new contract.
4. No `// THIS IS THE BUG` narrative inside the body — that goes in the docblock.

Prove it has teeth: revert the fix, watch the test go red, restore the fix. A test that's green against the buggy code proves nothing.

## When not to write a test

- Pure typo fixes / whitespace / comment-only edits — no test.
- Defensive null-checks with no observable behaviour (e.g. wrapping `glob()` in `(array)` so `foreach` is safe under PHP 8+ when the dir is missing) — justify the skip in the commit message; the test setup costs more than the fix.
- Code already exercised by an existing test in a way that fails pre-fix — that existing test IS the regression test; note it in the commit.

Otherwise: every behaviour change ships with a test that fails pre-fix and passes post-fix.
