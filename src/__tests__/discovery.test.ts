import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../tools/discovery.js';

const mockClient = {
  getBlockTypes: vi.fn().mockResolvedValue({ block_types: [] }),
  getBlockTypesByNamespace: vi.fn().mockResolvedValue({ block_types: [] }),
  getPatterns: vi.fn().mockResolvedValue({ patterns: [] }),
  searchPatterns: vi.fn().mockResolvedValue({ patterns: [] }),
  getPattern: vi.fn().mockResolvedValue({ id: 1, name: 'Test' }),
  getSiteUsage: vi.fn().mockResolvedValue({ block_usage: {}, namespace_totals: {}, pattern_references: {}, legacy_patterns: [] }),
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

  it('throws on unknown tool', async () => {
    await expect(handleDiscoveryTool('unknown', {}, mockClient)).rejects.toThrow('Unknown discovery tool');
  });
});
