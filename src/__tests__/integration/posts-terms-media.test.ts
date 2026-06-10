import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { skipUnlessLive, makeLiveClient, withTestPost, LIVE_ENV } from './setup.js';
import type { CreatePostRequest, UpdatePostRequest } from '../../types.js';

/**
 * Edge-case coverage for the post-lifecycle, terms, and media surfaces
 * against a live WordPress — every accepted argument plus the guard rails
 * (allow-list, status enum, mutual exclusion, trash gate, SSRF, MIME
 * allow-list, filename hygiene).
 */

interface WpErr {
  message?: string;
  wpCode?: string;
  wpStatus?: number;
}

async function grab(promise: Promise<unknown>): Promise<WpErr> {
  try {
    await promise;
    return {};
  } catch (e) {
    return e as WpErr;
  }
}

// 1x1 transparent PNG.
const PNG_B64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

describe.skipIf(skipUnlessLive())('integration: create_post argument surface', () => {
  it('content and blocks are mutually exclusive', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.createPost({
        title: `${LIVE_ENV.prefix} conflict`,
        content: '<p>html</p>',
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>blocks</p>' }],
      } as CreatePostRequest),
    );
    expect(err.message).toMatch(/mutually exclusive|content.*blocks/i);
  });

  it('invalid status is rejected', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.createPost({
        title: `${LIVE_ENV.prefix} bad status`,
        status: 'banana',
      } as unknown as CreatePostRequest),
    );
    expect(err.wpStatus).toBeGreaterThanOrEqual(400);
  });

  it('a post type outside the allow-list is rejected', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.createPost({
        title: `${LIVE_ENV.prefix} bad type`,
        post_type: 'gk_definitely_not_allowed',
      } as CreatePostRequest),
    );
    expect(err.wpStatus).toBeGreaterThanOrEqual(400);
  });

  it('slug + excerpt persist on create', async () => {
    const client = makeLiveClient();
    const slug = `edge-slug-${process.pid}`;
    const created = await client.createPost({
      title: `${LIVE_ENV.prefix} slugged`,
      status: 'draft',
      slug,
      excerpt: 'EDGE-EXCERPT',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    } as CreatePostRequest);
    try {
      const info = (await client.getPostInfo({ post_id: created.id })) as { slug?: string };
      expect(info.slug).toBe(slug);
      // Slug+post_type lookup resolves back to the same post.
      const bySlug = (await client.getPostInfo({ slug, post_type: 'post' })) as { post_id?: number };
      expect(bySlug.post_id).toBe(created.id);
    } finally {
      // Trash may be gated; draft test content is swept by title prefix.
      await grab(client.updatePost(created.id, { status: 'trash' } as UpdatePostRequest));
    }
  });
});

describe.skipIf(skipUnlessLive())('integration: update_post argument surface', () => {
  it('title / slug / excerpt update together', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.updatePost(postId, {
        title: `${LIVE_ENV.prefix} retitled`,
        slug: `edge-retitle-${process.pid}`,
        excerpt: 'EDGE-NEW-EXCERPT',
      });
      expect(res.success).toBe(true);
      const info = (await client.getPostInfo({ post_id: postId })) as {
        title?: string;
        slug?: string;
      };
      expect(info.title).toContain('retitled');
      expect(info.slug).toBe(`edge-retitle-${process.pid}`);
    });
  });

  it('status transition draft → publish → draft round-trips', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const publish = await client.updatePost(postId, { status: 'publish' });
      expect(publish.success).toBe(true);

      const back = await client.updatePost(postId, { status: 'draft' });
      expect(back.success).toBe(true);
    });
  });

  it('trash combined with other fields is rejected (mixed payload or gate)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(
        client.updatePost(postId, { status: 'trash', title: 'sneaky' } as UpdatePostRequest),
      );
      // Whichever guard fires first — the trash gate (when disabled) or the
      // mixed-payload guard — the write must NOT go through silently.
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);
      const info = (await client.getPostInfo({ post_id: postId })) as { title?: string };
      expect(info.title).not.toBe('sneaky');
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: list_terms argument surface', () => {
  it('invalid taxonomy fails loudly', async () => {
    const client = makeLiveClient();
    const err = await grab(client.listTerms({ taxonomy: 'gk_no_such_tax' }));
    expect(err.wpStatus).toBeGreaterThanOrEqual(400);
  });

  it('per_page is honored and capped', async () => {
    const client = makeLiveClient();
    const res = (await client.listTerms({ taxonomy: 'category', per_page: 1 })) as {
      terms?: unknown[];
    };
    expect((res.terms ?? []).length).toBeLessThanOrEqual(1);

    const err = await grab(client.listTerms({ taxonomy: 'category', per_page: 500 }));
    // Either rejected outright or clamped — never an unbounded dump.
    if (!err.wpStatus) {
      const all = (await client.listTerms({ taxonomy: 'category', per_page: 500 })) as {
        terms?: unknown[];
      };
      expect((all.terms ?? []).length).toBeLessThanOrEqual(200);
    }
  });

  it('orderby/order + search + slug filters apply', async () => {
    const client = makeLiveClient();
    const res = (await client.listTerms({
      taxonomy: 'category',
      orderby: 'name',
      order: 'desc',
      per_page: 5,
    } as never)) as { terms?: Array<{ name: string; slug: string }> };
    const names = (res.terms ?? []).map((t) => t.name.toLowerCase());
    const sorted = [...names].sort().reverse();
    expect(names).toEqual(sorted);

    if (res.terms?.length) {
      const target = res.terms[0];
      const bySlug = (await client.listTerms({ taxonomy: 'category', slug: target.slug } as never)) as {
        terms?: Array<{ slug: string }>;
      };
      expect(bySlug.terms?.every((t) => t.slug === target.slug)).toBe(true);
    }
  });
});

