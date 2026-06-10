import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import * as path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));

/**
 * Scoped Playwright config for the Connect-doc e2e.
 *
 * It drives the live "Connect an AI Assistant" UI on a running WordPress install
 * (default: the gkclone dev site at http://localhost:7701), asserts the current
 * flow, and writes the doc's screenshots to ./screenshots.
 *
 * This is intentionally NOT part of `npm test` (vitest only matches
 * `tests/**\/*.test.ts`; this file is `*.e2e.ts`). Run it explicitly with
 * `npm run test:docs` against a site that has gk-block-mcp active — see README.md.
 *
 * Env:
 *   GK_DOCS_BASE_URL  Site under test. Default http://localhost:7701 (gkclone).
 *   GK_DOCS_USER      Administrator login. Default "admin".
 *   GK_DOCS_PASS      Administrator password. Required.
 */
export default defineConfig({
  testDir: here,
  testMatch: '**/*.e2e.ts',
  outputDir: './.playwright-artifacts',
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.GK_DOCS_BASE_URL || 'http://localhost:7701',
    viewport: { width: 1340, height: 1200 },
    // 2x keeps these card/table-sized clips retina-crisp while staying under the
    // 2000px longest-side cap natively (at 3x a ~900px CSS card would exceed it
    // and need downscaling); the widest shot is still capped via sharp.
    deviceScaleFactor: 2,
    ignoreHTTPSErrors: true,
    screenshot: 'off',
  },
  // Don't spread devices['Desktop Chrome'] — its preset forces a 1280x720
  // viewport at deviceScaleFactor 1, clobbering the viewport + DPR set above.
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
