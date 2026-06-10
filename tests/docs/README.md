# Connect doc — e2e + screenshot generator

This folder holds the **source** for the published "Connect an AI Assistant to
Your Site" doc (gravitykit.com/docs/connect-ai-assistant/, the `help_url` the
plugin's Connect screen links to) plus the Playwright e2e that keeps it honest.

| File | What it is |
|------|------------|
| `connect-ai-assistant.md` | The doc body. Embeds `screenshots/*.png`. Edit this, then publish. |
| `connect-ai-assistant.e2e.ts` | Drives the live Connect UI: asserts the current flow **and** captures the screenshots. |
| `playwright.config.ts` | Scoped Playwright config (own `npm run test:docs`; not part of `npm test`). |
| `screenshots/` | Generated PNGs (git-ignored). Produced by the e2e; uploaded to the live doc on publish. |

## Why this exists

The first version of this doc was hand-captured and went stale — it documented
a ChatGPT option that was removed and predated the `gk-block-api` →
`gk-block-mcp` rename. Codifying the captures as an e2e means the screenshots
(and the UI assertions behind them) regenerate from the real plugin, so the doc
can't silently drift again.

## Running it

Needs a running WordPress site with **gk-block-mcp active** and admin
credentials. The default target is the local **gkclone** site.

```bash
# one-time, from the repo root
npm install
npx playwright install chromium

# generate screenshots + assert the flow
GK_DOCS_PASS='your-admin-password' npm run test:docs
```

Environment:

| Var | Default | Notes |
|-----|---------|-------|
| `GK_DOCS_BASE_URL` | `http://localhost:7701` | The site under test (gkclone direct port). |
| `GK_DOCS_USER` | `admin` | Administrator login. |
| `GK_DOCS_PASS` | — | Administrator password. **Required.** |

Output lands in `tests/docs/screenshots/`:
`connect-screen.png`, `command-artifact.png`, `ai-prompt-artifact.png`,
`approve-screen.png`, `connected-state.png`.

## Publishing

The doc lives on gravitykit.com as a BetterDocs `docs` post and is edited via
Block MCP (see the repo `CLAUDE.md` → Documentation Publishing). To publish or
refresh it:

1. Run the e2e to regenerate `screenshots/`.
2. Upload the screenshots to the live media library (Block MCP `upload_media`).
3. Create/update the `docs` post at slug `connect-ai-assistant` from
   `connect-ai-assistant.md`, pointing the image blocks at the uploaded URLs.

Keep the draft until the screenshots match the shipping UI.
