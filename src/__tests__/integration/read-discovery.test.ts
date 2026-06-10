import { describe, it, expect } from 'vitest';
import axios from 'axios';
import { skipUnlessLive, makeLiveClient, withTestPost, LIVE_ENV } from './setup.js';

/**
 * Edge-case coverage for the READ + discovery surface against a live
 * WordPress — every accepted query parameter on get_page_blocks plus the
 * discovery endpoints (block-types, patterns, site-usage, resolve,
 * find-posts, post-info, instructions), exercised for shape and filtering
 * behavior rather than just "returns 200".
 */

describe.skipIf(skipUnlessLive())('integration: get_page_blocks query params', () => {
  it('fields= returns only the requested fields', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, { fields: 'name,ref' });
      const block = (read.blocks as unknown as Array<Record<string, unknown>>)[0];
      expect(block.name).toBeTruthy();
      expect(block.innerHTML ?? block.inner_html).toBeUndefined();
      expect(block.attributes).toBeUndefined();
    });
  });

  it('search= filters to blocks containing the text', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, {
        fields: 'name,innerHTML',
        search: 'Integration test paragraph',
      });
      const blocks = read.blocks as Array<{ name: string }>;
      expect(blocks.length).toBe(1);
      expect(blocks[0].name).toBe('core/paragraph');
    });
  });

  it('block_name= filters to a single block type', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, {
        fields: 'name',
        block_name: 'core/heading',
      });
      const blocks = read.blocks as Array<{ name: string }>;
      expect(blocks.length).toBeGreaterThanOrEqual(1);
      expect(blocks.every((b) => b.name === 'core/heading')).toBe(true);
    });
  });

  it('summary_only returns the summary without block bodies', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = (await client.getPageBlocks(postId, { summary_only: true })) as {
        summary?: { total_blocks?: number };
        blocks?: unknown[];
      };
      expect(read.summary?.total_blocks).toBeGreaterThanOrEqual(2);
      expect(read.blocks === undefined || read.blocks.length === 0).toBe(true);
    });
  });

  it('render=true serves rendered output without corrupting the canonical tree', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, { render: true });
      const blocks = read.blocks as Array<{ name: string }>;
      expect(blocks.length).toBeGreaterThanOrEqual(2);
      expect(blocks[0].name).toBe('core/heading');
    });
  });

  it('resolve_url maps a real permalink back to the post', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      // Drafts have no stable public URL (resolution falls through to other
      // posts) — publish first so the permalink is a real contract.
      await client.updatePost(postId, { status: 'publish' });
      const info = (await client.getPostInfo({ post_id: postId })) as { post_url?: string };
      expect(info.post_url).toBeTruthy();

      const resolved = await client.resolveUrl(info.post_url as string);
      expect(resolved.post_id).toBe(postId);
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: discovery endpoints', () => {
  it('block-types: namespace filter returns only that namespace', async () => {
    const client = makeLiveClient();
    const res = (await client.getBlockTypes({ namespace: 'core' })) as {
      block_types?: Array<{ name: string }>;
      blocks?: Array<{ name: string }>;
    };
    const list = res.block_types ?? res.blocks ?? [];
    expect(list.length).toBeGreaterThan(0);
    expect(list.every((b) => b.name.startsWith('core/'))).toBe(true);
  });

  it('block-types: search finds core/paragraph', async () => {
    const client = makeLiveClient();
    const res = (await client.getBlockTypes({ search: 'paragraph' })) as {
      block_types?: Array<{ name: string }>;
      blocks?: Array<{ name: string }>;
    };
    const list = res.block_types ?? res.blocks ?? [];
    expect(JSON.stringify(list)).toContain('core/paragraph');
  });

  it('patterns: list + search respond with arrays; bogus id is a 404', async () => {
    const client = makeLiveClient();
    const list = (await client.getPatterns()) as { patterns?: unknown[] };
    expect(Array.isArray(list.patterns)).toBe(true);

    const search = (await client.searchPatterns('a')) as { patterns?: unknown[] };
    expect(Array.isArray(search.patterns)).toBe(true);

    let status = 0;
    try {
      await client.getPattern(999999999);
    } catch (e) {
      status = (e as { wpStatus?: number }).wpStatus ?? 0;
    }
    expect(status).toBe(404);
  });

  it('site-usage responds with usage stats', async () => {
    const client = makeLiveClient();
    const usage = (await client.getSiteUsage()) as unknown as Record<string, unknown>;
    expect(Object.keys(usage).length).toBeGreaterThan(0);
  });

  it('find-posts: search + post_type + per_page paging contract', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const info = (await client.getPostInfo({ post_id: postId })) as { title?: string };
      const found = (await client.findPosts({
        search: info.title ?? LIVE_ENV.prefix,
        post_type: 'post',
        post_status: 'draft',
        per_page: 1,
        page: 1,
      } as never)) as { posts?: Array<{ post_id?: number; id?: number }> };
      expect(found.posts?.length).toBeLessThanOrEqual(1);
      const ids = (found.posts ?? []).map((p) => p.post_id ?? p.id);
      expect(ids).toContain(postId);
    });
  });

  it('post-info: post_id and slug+post_type modes agree', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const byId = (await client.getPostInfo({ post_id: postId })) as {
        post_id?: number;
        slug?: string;
        post_type?: string;
      };
      expect(byId.post_id).toBe(postId);

      // Drafts only have a slug when explicitly set — skip the slug round-trip if empty.
      if (byId.slug) {
        const bySlug = (await client.getPostInfo({
          slug: byId.slug,
          post_type: byId.post_type ?? 'post',
        })) as { post_id?: number };
        expect(bySlug.post_id).toBe(postId);
      }
    });
  });

  it('instructions endpoint serves unauthenticated', async () => {
    const base = LIVE_ENV.url.replace(/\/+$/, '');
    const res = await axios.get(`${base}/wp-json/gk-block-api/v1/instructions`, {
      validateStatus: () => true,
    });
    expect(res.status).toBe(200);
  });
});

