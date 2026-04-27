import { describe, it, expect, vi, beforeEach } from 'vitest';
import { TERM_TOOLS, handleTermTool } from '../tools/terms.js';

const mockClient = {
  listTerms: vi.fn().mockResolvedValue({
    taxonomy: 'category',
    total: 0,
    page: 1,
    per_page: 100,
    terms: [],
  }),
} as any;

describe('TERM_TOOLS', () => {
  it('exposes list_terms', () => {
    expect(TERM_TOOLS.map((t) => t.name)).toContain('list_terms');
  });
});

describe('handleTermTool', () => {
  beforeEach(() => vi.clearAllMocks());

  it('passes empty args through to client (taxonomy default applied server-side)', async () => {
    await handleTermTool('list_terms', {}, mockClient);
    expect(mockClient.listTerms).toHaveBeenCalledWith({});
  });

  it('forwards filters', async () => {
    await handleTermTool(
      'list_terms',
      { taxonomy: 'post_tag', search: 'wp', per_page: 25, page: 2 },
      mockClient,
    );
    expect(mockClient.listTerms).toHaveBeenCalledWith({
      taxonomy: 'post_tag',
      search: 'wp',
      per_page: 25,
      page: 2,
    });
  });

  it('throws on unknown tool', async () => {
    await expect(handleTermTool('unknown', {}, mockClient)).rejects.toThrow('Unknown term tool');
  });
});
