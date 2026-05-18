/**
 * Tool tests: get_block
 *
 * Covers:
 *   - Required: post_id
 *   - XOR: exactly one of ref or flat_index
 *   - Routing: ref → client.getBlock(postId, {ref})
 *   - Routing: flat_index → client.getBlock(postId, {flatIndex})
 *   - flat_index:0 is valid (Number.isFinite check, not truthy)
 *   - Response shape: { success, saved } via assertSavedBlock
 *   - Empty string ref treated as missing (XOR with flat_index)
 *   - Non-finite flat_index (NaN/Infinity) treated as missing
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleReadTool } from '../../../tools/read.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { getBlockResponse } from '../../fixtures/rest-responses.js';
import { assertSavedBlock } from '../../helpers/schema-asserts.js';

describe('get_block — input validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlock.mockResolvedValue(getBlockResponse);
  });

  it('requires post_id', async () => {
    await expect(
      handleReadTool('get_block', { ref: 'blk_a' }, client as any)
    ).rejects.toThrow(/post_id is required/);
  });

  it('requires exactly one of ref or flat_index (rejects neither)', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1 }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
  });

  it('rejects both ref and flat_index together', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1, ref: 'blk_a', flat_index: 0 }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
  });

  it('treats empty-string ref as missing (still requires flat_index)', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1, ref: '' }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
  });

  it('treats NaN flat_index as missing (still requires ref)', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1, flat_index: NaN }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
  });
});

describe('get_block — routing', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlock.mockResolvedValue(getBlockResponse);
  });

  it('forwards ref to client.getBlock', async () => {
    await handleReadTool('get_block', { post_id: 42, ref: 'blk_a' }, client as any);
    expect(client.getBlock).toHaveBeenCalledWith(42, { ref: 'blk_a' });
  });

  it('forwards flat_index to client.getBlock as flatIndex (camelCase)', async () => {
    await handleReadTool('get_block', { post_id: 42, flat_index: 3 }, client as any);
    expect(client.getBlock).toHaveBeenCalledWith(42, { flatIndex: 3 });
  });

  it('flat_index:0 is a valid lookup (not falsy-rejected)', async () => {
    await handleReadTool('get_block', { post_id: 1, flat_index: 0 }, client as any);
    expect(client.getBlock).toHaveBeenCalledWith(1, { flatIndex: 0 });
  });
});

describe('get_block — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlock.mockResolvedValue(getBlockResponse);
  });

  it('returns { success, saved } with a valid saved block', async () => {
    const result = await handleReadTool('get_block', { post_id: 1, ref: 'blk_a' }, client as any) as {
      success: boolean; saved: unknown;
    };
    expect(result.success).toBe(true);
    assertSavedBlock(result.saved);
  });
});