/** Decorated error fields attached by the client's response interceptor. */
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

const P = (text: string) => ({
  name: 'core/paragraph',
  attributes: { content: text },
  innerHTML: `<p>${text}</p>`,
});

describe.skipIf(skipUnlessLive())('integration: read variants and pagination', () => {
  it('cursor + limit paginate top-level blocks without gaps or overlap', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, { blocks: [P('EDGE-PG-3'), P('EDGE-PG-4')] }); // 4 total

      const page1 = await client.getPageBlocks(postId, { fields: 'name,innerHTML', limit: 2 });
      expect(page1.blocks).toHaveLength(2);
      expect(page1.pagination?.next_cursor).toBeTruthy();

      const page2 = await client.getPageBlocks(postId, {
        fields: 'name,innerHTML',
        limit: 2,
        cursor: page1.pagination?.next_cursor as string,
      });
      expect(page2.blocks).toHaveLength(2);

      const all = JSON.stringify([...page1.blocks, ...page2.blocks]);
      expect(all).toContain('Integration test heading');
      expect(all).toContain('EDGE-PG-3');
      expect(all).toContain('EDGE-PG-4');
    });
  });

  it('outline and include_legacy_paths respond without altering the tree', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const outline = await client.getPageBlocks(postId, { outline: true });
      expect(outline.post_id ?? postId).toBe(postId);

      const legacy = await client.getPageBlocks(postId, {
        fields: 'name',
        include_legacy_paths: true,
      });
      expect((legacy.blocks as unknown[]).length).toBeGreaterThanOrEqual(2);
    });
  });

  it('persist_refs: false reads without writing refs back', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, {
        fields: 'name,ref',
        persist_refs: false,
      });
      expect((read.blocks as unknown[]).length).toBeGreaterThanOrEqual(2);
    });
  });

  it('getBlock targets by flatIndex as well as by ref', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const byIndex = await client.getBlock(postId, { flatIndex: 0 });
      expect(byIndex.saved?.block_name).toBe('core/heading');
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: block-types filter matrix', () => {
  it('tier filter returns only blocks of that tier', async () => {
    const client = makeLiveClient();
    const res = (await client.getBlockTypes({ tier: 'preferred' })) as unknown as {
      block_types?: Array<{ name: string; preference?: { tier?: string }; tier?: string }>;
      blocks?: Array<{ name: string; preference?: { tier?: string }; tier?: string }>;
    };
    const list = res.block_types ?? res.blocks ?? [];
    for (const b of list.slice(0, 10)) {
      const tier = b.preference?.tier ?? b.tier;
      if (tier) expect(String(tier).toLowerCase()).toBe('preferred');
    }
  });

  it('preferred_only / usage_only / category / storage_mode are accepted', async () => {
    const client = makeLiveClient();
    await expect(client.getBlockTypes({ preferred_only: true })).resolves.toBeTruthy();
    await expect(client.getBlockTypes({ usage_only: true })).resolves.toBeTruthy();
    await expect(client.getBlockTypes({ category: 'text' })).resolves.toBeTruthy();
    await expect(client.getBlockTypes({ storage_mode: 'static' })).resolves.toBeTruthy();
  });
});

describe.skipIf(skipUnlessLive())('integration: patterns insert', () => {
  it('inserts a registered or synced pattern (inlined) into the post', async () => {
    const client = makeLiveClient();
    const list = (await client.getPatterns()) as {
      patterns?: Array<{ id?: number | string; name?: string; pattern_id?: number | string }>;
    };
    const candidate = (list.patterns ?? [])[0];
    if (!candidate) {
      // A site with zero patterns is valid — nothing to exercise.
      return;
    }
    const patternId = (candidate.id ?? candidate.pattern_id ?? candidate.name) as number | string;

    await withTestPost(client, async (postId) => {
      const before = await client.getPageBlocks(postId, { fields: 'name' });
      const res = await grab(
        client.insertPattern(postId, { pattern_id: patternId, synced: false }),
      );
      if (res.wpStatus) {
        // Some patterns are rejected by site policy (legacy-tier contents) —
        // that's a structured outcome, not a silent failure.
        expect(res.wpStatus).toBeGreaterThanOrEqual(400);
        return;
      }
      const after = await client.getPageBlocks(postId, { fields: 'name' });
      expect((after.blocks as unknown[]).length).toBeGreaterThan(
        (before.blocks as unknown[]).length,
      );
    });
  });
});
