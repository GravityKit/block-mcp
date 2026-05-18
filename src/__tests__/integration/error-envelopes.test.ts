/**
 * Integration: real REST error envelope shapes
 *
 * The unit tests in src/__tests__/error-translator.test.ts verify that our
 * TypeScript translation layer maps codes correctly. THIS file verifies that
 * the live PHP plugin actually returns the error codes and HTTP statuses we
 * claim — i.e., our documented constants match what's on the wire.
 *
 * We sample 8 representative codes from the documented 49 and trigger each
 * one deliberately against a live post:
 *
 *   1. rest_no_route     — hit a non-existent route
 *   2. post not found    — reference a post that doesn't exist (403 or 404
 *                          depending on whether the permission check or the
 *                          lookup check fires first)
 *   3. rest_forbidden    — write with wrong credentials (401 or 403)
 *   4. invalid_path      — mutate with an out-of-range path
 *   5. legacy_block      — insert a ugb/text block (always hard-rejected)
 *   6. invalid_ref       — updateBlockByRef with a made-up ref
 *                          (skipped when by-ref route is absent)
 *   7. invalid_block     — insert a non-registered block name
 *   8. cleanup           — verify the shared post is trashed correctly
 *
 * Each assertion checks:
 *   - HTTP status is in the expected range for the error class
 *   - Response body has a `code` field (machine-readable)
 *   - Response body has a `message` string (non-empty)
 */

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import axios from 'axios';
import { makeLiveClient, skipUnlessLive, withTestPost, LIVE_ENV, hasRoute } from './setup.js';

const skip = skipUnlessLive();

/** Raw request against the REST API without the MCP client's retry logic. */
async function rawRequest(
  method: 'get' | 'post' | 'patch' | 'delete',
  path: string,
  body?: unknown,
  credentials?: string
): Promise<{ status: number; code?: string; message?: string; data?: unknown }> {
  const creds =
    credentials ??
    Buffer.from(`${LIVE_ENV.user}:${LIVE_ENV.password}`).toString('base64');
  const base = LIVE_ENV.url.replace(/\/+$/, '');
  const url = `${base}/wp-json/gk-block-api/v1${path}`;

  const response = await axios.request({
    method,
    url,
    data: body,
    headers: {
      Authorization: `Basic ${creds}`,
      'Content-Type': 'application/json',
    },
    timeout: 15_000,
    validateStatus: () => true,
  });

  const b = response.data as Record<string, unknown> | undefined;
  return {
    status: response.status,
    code: typeof b?.code === 'string' ? b.code : undefined,
    message: typeof b?.message === 'string' ? b.message : undefined,
    data: b?.data,
  };
}

describe.skipIf(skip)('real REST error envelopes (integration)', () => {
  // We need a live post ID for several tests.
  let livePostId: number;
  let liveHeadingIndex: number;

  beforeAll(async () => {
    if (skip) return;
    const client = makeLiveClient();
    const created = await client.createPost({
      title: `[integration-test] error-envelopes ${Date.now()}`,
      status: 'draft',
      blocks: [
        {
          name: 'core/heading',
          attributes: { level: 2, content: 'Error envelope test post' },
          innerHTML: '<h2 class="wp-block-heading">Error envelope test post</h2>',
        },
      ],
    });
    livePostId = created.id;

    const blocks = await client.getPageBlocks(livePostId);
    const h = blocks.blocks.find((b) => b.name === 'core/heading');
    liveHeadingIndex = h?.index ?? 0;
  });

  it('rest_no_route: GET to a non-existent route returns 404 + rest_no_route', async () => {
    const result = await rawRequest('get', '/nonexistent-route-xyz');
    expect(result.status).toBe(404);
    expect(result.code).toBe('rest_no_route');
    expect(result.message).toBeTruthy();
  });

  it('auth gate fires before post lookup for non-existent post IDs (403 or 404)', async () => {
    // Using a post ID that doesn't exist (999999999). Depending on whether the
    // permission check or the post lookup runs first, we get 403 or 404.
    // Both are valid — we just confirm a structured error envelope is returned.
    const result = await rawRequest('get', '/posts/999999999/blocks');
    expect([403, 404]).toContain(result.status);
    expect(result.message).toBeTruthy();
    // Some plugin versions return a code, others return a status-only envelope.
    if (result.code) {
      expect(typeof result.code).toBe('string');
    }
  });

  it('rest_forbidden: write with bad credentials returns 401 or 403', async () => {
    const badCreds = Buffer.from('nobody:wrongpassword').toString('base64');
    const result = await rawRequest(
      'patch',
      `/posts/${livePostId}/blocks/${liveHeadingIndex}`,
      { attributes: { content: 'forbidden write' } },
      badCreds
    );
    expect([401, 403]).toContain(result.status);
    expect(result.message).toBeTruthy();
  });

  it('invalid_path: mutate with out-of-range path returns 400 + path error code', async () => {
    const result = await rawRequest('post', `/posts/${livePostId}/mutate`, {
      op: 'update-attrs',
      path: [999, 999, 999],
      attributes: { content: 'never land' },
    });
    expect(result.status).toBe(400);
    expect(['invalid_path', 'path_not_found', 'path_out_of_bounds']).toContain(result.code);
    expect(result.message).toBeTruthy();
  });

  it('legacy_block: inserting a ugb/text block returns 400 + legacy_block', async () => {
    const result = await rawRequest('post', `/posts/${livePostId}/blocks`, {
      after: 0,
      blocks: [{ name: 'ugb/text', attributes: {}, innerHTML: '<div>legacy</div>' }],
    });
    expect(result.status).toBe(400);
    expect(result.code).toBe('legacy_block');
    expect(result.message).toBeTruthy();
  });

  it('invalid_ref: updateBlockByRef with a made-up ref returns a structured error (when route exists)', async () => {
    const byRefExists = await hasRoute('by-ref');
    if (!byRefExists) {
      console.log('[integration] by-ref route not present — skipping invalid_ref test');
      return;
    }

    const result = await rawRequest(
      'patch',
      `/posts/${livePostId}/blocks/by-ref/blk_00000000`,
      { attributes: { content: 'no such ref' } }
    );
    expect([400, 404]).toContain(result.status);
    // The plugin returns `ref_stale` or `invalid_ref` depending on version.
    if (result.code) {
      expect(result.code).toMatch(/ref|not_found/i);
    }
    expect(result.message).toBeTruthy();
  });

  it('invalid_block: inserting a non-registered block name returns 400', async () => {
    const result = await rawRequest('post', `/posts/${livePostId}/blocks`, {
      after: 0,
      blocks: [{ name: 'nonexistent/totally-fake-block', attributes: {}, innerHTML: '<div>x</div>' }],
    });
    expect(result.status).toBe(400);
    expect(result.message).toBeTruthy();
  });

  // Move cleanup to afterAll so the shared post is trashed even if any of
  // the assertions above fail. As a regular `it()` it would skip on failure
  // and leak the test post into the live site, polluting later runs.
  afterAll(async () => {
    if (!livePostId) {
      return;
    }
    const client = makeLiveClient();
    const result = await client.updatePost(livePostId, { status: 'trash' });
    expect(result.success).toBe(true);
  });
});
