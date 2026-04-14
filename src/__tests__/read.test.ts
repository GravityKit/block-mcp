import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleReadTool } from '../tools/read.js';

const mockClient = {
  getPageBlocks: vi.fn().mockResolvedValue({
    blocks: [
      { index: 0, path: [0], name: 'core/paragraph', attributes: {} },
      { index: 1, path: [1], name: 'stackable/heading', attributes: {} },
    ],
  }),
} as any;

describe('handleReadTool', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires post_id', async () => {
    await expect(handleReadTool('get_page_blocks', {}, mockClient)).rejects.toThrow('post_id');
  });

  it('calls client with post_id only', async () => {
    await handleReadTool('get_page_blocks', { post_id: 42 }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(42, {
      fields: undefined, render: undefined, search: undefined, block_name: undefined
    });
  });

  it('passes all query params to client', async () => {
    await handleReadTool('get_page_blocks', {
      post_id: 1, fields: 'path,name', render: true, search: 'hello', block_name: 'core/button'
    }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(1, {
      fields: 'path,name', render: true, search: 'hello', block_name: 'core/button'
    });
  });

  it('enriches response with legacy warnings', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, mockClient) as any;
    // Should have warnings for stackable/heading
    expect(result.warnings.length).toBeGreaterThan(0);
    expect(result.warnings[0].block).toBe('stackable/heading');
    expect(result.warnings[0].suggested_replacement).toBe('core/heading');
  });

  it('includes block_count', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, mockClient) as any;
    expect(result.block_count).toBe(2);
  });

  it('includes post_id in response', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 99 }, mockClient) as any;
    expect(result.post_id).toBe(99);
  });

  it('includes summary in response', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, mockClient) as any;
    expect(result.summary).toBeDefined();
    expect(typeof result.summary).toBe('string');
  });

  it('returns clean result for preferred-only blocks', async () => {
    mockClient.getPageBlocks.mockResolvedValueOnce({
      blocks: [
        { index: 0, path: [0], name: 'core/paragraph', attributes: {} },
        { index: 1, path: [1], name: 'core/heading', attributes: {} },
      ],
    });
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, mockClient) as any;
    expect(result.warnings).toHaveLength(0);
    expect(result.block_count).toBe(2);
  });

  it('throws on unknown tool name', async () => {
    await expect(handleReadTool('unknown_tool', { post_id: 1 }, mockClient))
      .rejects.toThrow('Unknown read tool');
  });
});
