/**
 * Tool tests: ref-based addressing for write tools
 *
 * Covers:
 *   - update_block: flat_index path vs ref path (XOR validation + routing)
 *   - delete_block: top_level_counter vs ref (XOR validation + routing)
 *   - insert_blocks: after_ref / before_ref forwarded to client
 *   - NaN / negative integer guards for flat_index and top_level_counter
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../../../tools/write.js';
import { makeMockClient } from '../../helpers/mock-client.js';

vi.mock('../../../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => block),
  enrichBlocks: vi.fn(async (blocks: any[]) => blocks),
}));

describe('update_block — ref vs flat_index routing', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

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

  it('rejects empty-string ref', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, ref: '', attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('passes innerHTML through ref path', async () => {
    await handleWriteTool('update_block', { post_id: 1, ref: 'blk_x', innerHTML: '<h2>hi</h2>' }, client as any);
    expect(client.updateBlockByRef).toHaveBeenCalledWith(1, 'blk_x', { attributes: undefined, innerHTML: '<h2>hi</h2>' });
  });
});

describe('delete_block — ref vs top_level_counter routing', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

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

  it('rejects when both top_level_counter and ref are provided', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, ref: 'blk_x' }, client as any)
    ).rejects.toThrow(/top_level_counter OR ref, not both/);
  });

  it('rejects when neither targeting field is provided', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1 }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('top_level_counter:0 is a valid target', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, undefined);
  });

  it('forwards count parameter through ref path', async () => {
    await handleWriteTool('delete_block', { post_id: 1, ref: 'blk_x', count: 3 }, client as any);
    expect(client.deleteBlockByRef).toHaveBeenCalledWith(1, 'blk_x', 3);
  });
});

describe('insert_blocks — after_ref / before_ref', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('forwards after_ref to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, after_ref: 'blk_anchor',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const call = client.insertBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(call.after_ref).toBe('blk_anchor');
  });

  it('forwards before_ref to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, before_ref: 'blk_anchor2',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const call = client.insertBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(call.before_ref).toBe('blk_anchor2');
  });

  it('does not include after_ref/before_ref when using numeric position', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, after_top_level: 2,
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const call = client.insertBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(call).not.toHaveProperty('after_ref');
    expect(call).not.toHaveProperty('before_ref');
    expect(call.after).toBe(2);
  });

  it('response preserves ref on inserted blocks', async () => {
    client.insertBlocks.mockResolvedValueOnce({
      success: true,
      inserted: [{ index: 0, name: 'core/heading', ref: 'blk_new001' }],
      warnings: [], before_revision_id: 1, revision_id: 2,
    });
    const result = await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'core/paragraph' }],
    }, client as any) as any;
    expect(result.inserted[0].ref).toBe('blk_new001');
  });
});

describe('NaN / integer guards', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('update_block rejects NaN flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: NaN, attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('update_block rejects negative flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: -1, attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('delete_block rejects NaN top_level_counter', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: NaN }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('delete_block rejects count=0 (must be positive)', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 0 }, client as any)
    ).rejects.toThrow(/count must be a positive integer/);
  });

  it('delete_block accepts count=1', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 1 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, 1);
  });
});
