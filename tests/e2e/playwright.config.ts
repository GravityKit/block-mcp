import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import * as path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));

/**
 * Scoped Playwright config for the Block MCP admin-UI regression e2e.
 *
 * Drives the live "Settings → Block MCP" admin on a running WordPress install
 * and asserts behaviours that only manifest in a real browser — the namespace
 * tier-score / replacement-map table interactions (adding rows, save
 * persistence, validation feedback) that PHPUnit can't exercise because they
 * depend on the inline admin JavaScript.
 *
 * Like the docs e2e, this is intentionally NOT part of `npm test` (vitest only
 * matches `tests/**\/*.test.ts`; this file is `*.e2e.ts`). Run it explicitly
 * against a site that has gk-block-mcp active — the fastest path is a Siteminter
 * mint:
 *
 *   GK_E2E_BASE_URL=http://localhost:8901 npm run test:e2e
 *
 * Env:
 *   GK_E2E_BASE_URL  Site under test. Default http://localhost:8901.
 *   GK_E2E_USER      Administrator login. Default "admin" (Siteminter default).
 *   GK_E2E_PASS      Administrator password. Default "admin" (Siteminter default).
 */
export default defineConfig({
  testDir: here,
  testMatch: '**/*.e2e.ts',
  outputDir: './.playwright-artifacts',
  fullyParallel: false,
  workers: 1,
  // Real save round-trips through options.php plus a cold first request need
  // headroom beyond Playwright's 30s default.
  timeout: 60_000,
  reporter: [['list']],
  use: {
    baseURL: process.env.GK_E2E_BASE_URL || 'http://localhost:8901',
    viewport: { width: 1340, height: 1200 },
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
