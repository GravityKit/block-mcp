import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: [
      // Legacy flat layout (migrated files stay here until fully moved)
      'src/__tests__/*.test.ts',
      // New layered layout
      'src/__tests__/unit/**/*.test.ts',
      'src/__tests__/tools/**/*.test.ts',
      'src/__tests__/integration/**/*.test.ts',
    ],
  },
});
