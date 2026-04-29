import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleWriteTool } from '../tools/write.js';

const mockClient = {
  updateBlock: vi.fn().mockResolvedValue({ success: true, block: { index: 0, name: 'core/heading', attributes: {} }, before_revision_id: 1, revision_id: 2 }),
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
      expect(mockClient.insertBlocks).toHaveBeenCalledWith(1, {
        after: 5, before: undefined,
        blocks: [{ name: 'core/paragraph', attributes: { content: 'Hi' } }],
      });
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
      expect(mockClient.replaceAllBlocks).toHaveBeenCalledWith(1, blocks);
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
});
