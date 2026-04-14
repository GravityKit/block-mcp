import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleReadTool } from '../tools/read.js';

const mockClient = {
  getPageBlocks: vi.fn().mockResolvedValue({
    blocks: [
      { index: 0, path: [0], name: 'core/paragraph', attributes: {} },
      { index: 1, path: [1], name: 'stackable/heading', attributes: {} },
    ],
    summary: {
      total_blocks: 2,
      top_level_blocks: 2,
      block_types: { 'core/paragraph': 1, 'stackable/heading': 1 },
      sections: [],
      headings: [],
      legacy_blocks: [{ name: 'stackable/heading', path: [1] }],
      max_path_depth: 0,
    },
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
      fields: undefined, render: undefined, search: undefined, block_name: undefined,
      outline: undefined, summary_only: undefined,
    });
  });

  it('passes all query params to client', async () => {
    await handleReadTool('get_page_blocks', {
      post_id: 1, fields: 'path,name', render: true, search: 'hello', block_name: 'core/button'
    }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(1, {
      fields: 'path,name', render: true, search: 'hello', block_name: 'core/button',
      outline: undefined, summary_only: undefined,
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
    // Server-side summary is now an object, not a string.
    expect(typeof result.summary).toBe('object');
    expect(result.summary.total_blocks).toBe(2);
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

  // ── outline param ─────────────────────────────────────────────
  it('outline param passes to client as true', async () => {
    await handleReadTool('get_page_blocks', { post_id: 5, outline: true }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(5, expect.objectContaining({
      outline: true,
    }));
  });

  it('outline=false is passed through', async () => {
    await handleReadTool('get_page_blocks', { post_id: 5, outline: false }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(5, expect.objectContaining({
      outline: false,
    }));
  });

  // ── summary_only param ────────────────────────────────────────
  it('summary_only param passes to client as true', async () => {
    mockClient.getPageBlocks.mockResolvedValueOnce({
      blocks: [],
      summary: { total_blocks: 3, top_level_blocks: 3, block_types: {}, sections: [], headings: [], legacy_blocks: [], max_path_depth: 0 },
    });
    await handleReadTool('get_page_blocks', { post_id: 7, summary_only: true }, mockClient);
    expect(mockClient.getPageBlocks).toHaveBeenCalledWith(7, expect.objectContaining({
      summary_only: true,
    }));
  });

  it('summary_only returns only summary + post_id (no blocks/warnings)', async () => {
    const serverSummary = {
      total_blocks: 5,
      top_level_blocks: 2,
      block_types: { 'core/heading': 2, 'core/paragraph': 3 },
      sections: [],
      headings: [{ path: [0], level: 2, text: 'Hello' }],
      legacy_blocks: [],
      max_path_depth: 1,
    };
    mockClient.getPageBlocks.mockResolvedValueOnce({
      blocks: [{ index: 0, path: [0], name: 'core/heading', attributes: { level: 2 } }],
      summary: serverSummary,
    });
    const result = await handleReadTool('get_page_blocks', { post_id: 42, summary_only: true }, mockClient) as any;
    expect(result.post_id).toBe(42);
    expect(result.summary).toEqual(serverSummary);
    // Minimal response: no blocks, no block_count, no warnings.
    expect(result.blocks).toBeUndefined();
    expect(result.block_count).toBeUndefined();
    expect(result.warnings).toBeUndefined();
  });

  it('summary_only skips enrichBlockList (legacy blocks not flagged)', async () => {
    // Even if the server returned blocks with legacy names, summary_only mode
    // should not run enrichBlockList and should not produce warnings.
    mockClient.getPageBlocks.mockResolvedValueOnce({
      blocks: [
        { index: 0, path: [0], name: 'stackable/heading', attributes: {} },
        { index: 1, path: [1], name: 'ugb/text', attributes: {} },
      ],
      summary: { total_blocks: 2, top_level_blocks: 2, block_types: {}, sections: [], headings: [], legacy_blocks: [], max_path_depth: 0 },
    });
    const result = await handleReadTool('get_page_blocks', { post_id: 1, summary_only: true }, mockClient) as any;
    expect(result.warnings).toBeUndefined();
    expect(result.blocks).toBeUndefined();
  });

  it('non-summary_only mode still enriches blocks with warnings', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, mockClient) as any;
    expect(result.warnings).toBeDefined();
    expect(result.warnings.length).toBeGreaterThan(0);
    expect(result.blocks).toBeDefined();
  });
});
