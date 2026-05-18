/**
 * Integration test setup helpers.
 *
 * Everything in this file is tree-shaken at unit-test time — nothing here
 * imports from the test-runner directly, so it is safe to import in any
 * integration test file even before `skipUnlessLive()` gates the describe
 * block.
 *
 * Required env vars (all absent → every integration test skips cleanly):
 *   WORDPRESS_URL           — e.g. http://localhost:7701
 *   WORDPRESS_USER          — e.g. admin
 *   WORDPRESS_APP_PASSWORD  — Application Password (spaces OK)
 *
 * Optional:
 *   INTEGRATION_POST_TITLE_PREFIX  — defaults to "[integration-test]"
 *     Used to namespace throwaway posts for cleanup safety.
 */

import { WordPressBlockClient } from '../../client.js';
import type { BlockMCPConfig } from '../../types.js';
import axios from 'axios';

// ── Environment probe ──────────────────────────────────────────────────────

export const LIVE_ENV = {
  url: process.env.WORDPRESS_URL ?? '',
  user: process.env.WORDPRESS_USER ?? '',
  password: process.env.WORDPRESS_APP_PASSWORD ?? '',
  prefix: process.env.INTEGRATION_POST_TITLE_PREFIX ?? '[integration-test]',
};

/** True when all required env vars are present. */
export const isLive: boolean =
  Boolean(LIVE_ENV.url) && Boolean(LIVE_ENV.user) && Boolean(LIVE_ENV.password);

/**
 * Returns the boolean skip flag for `describe.skipIf(skip)(...)`.
 *
 * Usage in every integration test file:
 *
 *   const skip = skipUnlessLive();
 *   describe.skipIf(skip)('my suite', () => { ... });
 */
export function skipUnlessLive(): boolean {
  return !isLive;
}

// ── Plugin capability detection ───────────────────────────────────────────

/**
 * Cached set of route prefixes registered on the live plugin.
 * Populated lazily on first call to `getRegisteredRoutes()`.
 */
let _routeCache: string[] | null = null;

/**
 * Fetch the route list from the live WP instance (cached for the process).
 * Returns an empty array when the site is unreachable.
 */
export async function getRegisteredRoutes(): Promise<string[]> {
  if (_routeCache !== null) return _routeCache;
  if (!isLive) return (_routeCache = []);

  try {
    const creds = Buffer.from(`${LIVE_ENV.user}:${LIVE_ENV.password}`).toString('base64');
    const base = LIVE_ENV.url.replace(/\/+$/, '');
    const resp = await axios.get(`${base}/wp-json/gk-block-api/v1`, {
      headers: { Authorization: `Basic ${creds}` },
      timeout: 8000,
    });
    _routeCache = Object.keys((resp.data as { routes?: Record<string, unknown> }).routes ?? {});
  } catch {
    _routeCache = [];
  }
  return _routeCache;
}

/**
 * True when the live plugin has a specific route.
 * `pattern` is treated as a substring when it's a plain string, or
 * as a regex when it starts with `/`.  Pass a regex string like `'/block$'`
 * to avoid false positives where 'block' matches 'blocks'.
 */
export async function hasRoute(pattern: string): Promise<boolean> {
  const routes = await getRegisteredRoutes();
  if (pattern.startsWith('/') && pattern.endsWith('$')) {
    // Treat as a regex anchored at the end of the route segment.
    const re = new RegExp(pattern.slice(1)); // remove leading /
    return routes.some((r) => re.test(r));
  }
  return routes.some((r) => r.includes(pattern));
}

// ── Client factory ─────────────────────────────────────────────────────────

/**
 * Build a real WordPressBlockClient pointed at the live instance.
 * Throws (and the test file will error out) if called without env vars — but
 * callers should always guard with `describe.skipIf(skipUnlessLive())` so
 * this never executes in offline runs.
 */
export function makeLiveClient(): WordPressBlockClient {
  if (!isLive) {
    throw new Error(
      'makeLiveClient() called without WORDPRESS_URL/WORDPRESS_USER/WORDPRESS_APP_PASSWORD. ' +
      'Guard with describe.skipIf(skipUnlessLive()).'
    );
  }
  const config: BlockMCPConfig = {
    wordpress_url: LIVE_ENV.url,
    auth: {
      username: LIVE_ENV.user,
      application_password: LIVE_ENV.password,
    },
  };
  return new WordPressBlockClient(config);
}

// ── Throwaway post lifecycle ───────────────────────────────────────────────

/** IDs of posts created during this test run, for emergency globalTeardown sweeps. */
const createdPostIds = new Set<number>();

/**
 * Create a throwaway draft post, run the callback, then delete it — even on
 * failure. Returns whatever the callback returns so tests can use it as their
 * assertion value.
 *
 * @param client  Live WordPressBlockClient
 * @param callback  Async function receiving the new post_id
 */
export async function withTestPost<T>(
  client: WordPressBlockClient,
  callback: (postId: number) => Promise<T>
): Promise<T> {
  const title = `${LIVE_ENV.prefix} ${Date.now()}`;
  const created = await client.createPost({
    title,
    status: 'draft',
    // Seed with a minimal block so we always have something to read/mutate.
    blocks: [
      {
        name: 'core/heading',
        attributes: { level: 2, content: 'Integration test heading' },
        innerHTML: '<h2 class="wp-block-heading">Integration test heading</h2>',
      },
      {
        name: 'core/paragraph',
        attributes: { content: 'Integration test paragraph.' },
        innerHTML: '<p>Integration test paragraph.</p>',
      },
    ],
  });

  const postId = created.id;
  createdPostIds.add(postId);

  try {
    return await callback(postId);
  } finally {
    await deleteTestPost(client, postId);
  }
}

/**
 * Delete a throwaway post, tolerating 404s and 429s (already gone or rate
 * limited). Removes the post from the in-process tracker.
 */
export async function deleteTestPost(
  client: WordPressBlockClient,
  postId: number
): Promise<void> {
  try {
    await client.updatePost(postId, { status: 'trash' });
  } catch (err: unknown) {
    // 404 / not found → already gone, fine.
    // 429 → rate limited — log and move on so cleanup doesn't mask test failure.
    const msg = err instanceof Error ? err.message : String(err);
    if (!msg.includes('404') && !msg.includes('not found') && !msg.includes('429') && !msg.includes('rate_limit')) {
      console.warn(`[integration] Failed to trash post ${postId}: ${msg}`);
    }
  } finally {
    createdPostIds.delete(postId);
  }
}

/**
 * Sweep any posts that leaked (e.g. afterEach didn't fire because the process
 * crashed). Designed to be called from Vitest globalTeardown.
 *
 * Safe to call even when `isLive` is false — returns immediately.
 */
export async function cleanupTestPosts(): Promise<void> {
  if (!isLive) return;
  if (createdPostIds.size === 0) return;

  const client = makeLiveClient();
  const ids = Array.from(createdPostIds);
  console.warn(`[integration] globalTeardown: sweeping ${ids.length} leaked post(s): ${ids.join(', ')}`);
  await Promise.allSettled(ids.map((id) => deleteTestPost(client, id)));
}
