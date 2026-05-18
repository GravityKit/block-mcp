/**
 * Tool tests: yoast_bulk_update_seo
 *
 * Covers:
 *   - Schema: posts required
 *   - Validation: rejects empty posts array
 *   - Validation: rejects items missing post_id
 *   - Request: normalized item list forwarded (extra fields stripped per-item)
 *   - Response shape: array of per-post results
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { YOAST_TOOLS, handleYoastTool } from '../../../tools/yoast.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { yoastSEOResponse } from '../../fixtures/rest-responses.js';

describe('yoast_bulk_update_seo — schema', () => {
  it('exposes yoast_bulk_update_seo tool', () => {
    expect(YOAST_TOOLS.map((t) => t.name)).toContain('yoast_bulk_update_seo');
  });

  it('requires posts in inputSchema', () => {
    const tool = YOAST_TOOLS.find((t) => t.name === 'yoast_bulk_update_seo')!;
    expect(tool.inputSchema.required).toContain('posts');
  });
});

describe('yoast_bulk_update_seo — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects empty posts array', async () => {
    await expect(handleYoastTool('yoast_bulk_update_seo', { posts: [] }, client as any))
      .rejects.toThrow('non-empty');
    expect(client.bulkUpdateYoastSEO).not.toHaveBeenCalled();
  });

  it('rejects items missing post_id', async () => {
    await expect(handleYoastTool('yoast_bulk_update_seo', {
      posts: [{ title: 'X' }],
    }, client as any)).rejects.toThrow('post_id');
    expect(client.bulkUpdateYoastSEO).not.toHaveBeenCalled();
  });
});

describe('yoast_bulk_update_seo — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.bulkUpdateYoastSEO.mockResolvedValue([
      { post_id: 1, success: true, seo: yoastSEOResponse },
      { post_id: 2, success: true, seo: { ...yoastSEOResponse, post_id: 2 } },
    ]);
    vi.clearAllMocks();
  });

  it('forwards normalized item list (extra fields stripped per item)', async () => {
    await handleYoastTool('yoast_bulk_update_seo', {
      posts: [
        { post_id: 1, title: 'A', evil_extra: 'strip me' },
        { post_id: 2, noindex: false, focus_keyword: 'keyword' },
      ],
    }, client as any);
    expect(client.bulkUpdateYoastSEO).toHaveBeenCalledWith([
      { post_id: 1, title: 'A' },
      { post_id: 2, noindex: false, focus_keyword: 'keyword' },
    ]);
  });

  it('processes multiple posts in a single call', async () => {
    await handleYoastTool('yoast_bulk_update_seo', {
      posts: [
        { post_id: 10, title: 'Page A' },
        { post_id: 11, title: 'Page B' },
        { post_id: 12, description: 'Meta C' },
      ],
    }, client as any);
    const [items] = client.bulkUpdateYoastSEO.mock.calls[0] as [unknown[]];
    expect(items).toHaveLength(3);
  });
});

describe('yoast_bulk_update_seo — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.bulkUpdateYoastSEO.mockResolvedValue([
      { post_id: 1, success: true, seo: yoastSEOResponse },
    ]);
    vi.clearAllMocks();
  });

  it('returns array of per-post results', async () => {
    const result = await handleYoastTool('yoast_bulk_update_seo', {
      posts: [{ post_id: 1, title: 'X' }],
    }, client as any) as any[];
    expect(Array.isArray(result)).toBe(true);
    expect(result[0].post_id).toBe(1);
    expect(result[0].success).toBe(true);
  });
});

describe('yoast_bulk_update_seo — unknown tool', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('throws on unknown tool name', async () => {
    await expect(handleYoastTool('unknown_tool', {}, client as any))
      .rejects.toThrow('Unknown yoast tool');
  });
});
