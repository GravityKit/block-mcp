/**
 * Vitest globalSetup for integration tests.
 *
 * Vitest does NOT support a top-level `globalTeardown` config key — teardown
 * must be returned from the globalSetup function. The default-exported
 * function below runs once before the suite (no setup work yet) and returns
 * a teardown that sweeps any throwaway posts that leaked due to unexpected
 * process exit, test timeouts, or beforeAll failures. Only fires when env
 * vars are set (cleanupTestPosts() is a no-op otherwise).
 */
import { cleanupTestPosts } from './setup.js';

export default function setup(): () => Promise<void> {
  return async function teardown(): Promise<void> {
    await cleanupTestPosts();
  };
}
