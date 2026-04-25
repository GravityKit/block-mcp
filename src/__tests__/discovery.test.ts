import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../tools/discovery.js';

const mockClient = {
  getBlockTypes: vi.fn().mockResolvedValue({ block_types: [] }),
  getBlockTypesByNamespace: vi.fn().mockResolvedValue({ block_types: [] }),
  getPatterns: vi.fn().mockResolvedValue({ patterns: [] }),
  searchPatterns: vi.fn().mockResolvedValue({ patterns: [] }),
  getPattern: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
  getSiteUsage: vi.fn().mockResolvedValue({ block_usage: {}, namespace_totals: {}, pattern_references: {}, legacy_patterns: [] }),
  resolveUrl: vi.fn().mockResolvedValue({ post_id: 42, post_type: 'page', title: 'X', status: 'publish', slug: 'x', edit_url: '' }),
  findPosts: vi.fn().mockResolvedValue({ posts: [], count: 0, total: 0, total_pages: 0, page: 1, per_page: 20 }),
  getPostInfo: vi.fn().mockResolvedValue({ post_id: 42, title: 'X', slug: 'x', post_type: 'page', post_status: 'publish', post_url: '', edit_url: '', modified: '', created: '', parent_id: 0, author: { id: 1, display_name: 'a' }, mime_type: '', comment_count: 0 }),
} as any;

describe('handleDiscoveryTool', () => {
  beforeEach(() => vi.clearAllMocks());

  describe('list_block_types', () => {
    it('passes namespace and preferred params', async () => {
      await handleDiscoveryTool('list_block_types', { namespace: 'core', preferred_only: true }, mockClient);
      expect(mockClient.getBlockTypes).toHaveBeenCalledWith(expect.objectContaining({
        namespace: 'core', preferred: true
      }));
    });

    it('passes category param', async () => {
      await handleDiscoveryTool('list_block_types', { category: 'text' }, mockClient);
      expect(mockClient.getBlockTypes).toHaveBeenCalledWith(expect.objectContaining({
        category: 'text'
      }));
    });

    it('returns enriched result with count and guidance', async () => {
      mockClient.getBlockTypes.mockResolvedValueOnce({
        block_types: [
          { name: 'core/paragraph', title: 'Paragraph', category: 'text', preference: { score: 90, tier: 'preferred' } },
        ],
      });
      const result = await handleDiscoveryTool('list_block_types', {}, mockClient) as any;
      expect(result.count).toBe(1);
      expect(result.block_types).toHaveLength(1);
      expect(result.guidance).toBeDefined();
    });

    it('works with no params', async () => {
      await handleDiscoveryTool('list_block_types', {}, mockClient);
      expect(mockClient.getBlockTypes).toHaveBeenCalledWith({
        namespace: undefined, category: undefined, preferred: undefined
      });
    });
  });

  describe('list_patterns', () => {
    it('passes search and filter params', async () => {
      await handleDiscoveryTool('list_patterns', {
        search: 'hero', synced: true, min_score: 50, limit: 10
      }, mockClient);
      expect(mockClient.getPatterns).toHaveBeenCalledWith({
        q: 'hero', synced: true, min_score: 50, limit: 10
      });
    });

    it('returns enriched result with count and summary', async () => {
      mockClient.getPatterns.mockResolvedValueOnce({
        patterns: [
          {
            id: 1, name: 'Hero Section', type: 'synced',
            created: '2026-01-01', modified: '2026-01-01',
            reference_count: 5, contains_blocks: ['core/heading'],
            has_legacy_blocks: false,
            preference: { score: 80, tier: 'recommended', reasons: [] },
          },
        ],
      });
      const result = await handleDiscoveryTool('list_patterns', {}, mockClient) as any;
      expect(result.count).toBe(1);
      expect(result.patterns).toHaveLength(1);
      expect(result.summary).toBeDefined();
    });
  });

  describe('get_pattern', () => {
    it('requires pattern_id', async () => {
      await expect(handleDiscoveryTool('get_pattern', {}, mockClient)).rejects.toThrow('pattern_id');
    });

    it('calls client with numeric id', async () => {
      await handleDiscoveryTool('get_pattern', { pattern_id: 123 }, mockClient);
      expect(mockClient.getPattern).toHaveBeenCalledWith(123);
    });

    it('calls client with string id', async () => {
      await handleDiscoveryTool('get_pattern', { pattern_id: 'my-pattern' }, mockClient);
      expect(mockClient.getPattern).toHaveBeenCalledWith('my-pattern');
    });
  });

  describe('get_site_usage', () => {
    it('calls client without refresh', async () => {
      await handleDiscoveryTool('get_site_usage', {}, mockClient);
      expect(mockClient.getSiteUsage).toHaveBeenCalledWith(undefined);
    });

    it('passes refresh param', async () => {
      await handleDiscoveryTool('get_site_usage', { refresh: true }, mockClient);
      expect(mockClient.getSiteUsage).toHaveBeenCalledWith(true);
    });
  });

  describe('find_posts', () => {
    it('passes all filter params through to client', async () => {
      await handleDiscoveryTool('find_posts', {
        search: 'gravityview', post_type: 'page,post', post_status: 'draft', per_page: 50, page: 2,
      }, mockClient);
      expect(mockClient.findPosts).toHaveBeenCalledWith({
        search: 'gravityview', post_type: 'page,post', post_status: 'draft', per_page: 50, page: 2,
      });
    });

    it('works with no params', async () => {
      await handleDiscoveryTool('find_posts', {}, mockClient);
      expect(mockClient.findPosts).toHaveBeenCalledWith({
        search: undefined, post_type: undefined, post_status: undefined, per_page: undefined, page: undefined,
      });
    });
  });

  describe('post_info', () => {
    it('looks up by post_id', async () => {
      await handleDiscoveryTool('post_info', { post_id: 42 }, mockClient);
      expect(mockClient.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ post_id: 42 }));
    });

    it('looks up by url', async () => {
      await handleDiscoveryTool('post_info', { url: '/foo/' }, mockClient);
      expect(mockClient.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ url: '/foo/' }));
    });

    it('looks up by slug + post_type', async () => {
      await handleDiscoveryTool('post_info', { slug: 'foo', post_type: 'docs' }, mockClient);
      expect(mockClient.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ slug: 'foo', post_type: 'docs' }));
    });

    it('throws when no lookup field provided', async () => {
      await expect(handleDiscoveryTool('post_info', {}, mockClient))
        .rejects.toThrow('one of: post_id, url, or slug');
    });
  });

  it('throws on unknown tool', async () => {
    await expect(handleDiscoveryTool('unknown', {}, mockClient)).rejects.toThrow('Unknown discovery tool');
  });
});
