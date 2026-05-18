/**
 * Vitest globalTeardown for integration tests.
 *
 * Sweeps any throwaway posts that leaked due to unexpected process exit,
 * test timeouts, or beforeAll failures. Only fires when env vars are set
 * (cleanupTestPosts() is a no-op otherwise).
 */
import { cleanupTestPosts } from './setup.js';

export default async function teardown(): Promise<void> {
  await cleanupTestPosts();
}
