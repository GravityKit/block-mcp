import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: [
      // Legacy flat layout (migrated files stay here until fully moved)
      'src/__tests__/*.test.ts',
      // New layered layout
      'src/__tests__/unit/**/*.test.ts',
      'src/__tests__/tools/**/*.test.ts',
    ],
    // Integration tests live in src/__tests__/integration/ but are
    // explicitly excluded from the default run.  Use `npm run test:integration`
    // (vitest.integration.config.ts) to run them against a live WordPress
    // instance. They skip cleanly via describe.skipIf(skipUnlessLive()) when
    // env vars are absent, but keeping them out of this pattern avoids any
    // accidental HTTP calls during the default offline test suite.
    exclude: [
      'src/__tests__/integration/**',
    ],
  },
});
