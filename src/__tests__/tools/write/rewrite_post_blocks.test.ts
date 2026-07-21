/**
 * Tool tests: rewrite_post_blocks and revert_to_revision
 *
 * rewrite_post_blocks: full page rewrite via replaceAllBlocks
 * revert_to_revision: revision rollback
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

// ── rewrite_post_blocks ───────────────────────────────────────────────────────

describe('rewrite_post_blocks — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', { blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id');
  });

  it('rejects a float post_id', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', { post_id: 1.5, blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', { post_id: -1, blocks: [{ name: 'core/paragraph' }] }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', {
        post_id: Number.MAX_SAFE_INTEGER + 1,
        blocks: [{ name: 'core/paragraph' }],
      }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('requires at least one block', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', { post_id: 1 }, client as any)
    ).rejects.toThrow('block');
  });

  it('rejects empty blocks array', async () => {
    await expect(
      handleWriteTool('rewrite_post_blocks', { post_id: 1, blocks: [] }, client as any)
    ).rejects.toThrow('block');
  });
});

describe('rewrite_post_blocks — forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('calls replaceAllBlocks with the block array', async () => {
    const blocks = [{ name: 'core/heading', attributes: { level: 1 } }];
    await handleWriteTool('rewrite_post_blocks', { post_id: 1, blocks }, client as any);
    expect(client.replaceAllBlocks).toHaveBeenCalledWith(1, expect.arrayContaining([
      expect.objectContaining({ name: 'core/heading' }),
    ]));
  });

  it('passes blocks through enrichBlocks', async () => {
    const { enrichBlocks } = await import('../../../enrichers.js');
    (enrichBlocks as ReturnType<typeof vi.fn>).mockClear();
    await handleWriteTool('rewrite_post_blocks', {
      post_id: 1, blocks: [{ name: 'core/heading', attributes: { level: 1 } }],
    }, client as any);
    expect(enrichBlocks).toHaveBeenCalled();
  });

  it('enriched attributes reach replaceAllBlocks', async () => {
    await handleWriteTool('rewrite_post_blocks', {
      post_id: 1, blocks: [{ name: 'core/heading', attributes: { level: 1 } }],
    }, client as any);
    const call = client.replaceAllBlocks.mock.calls[0] as unknown[];
    const blocks = call[1] as Array<{ attributes: Record<string, unknown> }>;
    expect(blocks[0].attributes).toMatchObject({ level: 1, enriched: true });
  });
});

describe('rewrite_post_blocks — warnings', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('adds formatted_warnings when response has warnings', async () => {
    client.replaceAllBlocks.mockResolvedValueOnce({
      success: true, inserted: [], before_revision_id: 1, revision_id: 2,
      warnings: [{ block: 'oldns/text', message: 'AVOID', suggested_replacement: 'core/paragraph' }],
    });
    const result = await handleWriteTool('rewrite_post_blocks', {
      post_id: 1, blocks: [{ name: 'oldns/text' }],
    }, client as any);
    assertHasFormattedWarning(result, 'WARNING');
  });

  it('no formatted_warnings when response is clean', async () => {
    const result = await handleWriteTool('rewrite_post_blocks', {
      post_id: 1, blocks: [{ name: 'core/heading', attributes: { level: 1 } }],
    }, client as any);
    assertNoFormattedWarnings(result);
  });
});

// ── revert_to_revision ────────────────────────────────────────────────────────

describe('revert_to_revision — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(
      handleWriteTool('revert_to_revision', { revision_id: 1 }, client as any)
    ).rejects.toThrow('post_id');
  });

  it('rejects a float post_id', async () => {
    await expect(
      handleWriteTool('revert_to_revision', { post_id: 1.5, revision_id: 1 }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(
      handleWriteTool('revert_to_revision', { post_id: -1, revision_id: 1 }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleWriteTool('revert_to_revision', { post_id: Number.MAX_SAFE_INTEGER + 1, revision_id: 1 }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('requires revision_id', async () => {
    await expect(
      handleWriteTool('revert_to_revision', { post_id: 1 }, client as any)
    ).rejects.toThrow('revision_id');
  });
});

describe('revert_to_revision — forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('calls revertToRevision with correct args', async () => {
    await handleWriteTool('revert_to_revision', { post_id: 1, revision_id: 456 }, client as any);
    expect(client.revertToRevision).toHaveBeenCalledWith(1, 456);
  });
});
