import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handlePatternTool } from '../tools/patterns.js';

const mockClient = {
  insertPattern: vi.fn().mockResolvedValue({
    success: true,
    inserted: { index: 5, name: 'core/block', attributes: { ref: 123 }, synced: true },
    pattern_name: 'Hero Section',
    synced: true,
    before_revision_id: 1,
    revision_id: 2,
  }),
} as any;

describe('handlePatternTool', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires post_id', async () => {
    await expect(handlePatternTool('insert_pattern', { pattern_id: 1 }, mockClient))
      .rejects.toThrow('post_id');
  });

  it('requires pattern_id', async () => {
    await expect(handlePatternTool('insert_pattern', { post_id: 1 }, mockClient))
      .rejects.toThrow('pattern_id');
  });

  it('calls client with default synced=true', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123 }, mockClient);
    expect(mockClient.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      pattern_id: 123, synced: true
    }));
  });

  it('passes synced=false', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, synced: false }, mockClient);
    expect(mockClient.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      synced: false
    }));
  });

  it('passes after_top_level position', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, after_top_level: 3 }, mockClient);
    expect(mockClient.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      after: 3
    }));
  });

  it('passes before_top_level position', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, before_top_level: 2 }, mockClient);
    expect(mockClient.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      before: 2
    }));
  });

  it('accepts string pattern_id', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 'my-pattern-name' }, mockClient);
    expect(mockClient.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      pattern_id: 'my-pattern-name'
    }));
  });

  it('adds sync note for synced insertion', async () => {
    const result = await handlePatternTool('insert_pattern', {
      post_id: 1, pattern_id: 123
    }, mockClient) as any;
    expect(result.note).toContain('synced reference');
  });

  it('adds inline note for non-synced insertion', async () => {
    mockClient.insertPattern.mockResolvedValueOnce({
      success: true,
      inserted: [{ index: 5, name: 'core/heading' }],
      synced: false,
      before_revision_id: 1,
      revision_id: 2,
    });
    const result = await handlePatternTool('insert_pattern', {
      post_id: 1, pattern_id: 123, synced: false
    }, mockClient) as any;
    expect(result.note).toContain('inline');
    expect(result.note).toContain('independent');
  });

  it('throws on unknown tool', async () => {
    await expect(handlePatternTool('unknown', { post_id: 1, pattern_id: 1 }, mockClient))
      .rejects.toThrow('Unknown pattern tool');
  });
});
