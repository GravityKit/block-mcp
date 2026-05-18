# AGENTS.md — gk-block-api PHPUnit suite

> Operational conventions for adding and editing tests. Production-code
> conventions live in `../AGENTS.md` and the repo-root README.

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

Tests go in the file that owns the class under test, in the directory that mirrors the production class:

| Production class | Test file |
|---|---|
| `Block_CRUD` | `tests/Block/BlockCrudTest.php` |
| `Block_Reader` | `tests/Block/BlockReader*Test.php` (one file per concern: Memo, LazyRefs, SchemaAwareAttrs, SourceDispatcher, ExceptionGuard) |
| `Block_Writer` (no direct test class) | exercise via `tests/Block/BlockCrudTest.php` (facade) or `tests/REST/*` |
| `Block_Mutator` | `tests/Block/BlockMutatorTest.php` |
| `Block_Inventory` | `tests/Block/BlockInventoryTest.php` if it exists; otherwise add coverage where the seam lives |
| `Post_Manager` | `tests/Post/PostManagerTest.php` |
| `Term_Manager` | `tests/Post/TermManagerTest.php` |
| `Media_Manager` | `tests/Media/MediaManagerTest.php` |
| `Yoast_Bridge` | `tests/Integrations/YoastBridgeTest.php` |
| Block enrichers (`includes/block-enrichers/*`) | `tests/Block/Enrichers/*EnricherTest.php` |
| REST controller wire-up | `tests/REST/*` (one file per concern: IfMatch, RestSummary, WriteHandlerErrorEnvelope, YoastFaqEnricherRest) |

Don't create grab-bag files like `PhpBugFixesRegressionTest.php`. Each regression test belongs in the file that owns the contract it pins.

## Test base + helpers

- Extend `BlockApiTestCase` when the test needs `$this->crud`, `$this->mutator`, `make_block_post()`, `block_tree()`, `block_tree_visible()`. The base class wires the full filter graph and the SQLite drop-in.
- Extend `WP_UnitTestCase` directly when the test is at a layer below `Block_CRUD` (e.g. `Block_Safety`, `Block_Inventory`, `Yoast_Bridge`).
- Use `self::factory()->post->create(...)` for fixture posts. The base teardown handles cleanup.
- For reflection on private methods: PHP 7.4 floor requires `->setAccessible( true )` (PHP 8.1+ makes it a no-op but the suite must still pass under 7.4).

## Discipline

- **`composer test` must finish green** at every commit. Never push a red branch.
- **`composer lint` must finish with 0 errors / 0 warnings** at every commit. CI enforces this.
- **`composer test` count grows or stays — never shrinks** without an explicit "removing redundant coverage" commit message.
- Regression tests for a specific bug: include the bug's commit sha (or PR #) in the docblock so future readers can trace the fix.

## What an end-to-end regression test looks like

For a bug where the fix is in `Block_Writer::insert_pattern`:

1. The test lives in `tests/Block/BlockCrudTest.php` (since the facade exposes `insert_pattern`).
2. Docblock states: "what was wrong, what the fix is, what this test pins."
3. Body sets up the failing condition (e.g. a draft wp_block CPT entry), calls the public method via the facade, asserts the WP_Error code matches the new contract.
4. No `// THIS IS THE BUG` narrative inside the body — that goes in the docblock.

## When not to write a test

- Pure typo fixes / whitespace / comment-only edits — no test.
- Defensive null-checks that have no observable behaviour (e.g. wrapping `glob()` in `(array)` to make foreach safe under PHP 8+ when the directory is missing) — justify the skip in the commit message; the cost of the test setup exceeds the cost of the fix.
- Code that is exercised by an existing test in a way that fails pre-fix — the existing test IS the regression test; just note it in the commit.

Otherwise: every behaviour change ships with a test that fails pre-fix and passes post-fix.
