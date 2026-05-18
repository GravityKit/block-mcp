/**
 * Tool tests: update_blocks (batch update)
 *
 * Covers:
 *   - Input validation (post_id, non-empty updates, per-item ref/flat_index XOR)
 *   - Per-item payload validation (must have attributes or innerHTML)
 *   - Error message includes failing item index
 *   - Correct forwarding of normalized items to client.updateBlocksBatch
 *   - Enricher wiring for block_name items
 *   - verbose flag forwarding
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../../../tools/write.js';
import { makeMockClient } from '../../helpers/mock-client.js';

vi.mock('../../../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => ({
    ...block,
    attributes: { ...block.attributes, enriched: true },
  })),
  enrichBlocks: vi.fn(async (blocks: any[]) =>
    blocks.map((b: any) => ({ ...b, attributes: { ...b.attributes, enriched: true } }))
  ),
}));

describe('update_blocks — validation: post_id', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('update_blocks', { updates: [{ ref: 'blk_a', innerHTML: 'x' }] }, client as any)
    ).rejects.toThrow('post_id');
  });
});

describe('update_blocks — validation: updates array', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects empty updates array', async () => {
    await expect(
      handleWriteTool('update_blocks', { post_id: 1, updates: [] }, client as any)
    ).rejects.toThrow('non-empty');
  });

  it('rejects missing updates field', async () => {
    await expect(
      handleWriteTool('update_blocks', { post_id: 1 }, client as any)
    ).rejects.toThrow('non-empty');
  });
});

describe('update_blocks — per-item validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects items missing both ref and flat_index', async () => {
    await expect(
      handleWriteTool('update_blocks', { post_id: 1, updates: [{ innerHTML: 'x' }] }, client as any)
    ).rejects.toThrow('exactly one of ref or flat_index');
  });

  it('rejects items with both ref and flat_index', async () => {
    await expect(
      handleWriteTool('update_blocks', {
        post_id: 1, updates: [{ ref: 'blk_a', flat_index: 0, innerHTML: 'x' }],
      }, client as any)
    ).rejects.toThrow('exactly one of ref or flat_index');
  });

  it('rejects items missing payload (no attributes or innerHTML)', async () => {
    await expect(
      handleWriteTool('update_blocks', { post_id: 1, updates: [{ ref: 'blk_a' }] }, client as any)
    ).rejects.toThrow('attributes or innerHTML');
  });

  it('error message includes the failing item index', async () => {
    await expect(
      handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [
          { ref: 'blk_a', innerHTML: 'x' },
          { ref: 'blk_b' }, // missing payload at index 1
        ],
      }, client as any)
    ).rejects.toThrow('updates[1]');
  });

  it('error includes index for failing item deeper in the array', async () => {
    await expect(
      handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [
          { ref: 'blk_a', innerHTML: 'x' },
          { ref: 'blk_b', attributes: { level: 2 } },
          { innerHTML: 'y' }, // missing ref/flat_index at index 2
        ],
      }, client as any)
    ).rejects.toThrow('updates[2]');
  });
});

describe('update_blocks — forwarding to client', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('forwards normalized items to updateBlocksBatch', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 42,
      updates: [
        { ref: 'blk_a', innerHTML: '<p>One</p>' },
        { flat_index: 5, attributes: { level: 3 } },
      ],
    }, client as any);
    expect(client.updateBlocksBatch).toHaveBeenCalledWith(42, [
      { ref: 'blk_a', innerHTML: '<p>One</p>' },
      { flat_index: 5, attributes: { level: 3 } },
    ]);
  });

  it('omits verbose option when flag is not set (two-arg call)', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 7, updates: [{ ref: 'blk_x', attributes: { level: 2 } }],
    }, client as any);
    expect(client.updateBlocksBatch.mock.calls[0]).toHaveLength(2);
  });

  it('forwards verbose:true when requested (three-arg call)', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 7, updates: [{ ref: 'blk_x', attributes: { level: 2 } }], verbose: true,
    }, client as any);
    expect(client.updateBlocksBatch).toHaveBeenCalledWith(
      7,
      [{ ref: 'blk_x', attributes: { level: 2 } }],
      { verbose: true }
    );
  });

  it('treats non-boolean verbose as false (defensive)', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 7, updates: [{ ref: 'blk_x', attributes: { level: 2 } }],
      verbose: 'true' as unknown as boolean,
    }, client as any);
    expect(client.updateBlocksBatch.mock.calls[0]).toHaveLength(2);
  });
});

describe('update_blocks — enricher wiring', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('enriches item attributes when block_name is supplied', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 7,
      updates: [{ ref: 'blk_x', block_name: 'core/heading', attributes: { level: 2 } }],
    }, client as any);
    const call = client.updateBlocksBatch.mock.calls[0] as unknown[];
    const normalized = call[1] as Array<{ attributes: Record<string, unknown> }>;
    expect(normalized[0].attributes).toEqual({ level: 2, enriched: true });
  });

  it('passes item through unchanged when block_name is absent', async () => {
    await handleWriteTool('update_blocks', {
      post_id: 7,
      updates: [{ ref: 'blk_x', attributes: { level: 2 } }],
    }, client as any);
    const call = client.updateBlocksBatch.mock.calls[0] as unknown[];
    const normalized = call[1] as Array<{ attributes: Record<string, unknown> }>;
    expect(normalized[0].attributes).toEqual({ level: 2 });
  });
});
