import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../tools/write.js';
import { enrichBlock, enrichBlocks } from '../enrichers.js';

vi.mock('../enrichers.js', () => ({
  enrichBlock: vi.fn(async (block: any) => ({
    ...block,
    attributes: { ...block.attributes, enriched: true },
  })),
  enrichBlocks: vi.fn(async (blocks: any[]) =>
    blocks.map((b: any) => ({ ...b, attributes: { ...b.attributes, enriched: true } }))
  ),
}));

const mockClient = {
  updateBlock: vi.fn().mockResolvedValue({ success: true, block: { index: 0, name: 'core/heading', attributes: {} }, before_revision_id: 1, revision_id: 2 }),
  updateBlocksBatch: vi.fn().mockResolvedValue({ success: true, count: 0, results: [], before_revision_id: 1, revision_id: 2 }),
  insertBlocks: vi.fn().mockResolvedValue({ success: true, inserted: [{ index: 0, name: 'core/heading' }], warnings: [], before_revision_id: 1, revision_id: 2 }),
  deleteBlock: vi.fn().mockResolvedValue({ success: true, removed: 1, before_revision_id: 1, revision_id: 2 }),
  replaceAllBlocks: vi.fn().mockResolvedValue({ success: true, inserted: [], warnings: [], before_revision_id: 1, revision_id: 2 }),
  revertToRevision: vi.fn().mockResolvedValue({ success: true, revision_id: 1 }),
} as any;

