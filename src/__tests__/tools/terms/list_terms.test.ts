/**
 * Tool tests: list_terms
 *
 * Covers:
 *   - Schema: list_terms exposed
 *   - Empty args: passes through (taxonomy default server-side)
 *   - Filter forwarding: taxonomy, search, per_page, page
 *   - Response shape: taxonomy, total, page, per_page, terms
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { TERM_TOOLS, handleTermTool } from '../../../tools/terms.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('list_terms — schema', () => {
  it('exposes list_terms tool', () => {
    expect(TERM_TOOLS.map((t) => t.name)).toContain('list_terms');
  });
});

describe('list_terms — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('passes empty args to client (server applies default taxonomy)', async () => {
    await handleTermTool('list_terms', {}, client as any);
    expect(client.listTerms).toHaveBeenCalledWith({});
  });

  it('forwards taxonomy filter', async () => {
    await handleTermTool('list_terms', { taxonomy: 'post_tag' }, client as any);
    expect(client.listTerms).toHaveBeenCalledWith(expect.objectContaining({ taxonomy: 'post_tag' }));
  });

  it('forwards search filter', async () => {
    await handleTermTool('list_terms', { taxonomy: 'category', search: 'wp' }, client as any);
    expect(client.listTerms).toHaveBeenCalledWith(expect.objectContaining({ search: 'wp' }));
  });

  it('forwards per_page and page', async () => {
    await handleTermTool('list_terms', { taxonomy: 'post_tag', per_page: 25, page: 2 }, client as any);
    expect(client.listTerms).toHaveBeenCalledWith({
      taxonomy: 'post_tag', per_page: 25, page: 2,
    });
  });

  it('forwards all filters together', async () => {
    await handleTermTool('list_terms', {
      taxonomy: 'category', search: 'news', per_page: 50, page: 3,
    }, client as any);
    expect(client.listTerms).toHaveBeenCalledWith({
      taxonomy: 'category', search: 'news', per_page: 50, page: 3,
    });
  });
});

describe('list_terms — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    vi.clearAllMocks();
  });

  it('returns taxonomy, total, page, per_page, terms', async () => {
    client.listTerms.mockResolvedValue({
      taxonomy: 'category', total: 5, page: 1, per_page: 100,
      terms: [{ id: 1, name: 'Uncategorized', slug: 'uncategorized', count: 10 }],
    });
    const result = await handleTermTool('list_terms', {}, client as any) as any;
    expect(result.taxonomy).toBe('category');
    expect(result.total).toBe(5);
    expect(result.terms).toHaveLength(1);
  });

  it('handles empty terms array', async () => {
    const result = await handleTermTool('list_terms', {}, client as any) as any;
    expect(result.terms).toEqual([]);
    expect(result.total).toBe(0);
  });
});

describe('list_terms — unknown tool', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('throws on unknown tool name', async () => {
    await expect(handleTermTool('unknown_tool', {}, client as any))
      .rejects.toThrow('Unknown term tool');
  });
});
