/**
 * Tool tests: edit_block_tree — ref vs path addressing
 *
 * Covers:
 *   - ref forwarded when ref is provided (path absent)
 *   - path forwarded when path is provided (ref absent)
 *   - both path and ref rejected
 *   - neither path nor ref rejected
 *   - empty path array rejected
 *   - path integer-array validation still applies with ref
 *   - move: destination_ref accepted instead of destination path
 *   - move: both destination + destination_ref rejected
 *   - move: neither destination nor destination_ref rejected
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleMutateTool } from '../../../tools/mutate.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { mutationUpdateAttrsResponse } from '../../fixtures/rest-responses.js';

describe('edit_block_tree — ref vs path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
    vi.clearAllMocks();
  });

  it('forwards path when only path is provided', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0, 1], attributes: { level: 2 },
    }, client as any);
    const call = client.mutateBlockTree.mock.calls[0]![1] as Record<string, unknown>;
    expect(call.path).toEqual([0, 1]);
    expect(call.ref).toBeUndefined();
  });

  it('forwards ref when only ref is provided', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', ref: 'blk_target', attributes: { level: 2 },
    }, client as any);
    const call = client.mutateBlockTree.mock.calls[0]![1] as Record<string, unknown>;
    expect(call.ref).toBe('blk_target');
    expect(call.path).toBeUndefined();
  });

  it('rejects when both path and ref are provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'update-attrs', path: [0], ref: 'blk_x', attributes: {},
      }, client as any)
    ).rejects.toThrow(/path.*OR.*ref.*not both/i);
  });

  it('rejects when neither path nor ref is provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either "path" or "ref"/);
  });

  it('rejects empty path array', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', path: [], attributes: {} }, client as any)
    ).rejects.toThrow(/path must not be empty/);
  });

  it('still validates path is an array of integers', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'update-attrs', path: 'not-array', attributes: {},
      }, client as any)
    ).rejects.toThrow(/must be an array of integers/);
  });
});

describe('edit_block_tree — move: destination_ref', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue({ ...mutationUpdateAttrsResponse, op: 'move' as any });
    vi.clearAllMocks();
  });

  it('accepts destination_ref instead of destination path', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'move', ref: 'blk_source', destination_ref: 'blk_dest',
    }, client as any);
    const call = client.mutateBlockTree.mock.calls[0]![1] as Record<string, unknown>;
    expect(call.destination_ref).toBe('blk_dest');
  });

  it('rejects when no destination or destination_ref provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'move', ref: 'blk_x' }, client as any)
    ).rejects.toThrow(/move requires/);
  });

  it('rejects when both destination path AND destination_ref are provided', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', ref: 'blk_src', destination: [3], destination_ref: 'blk_dest',
      }, client as any)
    ).rejects.toThrow(/destination.*OR.*destination_ref.*not both/i);
  });
});