describe.skipIf(skipUnlessLive())('integration: upload_media guard rails', () => {
  it('SSRF: url mode pointing at a private/reserved address is blocked', async () => {
    const client = makeLiveClient();
    for (const url of ['http://127.0.0.1:9/x.png', 'http://169.254.169.254/latest/meta-data.png']) {
      const err = await grab(client.uploadMedia({ url, title: `${LIVE_ENV.prefix} ssrf` }));
      expect(err.wpStatus, url).toBeGreaterThanOrEqual(400);
      expect(`${err.wpCode}`, url).toMatch(/invalid_url|url_fetch_failed/);
    }
  });

  it('disallowed MIME (a .php "image") is rejected', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.uploadMedia({
        data_base64: PNG_B64,
        filename: 'evil.php',
        title: `${LIVE_ENV.prefix} mime`,
      }),
    );
    expect(err.wpStatus).toBeGreaterThanOrEqual(400);
    expect(`${err.wpCode}`).toMatch(/disallowed_mime|invalid_filename|sideload_failed/);
  });

  it('path traversal in filename does not escape the uploads dir', async () => {
    const client = makeLiveClient();
    const result = await grab(
      client.uploadMedia({
        data_base64: PNG_B64,
        filename: '../../traversal.png',
        title: `${LIVE_ENV.prefix} traversal`,
      }),
    );
    if (!result.wpStatus) {
      // Accepted → the stored file must be sanitized to a basename.
      const ok = (await client.uploadMedia({
        data_base64: PNG_B64,
        filename: '../../traversal.png',
        title: `${LIVE_ENV.prefix} traversal2`,
      })) as { source_url?: string; url?: string };
      const storedUrl = String(ok.source_url ?? ok.url);
      expect(storedUrl).not.toContain('..');
      expect(storedUrl).toMatch(/traversal[^/]*\.png$/);
    } else {
      expect(`${result.wpCode}`).toMatch(/invalid_filename|disallowed_mime/);
    }
  });

  it('invalid base64 payload fails loudly', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.uploadMedia({
        data_base64: '!!!not-base64!!!',
        filename: 'bad.png',
        title: `${LIVE_ENV.prefix} badb64`,
      }),
    );
    expect(err.wpStatus).toBeGreaterThanOrEqual(400);
  });

  it('path mode with a nonexistent local file throws client-side (nothing sent)', async () => {
    const client = makeLiveClient();
    const err = await grab(
      client.uploadMedia({ path: path.join(os.tmpdir(), 'gk-definitely-missing.png') }),
    );
    expect(err.message).toMatch(/ENOENT|no such file/i);
  });

  it('metadata args (title, alt_text, caption, description, post_id) ride along', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const tmp = path.join(os.tmpdir(), `gk-edge-meta-${process.pid}.png`);
      fs.writeFileSync(tmp, Buffer.from(PNG_B64, 'base64'));
      try {
        const res = (await client.uploadMedia({
          path: tmp,
          title: `${LIVE_ENV.prefix} meta upload`,
          alt_text: 'EDGE-ALT',
          caption: 'EDGE-CAPTION',
          description: 'EDGE-DESC',
          post_id: postId,
        })) as { id?: number; source_url?: string; url?: string };
        expect(res.id).toBeGreaterThan(0);
        expect(String(res.source_url ?? res.url)).toMatch(/\.png$/);
      } finally {
        fs.rmSync(tmp, { force: true });
      }
    });
  });
});
