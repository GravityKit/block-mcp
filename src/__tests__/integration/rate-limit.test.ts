/**
 * Integration: rate-limit enforcement
 *
 * The plugin allows 10 writes/minute per post (sliding 60-second window).
 * Firing 11 sequential writes to the same post must cause the 11th to return
 * HTTP 429 with code `rate_limit_exceeded`.
 *
 * Determinism notes:
 *   - We drive real writes (not fake timers); the plugin's transient-based
 *     counter is authoritative.
 *   - We do NOT test bucket reset (that would require a real 60-second sleep).
 *     We verify only that the limit fires.
 *   - To avoid the retry logic in WordPressBlockClient absorbing the 429
 *     (the client *does* retry 429s as per isRetryable()), we fire raw axios
 *     calls for the overflow write so we see the raw 429.
 *
 * Cleanup: the throwaway post is trashed in teardown regardless of failure.
 * The rate-limit transient expires on its own within 60 seconds.
 */

import { describe, it, expect } from 'vitest';
import axios from 'axios';
import { makeLiveClient, skipUnlessLive, withTestPost, LIVE_ENV } from './setup.js';

const skip = skipUnlessLive();

const RATE_LIMIT = 10; // must match Block_CRUD::WRITE_LIMIT in the plugin

/** Fire a raw PATCH without the MCP client's retry logic. */
async function rawPatch(
  postId: number,
  blockIndex: number,
  suffix: number
): Promise<{ status: number; code?: string }> {
  const credentials = Buffer.from(
    `${LIVE_ENV.user}:${LIVE_ENV.password}`
  ).toString('base64');

  const url = `${LIVE_ENV.url.replace(/\/+$/, '')}/wp-json/gk-block-api/v1/posts/${postId}/blocks/${blockIndex}`;

  const response = await axios.patch(
    url,
    {
      attributes: { content: `Rate-limit write ${suffix}`, level: 2 },
      innerHTML: `<h2 class="wp-block-heading">Rate-limit write ${suffix}</h2>`,
    },
    {
      headers: {
        Authorization: `Basic ${credentials}`,
        'Content-Type': 'application/json',
      },
      timeout: 15_000,
      validateStatus: () => true,
    }
  );

  const body = response.data as Record<string, unknown> | undefined;
  return {
    status: response.status,
    code: typeof body?.code === 'string' ? body.code : undefined,
  };
}

describe.skipIf(skip)('rate-limit enforcement (integration)', () => {
  it(`fires rate_limit_exceeded (429) after ${RATE_LIMIT} writes in 60 seconds`, async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();
      const headingIndex = heading!.index;

      // Fire RATE_LIMIT writes via raw axios (no retry layer).
      // We expect all of them to succeed (200).
      for (let i = 1; i <= RATE_LIMIT; i++) {
        const result = await rawPatch(postId, headingIndex, i);
        expect(result.status, `Write #${i} should succeed`).toBe(200);
      }

      // The (RATE_LIMIT + 1)-th write must hit the cap.
      const overflow = await rawPatch(postId, headingIndex, RATE_LIMIT + 1);
      expect(overflow.status).toBe(429);
      expect(overflow.code).toBe('rate_limit_exceeded');
    });
  // Allow generous time: RATE_LIMIT sequential network round-trips + margin.
  }, 120_000);
});
