/**
 * Tool tests: delete_block
 *
 * Covers:
 *   - Input validation (post_id, top_level_counter/ref XOR, count bounds)
 *   - Index path → client.deleteBlock
 *   - Ref path → client.deleteBlockByRef
 *   - count forwarding
 *   - Edge cases: counter=0, count=1
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../../../tools/write.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('delete_block — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('delete_block', { top_level_counter: 0 }, client as any)
    ).rejects.toThrow('post_id');
  });

  it('requires top_level_counter OR ref (not neither)', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1 }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('rejects when both top_level_counter and ref provided', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, ref: 'blk_x' }, client as any)
    ).rejects.toThrow(/top_level_counter OR ref, not both/);
  });

  it('rejects NaN top_level_counter', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: NaN }, client as any)
    ).rejects.toThrow(/Provide either top_level_counter/);
  });

  it('rejects count=0 (must be positive)', async () => {
    await expect(
      handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 0 }, client as any)
    ).rejects.toThrow(/count must be a positive integer/);
  });
});

describe('delete_block — index path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('routes to deleteBlock (not deleteBlockByRef)', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 3 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 3, undefined);
    expect(client.deleteBlockByRef).not.toHaveBeenCalled();
  });

  it('top_level_counter=0 is a valid target', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, undefined);
  });

  it('forwards count to deleteBlock', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 2, count: 3 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 2, 3);
  });

  it('count=1 is accepted', async () => {
    await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 0, count: 1 }, client as any);
    expect(client.deleteBlock).toHaveBeenCalledWith(1, 0, 1);
  });
});

describe('delete_block — ref path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('routes to deleteBlockByRef (not deleteBlock)', async () => {
    await handleWriteTool('delete_block', { post_id: 1, ref: 'blk_target' }, client as any);
    expect(client.deleteBlockByRef).toHaveBeenCalledWith(1, 'blk_target', undefined);
    expect(client.deleteBlock).not.toHaveBeenCalled();
  });

  it('forwards count through ref path', async () => {
    await handleWriteTool('delete_block', { post_id: 1, ref: 'blk_x', count: 3 }, client as any);
    expect(client.deleteBlockByRef).toHaveBeenCalledWith(1, 'blk_x', 3);
  });
});
