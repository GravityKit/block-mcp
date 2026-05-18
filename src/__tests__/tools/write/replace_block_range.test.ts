/**
 * Tool tests: replace_block_range
 *
 * Covers:
 *   - Input validation (post_id, start, count, blocks)
 *   - Client forwarding
 *   - Enricher wiring
 *   - Warning enrichment
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../../../tools/write.js';
import { makeMockClient } from '../../helpers/mock-client.js';

vi.mock('../../../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => block),
  enrichBlocks: vi.fn(async (blocks: any[]) =>
    blocks.map((b: any) => ({ ...b, attributes: { ...b.attributes, enriched: true } }))
  ),
}));

describe('replace_block_range — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('replace_block_range', { start: 0, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('post_id');
  });

  it('requires start', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('start');
  });

  it('requires count', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, start: 0, blocks: [] }, client as any)
    ).rejects.toThrow('count');
  });

  it('requires blocks array', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, start: 0, count: 1 }, client as any)
    ).rejects.toThrow('block');
  });
});

describe('replace_block_range — forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.replaceBlocksRange = vi.fn().mockResolvedValue({
      success: true, removed: 1,
      inserted: [{ index: 0, name: 'core/paragraph' }],
      warnings: [],
      before_revision_id: 1, revision_id: 2,
    }) as any;
    vi.clearAllMocks();
  });

  it('calls replaceBlocksRange with start, count, and blocks', async () => {
    await handleWriteTool('replace_block_range', {
      post_id: 1, start: 2, count: 3,
      blocks: [{ name: 'core/paragraph', attributes: {} }],
    }, client as any);
    expect(client.replaceBlocksRange).toHaveBeenCalledWith(1, expect.objectContaining({
      start: 2, count: 3,
    }));
  });

  it('passes blocks through enrichBlocks', async () => {
    const { enrichBlocks } = await import('../../../enrichers.js');
    (enrichBlocks as ReturnType<typeof vi.fn>).mockClear();
    await handleWriteTool('replace_block_range', {
      post_id: 1, start: 0, count: 1,
      blocks: [{ name: 'core/paragraph', attributes: {} }],
    }, client as any);
    expect(enrichBlocks).toHaveBeenCalled();
  });

  it('accepts empty blocks array (pure deletion)', async () => {
    await handleWriteTool('replace_block_range', {
      post_id: 1, start: 0, count: 2, blocks: [],
    }, client as any);
    expect(client.replaceBlocksRange).toHaveBeenCalled();
  });
});
