/**
 * Vitest config for the live-WordPress integration test suite.
 *
 * Run with:
 *   npm run test:integration
 *
 * Requires env vars:
 *   WORDPRESS_URL          — e.g. http://localhost:7701
 *   WORDPRESS_USER         — WordPress username
 *   WORDPRESS_APP_PASSWORD — Application Password
 *
 * When those vars are absent every test in this suite skips via
 * describe.skipIf(skipUnlessLive()), so the run exits 0 cleanly.
 */
import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  test: {
    // Only run the integration directory — never the unit/tool tests.
    include: ['src/__tests__/integration/**/*.test.ts'],
    // Exclude the default test dirs so this config is truly isolated.
    exclude: [
      'src/__tests__/*.test.ts',
      'src/__tests__/unit/**',
      'src/__tests__/tools/**',
    ],
    // One test file at a time: each creates/deletes its own posts and
    // rate-limit state; parallelism against the same WP post would race.
    // fileParallelism: false prevents two files from hitting the same
    // per-post rate-limit bucket simultaneously.
    fileParallelism: false,
    // Pool threads (default) is fine since fileParallelism is off.
    // Long integration timeouts — network round-trips to WP can be slow.
    testTimeout: 60_000,
    hookTimeout: 30_000,
    // Sweep leaked posts at the end of the run. Vitest does NOT support a
    // `globalTeardown` config key — the teardown is returned from globalSetup
    // and Vitest runs it after the last test. The setup module lives at
    // src/__tests__/integration/global-setup.ts and returns the teardown
    // function from its default export.
    globalSetup: [
      path.resolve(__dirname, 'src/__tests__/integration/global-setup.ts'),
    ],
  },
  resolve: {
    // Mirror tsconfig so .js imports resolve to the TypeScript source.
    alias: {
      // Vitest resolves .js extensions in ESM mode automatically when
      // moduleResolution is bundler; this is a belt-and-suspenders alias.
    },
  },
});
