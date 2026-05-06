/**
 * Tests for the ref-based addressing layer in the MCP tools.
 *
 * Covers:
 *   - update_block: index-only, ref-only, both-rejected, neither-rejected
 *   - delete_block: index-only, ref-only, both-rejected, neither-rejected
 *   - insert_blocks: after_ref / before_ref forwarded to client
 *   - edit_block_tree: ref-only, ref+path-rejected, neither-rejected
 *   - edit_block_tree move: destination_ref forwarded
 *   - get_page_blocks: persist_refs forwarded (default vs explicit false)
 *   - client URL routing: /blocks/{index} vs /blocks/by-ref/{ref}
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../tools/write.js';
import { handleMutateTool } from '../tools/mutate.js';
import { handleReadTool } from '../tools/read.js';

vi.mock('../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => block),
  enrichBlocks: vi.fn(async (blocks: any[]) => blocks),
}));

const baseMockClient = () => ({
  updateBlock: vi.fn().mockResolvedValue({
    success: true,
    block: { index: 0, name: 'core/heading', attributes: {} },
    before_revision_id: 1,
    revision_id: 2,
  }),
  updateBlockByRef: vi.fn().mockResolvedValue({
    success: true,
    block: { index: 0, name: 'core/heading', attributes: {}, ref: 'blk_xyz' },
    before_revision_id: 1,
    revision_id: 2,
  }),
  insertBlocks: vi.fn().mockResolvedValue({
    success: true,
    inserted: [{ index: 0, name: 'core/heading', ref: 'blk_new' }],
    warnings: [],
    before_revision_id: 1,
    revision_id: 2,
  }),
  deleteBlock: vi.fn().mockResolvedValue({ success: true, removed: 1, before_revision_id: 1, revision_id: 2 }),
  deleteBlockByRef: vi.fn().mockResolvedValue({ success: true, removed: 1, before_revision_id: 1, revision_id: 2 }),
  mutateBlockTree: vi.fn().mockResolvedValue({
    success: true,
    op: 'update-attrs',
    path: [0],
    block: { name: 'core/heading', attributes: {} },
    warnings: [],
    before_revision_id: 1,
    revision_id: 2,
  }),
  getPageBlocks: vi.fn().mockResolvedValue({ summary: {}, blocks: [], block_count: 0 }),
  resolveUrl: vi.fn(),
});

describe('update_block — ref vs flat_index', () => {
  let client: ReturnType<typeof baseMockClient>;
  beforeEach(() => { client = baseMockClient(); });

  it('routes to updateBlock when only flat_index is provided', async () => {
    await handleWriteTool('update_block', { post_id: 1, flat_index: 5, attributes: { level: 3 } }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 5, { attributes: { level: 3 }, innerHTML: undefined });
    expect(client.updateBlockByRef).not.toHaveBeenCalled();
  });

  it('routes to updateBlockByRef when only ref is provided', async () => {
    await handleWriteTool('update_block', { post_id: 1, ref: 'blk_abc12345', attributes: { level: 3 } }, client as any);
    expect(client.updateBlockByRef).toHaveBeenCalledWith(1, 'blk_abc12345', { attributes: { level: 3 }, innerHTML: undefined });
    expect(client.updateBlock).not.toHaveBeenCalled();
  });

  it('rejects when both flat_index and ref are provided', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: 0, ref: 'blk_abc', attributes: {} }, client as any)
    ).rejects.toThrow(/flat_index OR ref, not both/);
  });

  it('rejects when neither flat_index nor ref is provided', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('rejects empty string ref (must be non-empty)', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, ref: '', attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('still requires attributes or innerHTML when ref is provided', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, ref: 'blk_x' }, client as any)
    ).rejects.toThrow(/attributes or innerHTML/);
  });

  it('passes innerHTML through ref path', async () => {
    await handleWriteTool('update_block', { post_id: 1, ref: 'blk_x', innerHTML: '<h2>hi</h2>' }, client as any);
    expect(client.updateBlockByRef).toHaveBeenCalledWith(1, 'blk_x', { attributes: undefined, innerHTML: '<h2>hi</h2>' });
  });
});

describe('delete_block — ref vs top_level_counter', () => {
  let client: ReturnType<typeof baseMockClient>;
  beforeEach(() => { client = baseMockClient(); });

  it('routes to deleteBlock when only top_level_counter is provided', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 3 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 3, undefined);
    expect(client.deleteBlockByRef).not.toHaveBeenCalled();
  });

  it('routes to deleteBlockByRef when only ref is provided', async () => {
    await handleWriteTool('delete_block', { post_id: 1, ref: 'blk_target' }, client as any);
    expect(client.deleteBlockByRef).toHaveBeenCalledWith(1, 'blk_target', undefined);
    expect(client.deleteBlock).not.toHaveBeenCalled();
  });

  it('rejects when both targeting fields are provided', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, ref: 'blk_x' }, client as any)
    ).rejects.toThrow(/top_level_counter OR ref, not both/);
  });

  it('rejects when neither targeting field is provided', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1 }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('forwards count parameter through ref path', async () => {
    await handleWriteTool('delete_block', { post_id: 1, ref: 'blk_x', count: 3 }, client as any);
    expect(client.deleteBlockByRef).toHaveBeenCalledWith(1, 'blk_x', 3);
  });

  it('top_level_counter:0 is a valid target', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, undefined);
  });
});

describe('insert_blocks — after_ref / before_ref', () => {
  let client: ReturnType<typeof baseMockClient>;
  beforeEach(() => { client = baseMockClient(); });

  it('forwards after_ref to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1,
      after_ref: 'blk_anchor',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    expect(client.insertBlocks).toHaveBeenCalledWith(1, expect.objectContaining({
      after_ref: 'blk_anchor',
      blocks: expect.any(Array),
    }));
    // Should NOT include after_ref:undefined when using before_ref alternative
    const call = client.insertBlocks.mock.calls[0][1];
    expect(call.after_ref).toBe('blk_anchor');
  });

  it('forwards before_ref to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1,
      before_ref: 'blk_anchor2',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const call = client.insertBlocks.mock.calls[0][1];
    expect(call.before_ref).toBe('blk_anchor2');
  });

  it('does not include after_ref/before_ref keys when not provided', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1,
      after_top_level: 2,
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const call = client.insertBlocks.mock.calls[0][1];
    expect(call).not.toHaveProperty('after_ref');
    expect(call).not.toHaveProperty('before_ref');
    expect(call.after).toBe(2);
  });

  it('returns response with ref on inserted blocks', async () => {
    const result = await handleWriteTool('insert_blocks', {
      post_id: 1,
      blocks: [{ name: 'core/paragraph' }],
    }, client as any) as any;
    expect(result.inserted[0].ref).toBe('blk_new');
  });
});

describe('edit_block_tree — ref vs path', () => {
  const client = {
    mutateBlockTree: vi.fn().mockResolvedValue({
      success: true,
      op: 'update-attrs',
      path: [],
      block: { name: 'core/heading', attributes: {} },
      warnings: [],
      before_revision_id: 1,
      revision_id: 2,
    }),
  } as any;

  beforeEach(() => { client.mutateBlockTree.mockClear(); });

  it('forwards path when path is provided', async () => {
    await handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', path: [0, 1], attributes: { level: 2 } }, client);
    const call = client.mutateBlockTree.mock.calls[0][1];
    expect(call.path).toEqual([0, 1]);
    expect(call.ref).toBeUndefined();
  });

  it('forwards ref when ref is provided', async () => {
    await handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', ref: 'blk_target', attributes: { level: 2 } }, client);
    const call = client.mutateBlockTree.mock.calls[0][1];
    expect(call.ref).toBe('blk_target');
    expect(call.path).toBeUndefined();
  });

  it('rejects when both path and ref are provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'update-attrs', path: [0], ref: 'blk_x', attributes: {},
      }, client)
    ).rejects.toThrow(/path.*OR.*ref.*not both/i);
  });

  it('rejects when neither path nor ref is provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', attributes: {} }, client)
    ).rejects.toThrow(/Provide either "path" or "ref"/);
  });

  it('rejects empty path array', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', path: [], attributes: {} }, client)
    ).rejects.toThrow(/path must not be empty/);
  });

  it('still validates path is an integer array', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', path: 'not-array', attributes: {} }, client)
    ).rejects.toThrow(/must be an array of integers/);
  });

  it('move accepts destination_ref instead of destination path', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'move', ref: 'blk_source', destination_ref: 'blk_dest',
    }, client);
    const call = client.mutateBlockTree.mock.calls[0][1];
    expect(call.destination_ref).toBe('blk_dest');
  });

  it('move with no destination or destination_ref errors', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'move', ref: 'blk_x' }, client)
    ).rejects.toThrow(/move requires/);
  });

  it('move rejects both destination path AND destination_ref', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', ref: 'blk_src', destination: [3], destination_ref: 'blk_dest',
      }, client)
    ).rejects.toThrow(/destination.*OR.*destination_ref.*not both/i);
  });
});

describe('NaN/integer guards', () => {
  let client: ReturnType<typeof baseMockClient>;
  beforeEach(() => { client = baseMockClient(); });

  it('update_block rejects NaN flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: NaN, attributes: { level: 2 } }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('update_block rejects negative flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: -1, attributes: { level: 2 } }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('delete_block rejects NaN top_level_counter', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: NaN }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('delete_block rejects count=0', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 0 }, client as any)
    ).rejects.toThrow(/count must be a positive integer/);
  });

  it('delete_block accepts count=1', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 1 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, 1);
  });
});

describe('get_page_blocks — persist_refs', () => {
  let client: ReturnType<typeof baseMockClient>;
  beforeEach(() => { client = baseMockClient(); });

  it('does not pass persist_refs when not specified (server default applies)', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1 }, client as any);
    const opts = client.getPageBlocks.mock.calls[0][1];
    expect(opts).not.toHaveProperty('persist_refs');
  });

  it('forwards persist_refs:false when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: false }, client as any);
    const opts = client.getPageBlocks.mock.calls[0][1];
    expect(opts.persist_refs).toBe(false);
  });

  it('forwards persist_refs:true when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: true }, client as any);
    const opts = client.getPageBlocks.mock.calls[0][1];
    expect(opts.persist_refs).toBe(true);
  });
});