describe('handleWriteTool', () => {
  beforeEach(() => vi.clearAllMocks());

  describe('update_block', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('update_block', { flat_index: 0, attributes: {} }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires flat_index', async () => {
      await expect(handleWriteTool('update_block', { post_id: 1 }, mockClient))
        .rejects.toThrow('flat_index');
    });

    it('requires attributes or innerHTML', async () => {
      await expect(handleWriteTool('update_block', { post_id: 1, flat_index: 0 }, mockClient))
        .rejects.toThrow('attributes or innerHTML');
    });

    it('calls client with attributes', async () => {
      await handleWriteTool('update_block', { post_id: 1, flat_index: 0, attributes: { level: 3 } }, mockClient);
      expect(mockClient.updateBlock).toHaveBeenCalledWith(1, 0, { attributes: { level: 3 }, innerHTML: undefined });
    });

    it('calls client with innerHTML', async () => {
      await handleWriteTool('update_block', { post_id: 1, flat_index: 2, innerHTML: '<p>Hi</p>' }, mockClient);
      expect(mockClient.updateBlock).toHaveBeenCalledWith(1, 2, { attributes: undefined, innerHTML: '<p>Hi</p>' });
    });

    it('calls client with both attributes and innerHTML', async () => {
      await handleWriteTool('update_block', {
        post_id: 1, flat_index: 0, attributes: { level: 2 }, innerHTML: '<h2>Title</h2>'
      }, mockClient);
      expect(mockClient.updateBlock).toHaveBeenCalledWith(1, 0, {
        attributes: { level: 2 }, innerHTML: '<h2>Title</h2>'
      });
    });
  });

  describe('update_blocks', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('update_blocks', { updates: [{ ref: 'blk_a', innerHTML: 'x' }] }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires non-empty updates array', async () => {
      await expect(handleWriteTool('update_blocks', { post_id: 1, updates: [] }, mockClient))
        .rejects.toThrow('non-empty');
      await expect(handleWriteTool('update_blocks', { post_id: 1 }, mockClient))
        .rejects.toThrow('non-empty');
    });

    it('rejects items missing both ref and flat_index', async () => {
      await expect(handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [{ innerHTML: 'x' }],
      }, mockClient)).rejects.toThrow('exactly one of ref or flat_index');
    });

    it('rejects items with both ref and flat_index', async () => {
      await expect(handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [{ ref: 'blk_a', flat_index: 0, innerHTML: 'x' }],
      }, mockClient)).rejects.toThrow('exactly one of ref or flat_index');
    });

    it('rejects items missing payload', async () => {
      await expect(handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [{ ref: 'blk_a' }],
      }, mockClient)).rejects.toThrow('attributes or innerHTML');
    });

    it('reports the failing item index in error messages', async () => {
      await expect(handleWriteTool('update_blocks', {
        post_id: 1,
        updates: [
          { ref: 'blk_a', innerHTML: 'x' },
          { ref: 'blk_b' }, // missing payload
        ],
      }, mockClient)).rejects.toThrow('updates[1]');
    });

    it('forwards normalized items to the client', async () => {
      await handleWriteTool('update_blocks', {
        post_id: 42,
        updates: [
          { ref: 'blk_a', innerHTML: '<p>One</p>' },
          { flat_index: 5, attributes: { level: 3 } },
        ],
      }, mockClient);
      expect(mockClient.updateBlocksBatch).toHaveBeenCalledWith(42, [
        { ref: 'blk_a', innerHTML: '<p>One</p>' },
        { flat_index: 5, attributes: { level: 3 } },
      ]);
    });

    it('runs enrichers when block_name + attributes are supplied', async () => {
      await handleWriteTool('update_blocks', {
        post_id: 7,
        updates: [
          { ref: 'blk_x', block_name: 'core/heading', attributes: { level: 2 } },
        ],
      }, mockClient);
      // mock enrichBlock injects { enriched: true } on every attribute object
      const [, normalized] = mockClient.updateBlocksBatch.mock.calls[0];
      expect(normalized[0].attributes).toEqual({ level: 2, enriched: true });
    });
  });

  describe('insert_blocks', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('insert_blocks', { blocks: [{ name: 'core/paragraph' }] }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires blocks array', async () => {
      await expect(handleWriteTool('insert_blocks', { post_id: 1 }, mockClient))
        .rejects.toThrow('block');
    });

    it('requires non-empty blocks', async () => {
      await expect(handleWriteTool('insert_blocks', { post_id: 1, blocks: [] }, mockClient))
        .rejects.toThrow('block');
    });

    it('calls client with blocks and after_top_level position', async () => {
      await handleWriteTool('insert_blocks', {
        post_id: 1, after_top_level: 5, blocks: [{ name: 'core/paragraph', attributes: { content: 'Hi' } }]
      }, mockClient);
      expect(mockClient.insertBlocks).toHaveBeenCalledWith(1, expect.objectContaining({
        after: 5, before: undefined,
        blocks: expect.arrayContaining([
          expect.objectContaining({ name: 'core/paragraph', attributes: expect.objectContaining({ content: 'Hi' }) }),
        ]),
      }));
    });

    it('enriches warnings with formatted messages', async () => {
      mockClient.insertBlocks.mockResolvedValueOnce({
        success: true, inserted: [], before_revision_id: 1, revision_id: 2,
        warnings: [{ block: 'oldns/heading', message: 'AVOID', suggested_replacement: 'core/heading' }],
      });
      const result = await handleWriteTool('insert_blocks', {
        post_id: 1, blocks: [{ name: 'oldns/heading' }]
      }, mockClient) as any;
      expect(result.formatted_warnings).toBeDefined();
      expect(result.formatted_warnings[0]).toContain('WARNING');
    });

    it('returns raw result when no warnings', async () => {
      const result = await handleWriteTool('insert_blocks', {
        post_id: 1, blocks: [{ name: 'core/heading' }]
      }, mockClient) as any;
      expect(result.formatted_warnings).toBeUndefined();
      expect(result.success).toBe(true);
    });
  });

  describe('delete_block', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('delete_block', { top_level_counter: 0 }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires top_level_counter', async () => {
      await expect(handleWriteTool('delete_block', { post_id: 1 }, mockClient))
        .rejects.toThrow('top_level_counter');
    });

    it('calls client with counter', async () => {
      await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 2 }, mockClient);
      expect(mockClient.deleteBlock).toHaveBeenCalledWith(1, 2, undefined);
    });

    it('passes count', async () => {
      await handleWriteTool('delete_block', { post_id: 1, top_level_counter: 2, count: 3 }, mockClient);
      expect(mockClient.deleteBlock).toHaveBeenCalledWith(1, 2, 3);
    });
  });

  describe('rewrite_post_blocks', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('rewrite_post_blocks', { blocks: [{ name: 'core/paragraph' }] }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires blocks', async () => {
      await expect(handleWriteTool('rewrite_post_blocks', { post_id: 1 }, mockClient))
        .rejects.toThrow('block');
    });

    it('requires non-empty blocks', async () => {
      await expect(handleWriteTool('rewrite_post_blocks', { post_id: 1, blocks: [] }, mockClient))
        .rejects.toThrow('block');
    });

    it('calls client with blocks', async () => {
      const blocks = [{ name: 'core/heading', attributes: { level: 1 } }];
      await handleWriteTool('rewrite_post_blocks', { post_id: 1, blocks }, mockClient);
      expect(mockClient.replaceAllBlocks).toHaveBeenCalledWith(1,
        expect.arrayContaining([
          expect.objectContaining({ name: 'core/heading', attributes: expect.objectContaining({ level: 1 }) }),
        ])
      );
    });

    it('enriches warnings', async () => {
      mockClient.replaceAllBlocks.mockResolvedValueOnce({
        success: true, inserted: [], before_revision_id: 1, revision_id: 2,
        warnings: [{ block: 'oldns/text', message: 'AVOID', suggested_replacement: 'core/paragraph' }],
      });
      const result = await handleWriteTool('rewrite_post_blocks', {
        post_id: 1, blocks: [{ name: 'oldns/text' }]
      }, mockClient) as any;
      expect(result.formatted_warnings).toBeDefined();
      expect(result.formatted_warnings[0]).toContain('WARNING');
    });
  });

  describe('revert_to_revision', () => {
    it('requires post_id', async () => {
      await expect(handleWriteTool('revert_to_revision', { revision_id: 1 }, mockClient))
        .rejects.toThrow('post_id');
    });

    it('requires revision_id', async () => {
      await expect(handleWriteTool('revert_to_revision', { post_id: 1 }, mockClient))
        .rejects.toThrow('revision_id');
    });

    it('calls client correctly', async () => {
      await handleWriteTool('revert_to_revision', { post_id: 1, revision_id: 456 }, mockClient);
      expect(mockClient.revertToRevision).toHaveBeenCalledWith(1, 456);
    });
  });

  it('throws on unknown tool', async () => {
    await expect(handleWriteTool('unknown', {}, mockClient)).rejects.toThrow('Unknown write tool');
  });

  describe('enricher wiring', () => {
    it('update_block with block_name enriches attributes', async () => {
      await handleWriteTool('update_block', {
        post_id: 1,
        flat_index: 0,
        block_name: 'core/heading',
        attributes: { level: 2 },
      }, mockClient);
      expect(enrichBlock).toHaveBeenCalledWith({ name: 'core/heading', attributes: { level: 2 } });
      expect(mockClient.updateBlock).toHaveBeenCalledWith(
        1, 0, expect.objectContaining({ attributes: expect.objectContaining({ enriched: true }) })
      );
    });

    it('update_block without block_name skips enricher', async () => {
      await handleWriteTool('update_block', {
        post_id: 1,
        flat_index: 0,
        attributes: { level: 2 },
      }, mockClient);
      expect(enrichBlock).not.toHaveBeenCalled();
    });

    it('update_block enricher can update innerHTML', async () => {
      (enrichBlock as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
        name: 'core/heading',
        attributes: { level: 2, enriched: true },
        innerHTML: '<h2>Enriched</h2>',
      });
      await handleWriteTool('update_block', {
        post_id: 1,
        flat_index: 0,
        block_name: 'core/heading',
        attributes: { level: 2 },
        innerHTML: '<h2>Original</h2>',
      }, mockClient);
      expect(mockClient.updateBlock).toHaveBeenCalledWith(
        1, 0, { attributes: { level: 2, enriched: true }, innerHTML: '<h2>Enriched</h2>' }
      );
    });

    it('insert_blocks enriches blocks', async () => {
      await handleWriteTool('insert_blocks', {
        post_id: 1,
        blocks: [{ name: 'core/paragraph', attributes: { content: 'Hi' } }],
      }, mockClient);
      expect(enrichBlocks).toHaveBeenCalled();
      expect(mockClient.insertBlocks).toHaveBeenCalledWith(
        1,
        expect.objectContaining({
          blocks: expect.arrayContaining([expect.objectContaining({ attributes: expect.objectContaining({ enriched: true }) })]),
        })
      );
    });

    it('replace_block_range enriches blocks', async () => {
      mockClient.replaceBlocksRange = vi.fn().mockResolvedValue({
        success: true, inserted: [], warnings: [], before_revision_id: 1, revision_id: 2,
      });
      await handleWriteTool('replace_block_range', {
        post_id: 1,
        start: 0,
        count: 1,
        blocks: [{ name: 'core/paragraph', attributes: {} }],
      }, mockClient);
      expect(enrichBlocks).toHaveBeenCalled();
    });

    it('rewrite_post_blocks enriches blocks', async () => {
      await handleWriteTool('rewrite_post_blocks', {
        post_id: 1,
        blocks: [{ name: 'core/heading', attributes: { level: 1 } }],
      }, mockClient);
      expect(enrichBlocks).toHaveBeenCalled();
      expect(mockClient.replaceAllBlocks).toHaveBeenCalledWith(
        1,
        expect.arrayContaining([expect.objectContaining({ attributes: expect.objectContaining({ enriched: true }) })])
      );
    });
  });
});
