/**
 * Tool tests: update_block
 *
 * Covers:
 *   - Input validation (post_id, flat_index/ref XOR, attributes/innerHTML)
 *   - Index path → client.updateBlock
 *   - Ref path → client.updateBlockByRef
 *   - Enricher wiring (block_name triggers enrichBlock)
 *   - Response shape (success, saved snapshot, revision IDs)
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../../../tools/write.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { assertBlockUpdateResponse } from '../../helpers/schema-asserts.js';

vi.mock('../../../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => ({
    ...block,
    attributes: { ...block.attributes, enriched: true },
  })),
  enrichBlocks: vi.fn(async (blocks: any[]) =>
    blocks.map((b: any) => ({ ...b, attributes: { ...b.attributes, enriched: true } }))
  ),
}));

// ── Fixtures ──────────────────────────────────────────────────────────────────

const UPDATE_RESPONSE = {
  success: true,
  block: { index: 1, name: 'core/heading', attributes: { level: 3 }, ref: 'blk_head0001' },
  saved: {
    flat_index: 1, block_name: 'core/heading',
    attributes: { level: 3 }, inner_html: '<h3>Title</h3>', is_dynamic: false, ref: 'blk_head0001',
  },
  before_revision_id: 100,
  revision_id: 101,
};

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('update_block — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(handleWriteTool('update_block', { flat_index: 0, attributes: {} }, client as any))
      .rejects.toThrow('post_id');
  });

  it('rejects a float post_id', async () => {
    await expect(handleWriteTool('update_block', { post_id: 1.5, flat_index: 0, attributes: {} }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(handleWriteTool('update_block', { post_id: -1, flat_index: 0, attributes: {} }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: Number.MAX_SAFE_INTEGER + 1, flat_index: 0, attributes: {} }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('requires flat_index OR ref (not neither)', async () => {
    await expect(handleWriteTool('update_block', { post_id: 1, attributes: {} }, client as any))
      .rejects.toThrow(/Provide either flat_index/);
  });

  it('rejects when both flat_index and ref provided', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: 0, ref: 'blk_x', attributes: {} }, client as any)
    ).rejects.toThrow(/flat_index OR ref, not both/);
  });

  it('rejects empty-string ref (treats as absent)', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, ref: '', attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('requires attributes or innerHTML', async () => {
    await expect(handleWriteTool('update_block', { post_id: 1, flat_index: 0 }, client as any))
      .rejects.toThrow(/attributes or innerHTML/);
  });

  it('rejects negative flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: -1, attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });

  it('rejects NaN flat_index', async () => {
    await expect(
      handleWriteTool('update_block', { post_id: 1, flat_index: NaN, attributes: {} }, client as any)
    ).rejects.toThrow(/Provide either flat_index/);
  });
});

describe('update_block — index path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); client.updateBlock.mockResolvedValue(UPDATE_RESPONSE); vi.clearAllMocks(); });

  it('routes to updateBlock (not updateBlockByRef)', async () => {
    await handleWriteTool('update_block', { post_id: 1, flat_index: 5, attributes: { level: 3 } }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 5, { attributes: { level: 3 }, innerHTML: undefined });
    expect(client.updateBlockByRef).not.toHaveBeenCalled();
  });

  it('passes innerHTML through index path', async () => {
    await handleWriteTool('update_block', { post_id: 1, flat_index: 2, innerHTML: '<p>Hi</p>' }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 2, { attributes: undefined, innerHTML: '<p>Hi</p>' });
  });

  it('passes both attributes and innerHTML', async () => {
    await handleWriteTool('update_block', {
      post_id: 1, flat_index: 0, attributes: { level: 2 }, innerHTML: '<h2>Title</h2>'
    }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 0, { attributes: { level: 2 }, innerHTML: '<h2>Title</h2>' });
  });

  it('flat_index=0 is a valid target', async () => {
    await handleWriteTool('update_block', { post_id: 1, flat_index: 0, attributes: { level: 1 } }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 0, expect.any(Object));
  });
});

describe('update_block — ref path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); client.updateBlockByRef.mockResolvedValue(UPDATE_RESPONSE); vi.clearAllMocks(); });

  it('routes to updateBlockByRef (not updateBlock)', async () => {
    await handleWriteTool('update_block', { post_id: 1, ref: 'blk_abc12345', attributes: { level: 3 } }, client as any);
    expect(client.updateBlockByRef).toHaveBeenCalledWith(1, 'blk_abc12345', { attributes: { level: 3 }, innerHTML: undefined });
    expect(client.updateBlock).not.toHaveBeenCalled();
  });

  it('passes innerHTML through ref path', async () => {
    await handleWriteTool('update_block', { post_id: 1, ref: 'blk_x', innerHTML: '<h2>hi</h2>' }, client as any);
    expect(client.updateBlockByRef).toHaveBeenCalledWith(1, 'blk_x', { attributes: undefined, innerHTML: '<h2>hi</h2>' });
  });
});

describe('update_block — enricher wiring', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); client.updateBlock.mockResolvedValue(UPDATE_RESPONSE); vi.clearAllMocks(); });

  it('skips enricher when block_name is absent', async () => {
    const enrichers = await import('../../../enrichers.js');
    const localEnrichBlock = enrichers.enrichBlock as ReturnType<typeof vi.fn>;
    localEnrichBlock.mockClear();
    await handleWriteTool('update_block', { post_id: 1, flat_index: 0, attributes: { level: 2 } }, client as any);
    expect(localEnrichBlock).not.toHaveBeenCalled();
  });

  it('calls enrichBlock when block_name is provided', async () => {
    const enrichers = await import('../../../enrichers.js');
    const localEnrichBlock = enrichers.enrichBlock as ReturnType<typeof vi.fn>;
    localEnrichBlock.mockClear();
    await handleWriteTool('update_block', {
      post_id: 1, flat_index: 0, block_name: 'core/heading', attributes: { level: 2 },
    }, client as any);
    expect(localEnrichBlock).toHaveBeenCalledWith({ name: 'core/heading', attributes: { level: 2 } });
  });

  it('enricher-updated attributes reach the client', async () => {
    await handleWriteTool('update_block', {
      post_id: 1, flat_index: 0, block_name: 'core/heading', attributes: { level: 2 },
    }, client as any);
    const call = client.updateBlock.mock.calls[0] as unknown[];
    const data = call[2] as { attributes: Record<string, unknown> };
    expect(data.attributes).toMatchObject({ level: 2, enriched: true });
  });

  it('enricher can update innerHTML', async () => {
    const enrichers = await import('../../../enrichers.js');
    const localEnrichBlock = enrichers.enrichBlock as ReturnType<typeof vi.fn>;
    localEnrichBlock.mockResolvedValueOnce({
      name: 'core/heading',
      attributes: { level: 2, enriched: true },
      innerHTML: '<h2>Enriched</h2>',
    });
    await handleWriteTool('update_block', {
      post_id: 1, flat_index: 0, block_name: 'core/heading',
      attributes: { level: 2 }, innerHTML: '<h2>Original</h2>',
    }, client as any);
    expect(client.updateBlock).toHaveBeenCalledWith(1, 0, {
      attributes: { level: 2, enriched: true }, innerHTML: '<h2>Enriched</h2>',
    });
  });
});

describe('update_block — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); client.updateBlock.mockResolvedValue(UPDATE_RESPONSE); vi.clearAllMocks(); });

  it('returns a valid BlockUpdateResponse shape', async () => {
    const result = await handleWriteTool('update_block', {
      post_id: 1, flat_index: 1, attributes: { level: 3 }
    }, client as any);
    assertBlockUpdateResponse(result);
  });

  it('saved block matches expected shape', async () => {
    const result = await handleWriteTool('update_block', {
      post_id: 1, flat_index: 1, attributes: { level: 3 }
    }, client as any) as any;
    expect(result.saved.block_name).toBe('core/heading');
    expect(result.saved.flat_index).toBe(1);
    expect(result.saved.is_dynamic).toBe(false);
  });

  it('revision IDs are present', async () => {
    const result = await handleWriteTool('update_block', {
      post_id: 1, flat_index: 1, attributes: { level: 3 }
    }, client as any) as any;
    expect(result.before_revision_id).toBe(100);
    expect(result.revision_id).toBe(101);
  });
});
