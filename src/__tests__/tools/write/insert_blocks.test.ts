/**
 * Tool tests: insert_blocks
 *
 * Covers:
 *   - Input validation
 *   - Positioning params (after/before/after_ref/before_ref)
 *   - Enricher wiring
 *   - Warning enrichment (formatted_warnings)
 *   - Clean path (no warnings)
 *   - Ref in inserted blocks forwarded
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

describe('insert_blocks — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('insert_blocks', { blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id');
  });

  it('rejects a float post_id', async () => {
    await expect(
      handleWriteTool('insert_blocks', { post_id: 1.5, blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(
      handleWriteTool('insert_blocks', { post_id: -1, blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleWriteTool('insert_blocks', {
        post_id: Number.MAX_SAFE_INTEGER + 1,
        blocks: [{ name: 'core/paragraph' }],
      }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('requires at least one block', async () => {
    await expect(
      handleWriteTool('insert_blocks', { post_id: 1 }, client as any)
    ).rejects.toThrow('block');
  });

  it('rejects empty blocks array', async () => {
    await expect(
      handleWriteTool('insert_blocks', { post_id: 1, blocks: [] }, client as any)
    ).rejects.toThrow('block');
  });
});

describe('insert_blocks — positioning', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('forwards after_top_level as "after" to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, after_top_level: 5, blocks: [{ name: 'core/paragraph' }],
    }, client as any);
    expect(client.insertBlocks).toHaveBeenCalledWith(1, expect.objectContaining({ after: 5 }));
  });

  it('forwards before_top_level as "before" to client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, before_top_level: 2, blocks: [{ name: 'core/paragraph' }],
    }, client as any);
    expect(client.insertBlocks).toHaveBeenCalledWith(1, expect.objectContaining({ before: 2 }));
  });

  it('forwards after_ref to client body', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, after_ref: 'blk_anchor', blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const callArg = client.insertBlocks.mock.calls[0][1] as any;
    expect(callArg.after_ref).toBe('blk_anchor');
  });

  it('forwards before_ref to client body', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, before_ref: 'blk_anchor2', blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const callArg = client.insertBlocks.mock.calls[0][1] as any;
    expect(callArg.before_ref).toBe('blk_anchor2');
  });

  it('does not include after_ref/before_ref keys when not provided', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, after_top_level: 2, blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    }, client as any);
    const callArg = client.insertBlocks.mock.calls[0][1] as any;
    expect(callArg).not.toHaveProperty('after_ref');
    expect(callArg).not.toHaveProperty('before_ref');
  });
});

describe('insert_blocks — enricher wiring', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('passes blocks through enrichBlocks', async () => {
    const { enrichBlocks } = await import('../../../enrichers.js');
    (enrichBlocks as ReturnType<typeof vi.fn>).mockClear();
    await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'core/paragraph', attributes: { content: 'Hi' } }],
    }, client as any);
    expect(enrichBlocks).toHaveBeenCalled();
  });

  it('enriched attributes reach the client', async () => {
    await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'core/paragraph', attributes: { content: 'Hi' } }],
    }, client as any);
    const callArg = client.insertBlocks.mock.calls[0][1] as any;
    expect(callArg.blocks[0].attributes).toMatchObject({ content: 'Hi', enriched: true });
  });
});

describe('insert_blocks — warning enrichment', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('adds formatted_warnings when response has warnings', async () => {
    client.insertBlocks.mockResolvedValueOnce({
      success: true, inserted: [], before_revision_id: 1, revision_id: 2,
      warnings: [{ block: 'oldns/heading', message: 'AVOID', suggested_replacement: 'core/heading' }],
    });
    const result = await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'oldns/heading' }],
    }, client as any);
    assertHasFormattedWarning(result, 'WARNING');
    assertHasFormattedWarning(result, 'oldns/heading');
  });

  it('no formatted_warnings when response has none', async () => {
    const result = await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'core/heading' }],
    }, client as any);
    assertNoFormattedWarnings(result);
    expect((result as any).success).toBe(true);
  });

  it('returns ref on inserted blocks', async () => {
    client.insertBlocks.mockResolvedValueOnce({
      success: true,
      inserted: [{ index: 0, name: 'core/paragraph', ref: 'blk_new001' }],
      warnings: [], before_revision_id: 1, revision_id: 2,
    });
    const result = await handleWriteTool('insert_blocks', {
      post_id: 1, blocks: [{ name: 'core/paragraph' }],
    }, client as any) as any;
    expect(result.inserted[0].ref).toBe('blk_new001');
  });
});
