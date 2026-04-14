import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleMutateTool } from '../tools/mutate.js';

// Mock client
const mockClient = {
  mutateBlockTree: vi.fn().mockResolvedValue({
    success: true,
    op: 'update-attrs',
    path: [0],
    block: { name: 'core/heading', attributes: { level: 2 } },
    warnings: [],
    before_revision_id: 1,
    revision_id: 2,
  }),
} as any;

describe('handleMutateTool', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // Common validation
  it('throws on missing post_id', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { op: 'update-attrs', path: [0], attributes: {} }, mockClient)
    ).rejects.toThrow('post_id is required');
  });

  it('throws on invalid op', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'fake', path: [0] }, mockClient)
    ).rejects.toThrow('op must be one of');
  });

  it('throws on non-array path', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'update-attrs', path: 'not-array', attributes: {} }, mockClient)
    ).rejects.toThrow('must be an array of integers');
  });

  it('throws on path with non-integers', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'update-attrs', path: [0, 'abc', 2], attributes: {} }, mockClient)
    ).rejects.toThrow('must contain only integers');
  });

  // Per-operation validation
  it('update-attrs requires attributes', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'update-attrs', path: [0] }, mockClient)
    ).rejects.toThrow('attributes');
  });

  it('update-html requires innerHTML', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'update-html', path: [0] }, mockClient)
    ).rejects.toThrow('innerHTML');
  });

  it('replace-block requires block with name', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'replace-block', path: [0], block: {} }, mockClient)
    ).rejects.toThrow('name');
  });

  it('move requires before or destination', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'move', path: [0] }, mockClient)
    ).rejects.toThrow('before');
  });

  it('move accepts before param', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'move', path: [0], before: [5]
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'move', path: [0], before: [5]
    }));
  });

  it('move accepts destination param', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'move', path: [0], destination: [3]
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'move', path: [0], destination: [3]
    }));
  });

  it('move accepts count param', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'move', path: [0], before: [5], count: 3
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      count: 3
    }));
  });

  it('move rejects non-integer count', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', {
        post_id: 1, op: 'move', path: [0], before: [5], count: 0
      }, mockClient)
    ).rejects.toThrow('count must be a positive integer');
  });

  // Operations that need no extra params
  for (const op of ['remove-block', 'unwrap-group', 'duplicate']) {
    it(`${op} requires only path`, async () => {
      await handleMutateTool('mutate_block_tree', {
        post_id: 1, op, path: [0]
      }, mockClient);
      expect(mockClient.mutateBlockTree).toHaveBeenCalled();
    });
  }

  it('insert-child requires block with name', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', { post_id: 1, op: 'insert-child', path: [0] }, mockClient)
    ).rejects.toThrow('block');
  });

  it('insert-child accepts valid position', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'insert-child', path: [0],
      block: { name: 'core/paragraph' }, position: 'start'
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      position: 'start'
    }));
  });

  it('insert-child rejects invalid position', async () => {
    await expect(
      handleMutateTool('mutate_block_tree', {
        post_id: 1, op: 'insert-child', path: [0],
        block: { name: 'core/paragraph' }, position: 'middle'
      }, mockClient)
    ).rejects.toThrow('position');
  });

  it('wrap-in-group passes wrapper', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'wrap-in-group', path: [0], wrapper: { name: 'core/columns' }
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      wrapper: { name: 'core/columns' }
    }));
  });

  it('update-attrs passes attributes through', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0, 2], attributes: { level: 3, content: 'Hi' }
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'update-attrs', path: [0, 2], attributes: { level: 3, content: 'Hi' }
    }));
  });

  it('update-html passes innerHTML through', async () => {
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'update-html', path: [1], innerHTML: '<p>Hello</p>'
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'update-html', path: [1], innerHTML: '<p>Hello</p>'
    }));
  });

  it('replace-block passes full block', async () => {
    const block = { name: 'core/paragraph', attributes: { content: 'New' }, innerHTML: '<p>New</p>' };
    await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'replace-block', path: [0], block
    }, mockClient);
    expect(mockClient.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'replace-block', block
    }));
  });

  // Warning enrichment
  it('formats static_markup_stale_risk warnings', async () => {
    mockClient.mutateBlockTree.mockResolvedValueOnce({
      success: true, op: 'update-attrs', path: [0],
      warnings: [{ type: 'static_markup_stale_risk', block_name: 'core/paragraph', changed_attrs: ['content'], message: 'test' }],
      revision_id: 1,
    });
    const result = await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0], attributes: { content: 'x' }
    }, mockClient) as any;
    expect(result.formatted_warnings).toBeDefined();
    expect(result.formatted_warnings[0]).toContain('WARNING');
    expect(result.formatted_warnings[0]).toContain('content');
    expect(result.formatted_warnings[0]).toContain('core/paragraph');
  });

  it('formats preference warnings', async () => {
    mockClient.mutateBlockTree.mockResolvedValueOnce({
      success: true, op: 'replace-block', path: [0],
      warnings: [{ block: 'stackable/heading', message: 'AVOID', suggested_replacement: 'core/heading' }],
      revision_id: 1,
    });
    const result = await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'replace-block', path: [0], block: { name: 'stackable/heading' }
    }, mockClient) as any;
    expect(result.formatted_warnings).toBeDefined();
    expect(result.formatted_warnings[0]).toContain('WARNING');
  });

  it('returns raw result when no warnings', async () => {
    mockClient.mutateBlockTree.mockResolvedValueOnce({
      success: true, op: 'remove-block', path: [0],
      warnings: [],
      revision_id: 2,
    });
    const result = await handleMutateTool('mutate_block_tree', {
      post_id: 1, op: 'remove-block', path: [0]
    }, mockClient) as any;
    expect(result.formatted_warnings).toBeUndefined();
    expect(result.success).toBe(true);
  });

  it('throws on unknown tool name', async () => {
    await expect(
      handleMutateTool('unknown_tool', { post_id: 1, op: 'update-attrs', path: [0], attributes: {} }, mockClient)
    ).rejects.toThrow('Unknown mutate tool');
  });
});
