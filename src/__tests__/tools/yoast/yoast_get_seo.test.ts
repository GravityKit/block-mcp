/**
 * Tool tests: yoast_get_seo
 *
 * Covers:
 *   - Schema: tool exposed with post_id required
 *   - Validation: post_id must be a number
 *   - Request: post_id forwarded to client.getYoastSEO
 *   - Response shape: post_id, title, description, seo_score, readability_score
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { YOAST_TOOLS, handleYoastTool } from '../../../tools/yoast.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { yoastSEOResponse } from '../../fixtures/rest-responses.js';

describe('yoast_get_seo — schema', () => {
  it('exposes yoast_get_seo tool', () => {
    expect(YOAST_TOOLS.map((t) => t.name)).toContain('yoast_get_seo');
  });

  it('requires post_id in inputSchema', () => {
    const tool = YOAST_TOOLS.find((t) => t.name === 'yoast_get_seo')!;
    expect(tool.inputSchema.required).toContain('post_id');
  });
});

describe('yoast_get_seo — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects missing post_id', async () => {
    await expect(handleYoastTool('yoast_get_seo', {}, client as any)).rejects.toThrow('post_id');
    expect(client.getYoastSEO).not.toHaveBeenCalled();
  });

  // Coerces a numeric-string post_id (parity with get_post_info / update_post),
  // rejecting only a genuinely non-numeric value.
  it('coerces a numeric-string post_id', async () => {
    client.getYoastSEO.mockResolvedValue(yoastSEOResponse);
    await handleYoastTool('yoast_get_seo', { post_id: '42' }, client as any);
    expect(client.getYoastSEO).toHaveBeenCalledWith(42);
  });

  it('rejects a non-numeric post_id string', async () => {
    await expect(handleYoastTool('yoast_get_seo', { post_id: 'abc' }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
    expect(client.getYoastSEO).not.toHaveBeenCalled();
  });
});

describe('yoast_get_seo — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getYoastSEO.mockResolvedValue(yoastSEOResponse);
    vi.clearAllMocks();
  });

  it('forwards post_id to client.getYoastSEO', async () => {
    await handleYoastTool('yoast_get_seo', { post_id: 42 }, client as any);
    expect(client.getYoastSEO).toHaveBeenCalledWith(42);
  });
});

describe('yoast_get_seo — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getYoastSEO.mockResolvedValue(yoastSEOResponse);
    vi.clearAllMocks();
  });

  it('returns post_id, title, description, scores', async () => {
    const result = await handleYoastTool('yoast_get_seo', { post_id: 42 }, client as any) as any;
    expect(result.post_id).toBe(9999);
    expect(result.title).toBe('SEO Title');
    expect(result.description).toBe('Meta description.');
    expect(result.seo_score).toBe(78);
    expect(result.readability_score).toBe(65);
  });

  it('noindex can be null (tri-state)', async () => {
    const result = await handleYoastTool('yoast_get_seo', { post_id: 42 }, client as any) as any;
    expect(result.noindex).toBeNull();
  });
});
