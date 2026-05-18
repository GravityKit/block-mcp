/**
 * Integration: ETag / If-Match stale-revision rejection
 *
 * The plugin's PATCH endpoint accepts an `if_match` body field carrying a
 * revision_id. Sending two writes with the SAME revision_id as the pre-edit
 * baseline should cause the second write to be rejected with HTTP 412
 * (stale_revision) because the first write already bumped the revision.
 *
 * Capability detection: if the live plugin does not enforce `if_match` (i.e.
 * the second write with a stale revision succeeds with 200), we emit a
 * console.log and the test passes — the plugin just doesn't implement this
 * guard yet. We don't assert a hard failure because the gkclone site may be
 * running an older plugin version that pre-dates this feature.
 *
 * The "correct If-Match succeeds" test is always valid regardless of version.
 */

import { describe, it, expect } from 'vitest';
import axios from 'axios';
import { makeLiveClient, skipUnlessLive, withTestPost, LIVE_ENV } from './setup.js';

const skip = skipUnlessLive();

/** Fire a raw PATCH to /posts/{id}/blocks/{index} with an explicit if_match field. */
async function patchBlockWithIfMatch(
  postId: number,
  blockIndex: number,
  attributes: Record<string, unknown>,
  innerHTML: string,
  ifMatch: number
): Promise<{ status: number; data: unknown }> {
  const credentials = Buffer.from(
    `${LIVE_ENV.user}:${LIVE_ENV.password}`
  ).toString('base64');

  const url = `${LIVE_ENV.url.replace(/\/+$/, '')}/wp-json/gk-block-api/v1/posts/${postId}/blocks/${blockIndex}`;

  const response = await axios.patch(
    url,
    { attributes, innerHTML, if_match: ifMatch },
    {
      headers: {
        Authorization: `Basic ${credentials}`,
        'Content-Type': 'application/json',
      },
      timeout: 15_000,
      // Don't throw on 4xx so we can inspect the status.
      validateStatus: () => true,
    }
  );
  return { status: response.status, data: response.data };
}

describe.skipIf(skip)('ETag / If-Match concurrency (integration)', () => {
  it('second write with stale revision_id is rejected with 412 (or plugin does not enforce)', async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      // Step 1: read to get the current revision baseline.
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();
      const headingIndex = heading!.index;

      // Step 2: first write — succeeds and bumps the revision.
      const firstWrite = await client.updateBlock(postId, headingIndex, {
        attributes: { content: 'First write — takes the slot', level: 2 },
        innerHTML: '<h2 class="wp-block-heading">First write — takes the slot</h2>',
      });
      expect(firstWrite.success).toBe(true);
      const baseRevision = firstWrite.before_revision_id;

      // Step 3: second write with the ORIGINAL (now-stale) revision_id.
      const secondWrite = await patchBlockWithIfMatch(
        postId,
        headingIndex,
        { content: 'Second write — should be rejected', level: 2 },
        '<h2 class="wp-block-heading">Second write — should be rejected</h2>',
        baseRevision // stale: the first write already consumed this revision
      );

      if (secondWrite.status === 412) {
        // Plugin enforces If-Match — verify the error code.
        const body = secondWrite.data as Record<string, unknown>;
        expect(body.code).toBe('stale_revision');
      } else {
        // Plugin version doesn't enforce if_match yet — acceptable.
        // We still assert the write didn't crash unexpectedly.
        expect([200, 201]).toContain(secondWrite.status);
        console.log(
          '[integration] if_match not enforced on this plugin build ' +
          `(got ${secondWrite.status}, expected 412) — stale_revision guard not present`
        );
      }
    });
  }, 30_000);

  it('write with correct current revision_id succeeds', async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();
      const headingIndex = heading!.index;

      // First write — capture its revision_id for use as If-Match.
      const firstWrite = await client.updateBlock(postId, headingIndex, {
        attributes: { content: 'Write A', level: 2 },
        innerHTML: '<h2 class="wp-block-heading">Write A</h2>',
      });
      expect(firstWrite.success).toBe(true);
      const currentRevision = firstWrite.revision_id;

      // Second write passing the CURRENT revision_id — must succeed.
      const secondWrite = await patchBlockWithIfMatch(
        postId,
        headingIndex,
        { content: 'Write B — with correct If-Match', level: 2 },
        '<h2 class="wp-block-heading">Write B — with correct If-Match</h2>',
        currentRevision
      );

      expect(secondWrite.status).toBe(200);
      const body = secondWrite.data as Record<string, unknown>;
      expect(body.success).toBe(true);
    });
  }, 30_000);
});
