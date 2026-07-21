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
import { assertHasFormattedWarning, assertNoFormattedWarnings } from '../../helpers/request-matchers.js';

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

  it('rejects a float post_id', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1.5, start: 0, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: -1, start: 0, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleWriteTool('replace_block_range', {
        post_id: Number.MAX_SAFE_INTEGER + 1, start: 0, count: 1, blocks: [],
      }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
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

  it('rejects a fractional start', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, start: 1.5, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('start must be a non-negative integer');
  });

  it('rejects a NaN count', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, start: 0, count: NaN, blocks: [] }, client as any)
    ).rejects.toThrow('count must be a non-negative integer');
  });

  it('rejects an Infinity start', async () => {
    await expect(
      handleWriteTool('replace_block_range', { post_id: 1, start: Infinity, count: 1, blocks: [] }, client as any)
    ).rejects.toThrow('start must be a non-negative integer');
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

describe('replace_block_range — warning enrichment', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('adds formatted_warnings when response has warnings', async () => {
    client.replaceBlocksRange = vi.fn().mockResolvedValue({
      success: true, removed: 1,
      inserted: [{ index: 0, name: 'oldns/heading' }],
      warnings: [{ block: 'oldns/heading', message: 'AVOID', suggested_replacement: 'core/heading' }],
      before_revision_id: 1, revision_id: 2,
    }) as any;
    const result = await handleWriteTool('replace_block_range', {
      post_id: 1, start: 0, count: 1, blocks: [{ name: 'oldns/heading' }],
    }, client as any);
    assertHasFormattedWarning(result, 'WARNING');
    assertHasFormattedWarning(result, 'oldns/heading');
  });

  it('no formatted_warnings when response has none', async () => {
    client.replaceBlocksRange = vi.fn().mockResolvedValue({
      success: true, removed: 1,
      inserted: [{ index: 0, name: 'core/heading' }],
      warnings: [],
      before_revision_id: 1, revision_id: 2,
    }) as any;
    const result = await handleWriteTool('replace_block_range', {
      post_id: 1, start: 0, count: 1, blocks: [{ name: 'core/heading' }],
    }, client as any);
    assertNoFormattedWarnings(result);
  });
});
