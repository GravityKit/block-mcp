/**
 * Tool tests: yoast_update_seo
 *
 * Covers:
 *   - Schema: post_id required
 *   - Validation: post_id required
 *   - Validation: at least one mutating Yoast field required
 *   - Request: only known Yoast fields forwarded (extra fields stripped)
 *   - noindex tri-state: null is preserved
 *   - robots_advanced: unknown directives filtered out
 *   - schema_page_type: out-of-enum values dropped
 *   - Response shape
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { YOAST_TOOLS, handleYoastTool } from '../../../tools/yoast.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { yoastSEOResponse } from '../../fixtures/rest-responses.js';

describe('yoast_update_seo — schema', () => {
  it('exposes yoast_update_seo tool', () => {
    expect(YOAST_TOOLS.map((t) => t.name)).toContain('yoast_update_seo');
  });

  it('requires post_id in inputSchema', () => {
    const tool = YOAST_TOOLS.find((t) => t.name === 'yoast_update_seo')!;
    expect(tool.inputSchema.required).toContain('post_id');
  });

  // Regression: some AI clients (e.g. Google Gemini) reject a "null" member in a
  // JSON Schema `type` array and 400 the entire tools/list request, taking down
  // every tool on the server. noindex must advertise a single scalar type; the
  // handler still accepts an explicit null (see "noindex tri-state" tests below).
  it('advertises noindex as a single boolean type, never a ["boolean","null"] array', () => {
    const tool = YOAST_TOOLS.find((t) => t.name === 'yoast_update_seo')!;
    const props = tool.inputSchema.properties as unknown as Record<string, { type?: unknown }>;
    expect(props.noindex.type).toBe('boolean');
    // Guard the whole shared field set: no property may use a type array containing "null".
    for (const [name, schema] of Object.entries(props)) {
      if (Array.isArray(schema.type)) {
        expect(schema.type, `${name} uses a type array containing "null"`).not.toContain('null');
      }
    }
  });
});

describe('yoast_update_seo — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(handleYoastTool('yoast_update_seo', { title: 'New' }, client as any))
      .rejects.toThrow('post_id');
    expect(client.updateYoastSEO).not.toHaveBeenCalled();
  });

  it('requires at least one Yoast mutating field', async () => {
    await expect(handleYoastTool('yoast_update_seo', { post_id: 1 }, client as any))
      .rejects.toThrow('at least one Yoast field');
    expect(client.updateYoastSEO).not.toHaveBeenCalled();
  });
});

describe('yoast_update_seo — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.updateYoastSEO.mockResolvedValue({ ...yoastSEOResponse, title: 'New' });
    vi.clearAllMocks();
  });

  it('forwards known Yoast fields and strips unknown fields', async () => {
    await handleYoastTool('yoast_update_seo', {
      post_id: 1, title: 'New', noindex: true, evil_extra: 'ignored',
    }, client as any);
    expect(client.updateYoastSEO).toHaveBeenCalledWith(1, { title: 'New', noindex: true });
  });

  it('preserves null in noindex (tri-state)', async () => {
    await handleYoastTool('yoast_update_seo', { post_id: 1, noindex: null }, client as any);
    expect(client.updateYoastSEO).toHaveBeenCalledWith(1, { noindex: null });
  });

  it('filters robots_advanced to known directives only', async () => {
    await handleYoastTool('yoast_update_seo', {
      post_id: 1, robots_advanced: ['noarchive', 'evil', 'nosnippet'],
    }, client as any);
    expect(client.updateYoastSEO).toHaveBeenCalledWith(1, {
      robots_advanced: ['noarchive', 'nosnippet'],
    });
  });

  it('drops out-of-enum schema_page_type values', async () => {
    await handleYoastTool('yoast_update_seo', {
      post_id: 1, schema_page_type: 'BogusType', title: 'Valid',
    }, client as any);
    expect(client.updateYoastSEO).toHaveBeenCalledWith(1, { title: 'Valid' });
  });

  it('post_id is the first arg not part of the body', async () => {
    await handleYoastTool('yoast_update_seo', { post_id: 99, title: 'X' }, client as any);
    const [id, body] = client.updateYoastSEO.mock.calls[0] as [number, Record<string, unknown>];
    expect(id).toBe(99);
    expect(body).not.toHaveProperty('post_id');
  });
});

describe('yoast_update_seo — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.updateYoastSEO.mockResolvedValue({ ...yoastSEOResponse, title: 'Updated' });
    vi.clearAllMocks();
  });

  it('returns updated SEO metadata', async () => {
    const result = await handleYoastTool('yoast_update_seo', { post_id: 1, title: 'Updated' }, client as any) as any;
    expect(result.post_id).toBe(9999);
    expect(result.title).toBe('Updated');
  });
});
