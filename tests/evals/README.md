# Block MCP — AI evals + benchmarks

Live AI evaluation harness for the block-mcp tool surface. Each scenario
hands a natural-language task to an Anthropic model, lets it call the same
tool definitions production agents see, and grades the resulting block tree.

No live WordPress is touched at eval time — everything runs against a saved
`get_page_blocks` fixture.

## Layout

```
tests/evals/
├── fixtures/
│   └── gravitycalendar-creating-a-calendar.json   # saved page blocks
├── lib/
│   ├── fixture-store.ts   # in-memory fake of the WP REST surface
│   ├── tools.ts           # tool defs reused from src/tools/*.ts
│   └── runner.ts          # Anthropic SDK loop + grading
├── scenarios/
│   └── index.ts           # one entry per scenario; prompt + assertion
├── scripts/
│   └── fetch-fixture.ts   # one-time live fetch (needs GK_* env vars)
└── results/
    └── baseline.json      # committed run result; tracked over time
```

## Run

```bash
# One-time refresh of the fixture (requires GK_* env vars).
npm run eval:fixture-refresh

# Run all scenarios. Requires ANTHROPIC_API_KEY. Defaults to claude-haiku-4-5.
ANTHROPIC_API_KEY=... npm run eval

# Override model.
ANTHROPIC_API_KEY=... EVAL_MODEL=claude-sonnet-4-6 npm run eval
```

## What gets committed

`results/baseline.json` — pass/fail, turn count, latency, token use, tool-call
counts per scenario. Re-running overwrites it. Diff before/after a code
change to spot regressions.

## Adding a scenario

Append to `scenarios/index.ts`:

```ts
{
  name: 'my-scenario',
  prompt: (store) => `... task for the model ...`,
  assert: (store) => {
    const blocks = store.blocksSnapshot();
    if (someCheck) return { passed: false, reason: 'why' };
    return { passed: true, reason: 'ok' };
  },
}
```

Keep assertions structural (block name, ordinal, count). Don't grade on the
model's prose — it's nondeterministic.

## Why fixtures, not live

Live tests would burn revisions on a real post and depend on network state.
The fixture mirrors the exact JSON shape the real MCP returns; the model
can't tell the difference. Mutations apply to an in-memory copy, so each
scenario starts fresh.

When the live block-mcp adds new fields (e.g. BLOCK-5's `top_level_counter`,
BLOCK-8's `storage_mode`), re-run `eval:fixture-refresh` to capture them.
