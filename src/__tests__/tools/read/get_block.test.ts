/**
 * Tool tests: get_block
 *
 * Covers:
 *   - Required: post_id
 *   - XOR: exactly one of ref or flat_index
 *   - Routing: ref → client.getBlock(postId, {ref})
 *   - Routing: flat_index → client.getBlock(postId, {flatIndex})
 *   - flat_index:0 is valid (Number.isFinite check, not truthy)
 *   - Response shape: flat self-describing block at the top level
 *     ({ success, post_id, name, ref?, flat_index, path?, attributes,
 *     inner_html, is_dynamic }) + `saved` back-compat alias
 *   - Flat fields derived from `saved` when the plugin sends only the
 *     legacy { success, saved } envelope
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

  it('rejects a float post_id', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1.5, ref: 'blk_a' }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(
      handleReadTool('get_block', { post_id: -1, ref: 'blk_a' }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleReadTool('get_block', { post_id: Number.MAX_SAFE_INTEGER + 1, ref: 'blk_a' }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
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

  it('treats a fractional flat_index as missing (must be an integer)', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1, flat_index: 1.5 }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
    expect(client.getBlock).not.toHaveBeenCalled();
  });

  // update_block / delete_block reject a negative flat_index client-side;
  // get_block must match so a negative index is treated as absent, not sent
  // to the server as a valid target.
  it('treats a negative flat_index as missing (still requires ref)', async () => {
    await expect(
      handleReadTool('get_block', { post_id: 1, flat_index: -1 }, client as any)
    ).rejects.toThrow(/exactly one of ref or flat_index/);
    expect(client.getBlock).not.toHaveBeenCalled();
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

  it('returns the block flat at the top level (name, ref, attributes, inner_html)', async () => {
    // The regression this pins: the tool returned only { success, saved },
    // so a caller reading result.inner_html or result.name got undefined and
    // had to discover the `saved` envelope by dumping keys.
    const result = await handleReadTool('get_block', { post_id: 1, ref: 'blk_para0001' }, client as any) as Record<string, unknown>;
    expect(result.success).toBe(true);
    expect(result.post_id).toBe(1);
    expect(result.name).toBe('core/paragraph');
    expect(result.ref).toBe('blk_para0001');
    expect(result.flat_index).toBe(0);
    expect(result.attributes).toEqual({ content: 'Hello world.' });
    expect(result.inner_html).toBe('<p>Hello world.</p>');
    expect(result.is_dynamic).toBe(false);
  });

  it('keeps `saved` as a back-compat alias with a valid saved block', async () => {
    const result = await handleReadTool('get_block', { post_id: 1, ref: 'blk_a' }, client as any) as {
      success: boolean; saved: unknown;
    };
    expect(result.success).toBe(true);
    assertSavedBlock(result.saved);
  });

  it('derives the flat fields from `saved` when the plugin sends only the legacy envelope', async () => {
    // getBlockResponse is exactly the legacy { success, saved } envelope —
    // no flat fields — which is what pre-2.3 plugins return.
    expect(Object.keys(getBlockResponse).sort()).toEqual(['saved', 'success']);
    const result = await handleReadTool('get_block', { post_id: 7, flat_index: 0 }, client as any) as Record<string, unknown>;
    const saved = getBlockResponse.saved;
    expect(result.post_id).toBe(7);
    expect(result.name).toBe(saved.block_name);
    expect(result.ref).toBe(saved.ref);
    expect(result.flat_index).toBe(saved.flat_index);
    expect(result.attributes).toEqual(saved.attributes);
    expect(result.inner_html).toBe(saved.inner_html);
    expect(result.is_dynamic).toBe(saved.is_dynamic);
  });

  it('passes through flat fields and path when the plugin sends them', async () => {
    client.getBlock.mockResolvedValue({
      success: true,
      post_id: 9,
      name: 'core/heading',
      ref: 'blk_head0001',
      flat_index: 3,
      path: [2, 0],
      attributes: { level: 2 },
      inner_html: '<h2>Hi</h2>',
      is_dynamic: false,
      saved: {
        flat_index: 3,
        block_name: 'core/heading',
        attributes: { level: 2 },
        inner_html: '<h2>Hi</h2>',
        is_dynamic: false,
        ref: 'blk_head0001',
      },
    });
    const result = await handleReadTool('get_block', { post_id: 9, ref: 'blk_head0001' }, client as any) as Record<string, unknown>;
    expect(result.name).toBe('core/heading');
    expect(result.path).toEqual([2, 0]);
    expect(result.post_id).toBe(9);
    expect(result.inner_html).toBe('<h2>Hi</h2>');
  });

  it('omits `ref` when the block has none', async () => {
    client.getBlock.mockResolvedValue({
      success: true,
      saved: {
        flat_index: 2,
        block_name: 'core/separator',
        attributes: {},
        inner_html: '<hr class="wp-block-separator"/>',
        is_dynamic: false,
      },
    });
    const result = await handleReadTool('get_block', { post_id: 1, flat_index: 2 }, client as any) as Record<string, unknown>;
    expect('ref' in result).toBe(false);
    expect(result.name).toBe('core/separator');
  });
});
