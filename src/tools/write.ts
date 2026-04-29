/**
 * Write Tools — single-block updates, insertion, deletion, range/full
 * replacement, revert. All ops create a WordPress revision.
 *
 * Naming conventions (1.4.0+):
 *   - `flat_index`         = sequential position across ALL blocks (incl. nested).
 *   - `top_level_counter`  = sequential position among top-level blocks only.
 *   - tool names use verb_noun; range tools spell out the scope.
 */

import type { WordPressBlockClient } from '../client.js';
import { formatPreferenceWarning } from '../preferences.js';

/** Shape shared by every block-input arg in this module. */
const BLOCK_INPUT_SCHEMA = {
  type: 'object',
  properties: {
    name: { type: 'string', description: 'Fully-qualified block name (e.g. "core/heading").' },
    attributes: { type: 'object', description: 'Block attributes.' },
    innerHTML: { type: 'string', description: 'Raw HTML content.' },
  },
  required: ['name'],
} as const;

export const WRITE_TOOLS = [
  {
    name: 'update_block',
    description:
      'Update one block by flat_index. attributes are SHALLOW-merged at top level — pass full arrays, not deltas. innerHTML replaces atomically. For dual-storage blocks (e.g. yoast/faq-block) you MUST send both fields together; innerHTML-only is rejected.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Update one block' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        flat_index: {
          type: 'number',
          description: 'Zero-based flat `index` from get_page_blocks (counts every block, including innerBlocks). For top-level-only addressing, use delete_block / insert_blocks / replace_block_range.',
        },
        attributes: { type: 'object', description: 'Partial attrs (top-level shallow merge).' },
        innerHTML: { type: 'string', description: 'Replacement innerHTML.' },
      },
      required: ['post_id', 'flat_index'],
    },
  },
  {
    name: 'insert_blocks',
    description:
      'Insert blocks at a top-level position. `after_top_level` / `before_top_level` use the top_level_counter. Omit or after_top_level:-1 to append; "start" prepends. Legacy-tier blocks rejected per the site policy (see block-mcp://block-preferences). Response.inserted[] carries `path` + `top_level_counter` so you can chain edit_block_tree without re-reading.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Insert blocks' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        after_top_level: {
          type: ['number', 'string'],
          description: 'top_level_counter to insert AFTER. -1/omit = append, "start" = prepend.',
        },
        before_top_level: {
          type: 'number',
          description: 'top_level_counter to insert BEFORE.',
        },
        blocks: {
          type: 'array',
          description: 'Blocks to insert.',
          items: BLOCK_INPUT_SCHEMA,
        },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'delete_block',
    description: 'Remove block(s) at a top_level_counter. For core/block, removes the reference only — not the source pattern.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true, title: 'Delete blocks' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        top_level_counter: {
          type: 'number',
          description: 'Zero-based top_level_counter (sequential position among top-level blocks). NOT the flat index — that one is for update_block.',
        },
        count: { type: 'number', description: 'Consecutive top-level blocks to remove. Default 1.' },
      },
      required: ['post_id', 'top_level_counter'],
    },
  },
  {
    name: 'replace_block_range',
    description:
      'Atomic single-revision swap of N top-level blocks for M new blocks (M can be 0, 1, or N). Safer than delete+insert (no half-written intermediate state). Distinct from rewrite_post_blocks (which replaces the entire post).',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Replace a range of blocks' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        start: { type: 'number', description: 'top_level_counter of first block to replace.' },
        count: { type: 'number', description: 'How many top-level blocks to remove. Pass 0 to insert without removing.' },
        blocks: { type: 'array', description: 'Replacement blocks. May be empty (pure delete) or any length.', items: BLOCK_INPUT_SCHEMA },
      },
      required: ['post_id', 'start', 'count', 'blocks'],
    },
  },
  {
    name: 'rewrite_post_blocks',
    description: 'Replace ALL blocks on a page in one revision. Use for major restructuring; prefer update_block / insert_blocks / replace_block_range for edits.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true, title: 'Rewrite the entire post' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        blocks: { type: 'array', description: 'Complete blocks array (replaces all).', items: BLOCK_INPUT_SCHEMA },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'revert_to_revision',
    description: 'Restore a post to a revision. Pass `before_revision_id` from a prior write to UNDO that write; pass `revision_id` to REDO it.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true, title: 'Revert post to revision' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        revision_id: { type: 'number', description: 'Revision ID to restore.' },
      },
      required: ['post_id', 'revision_id'],
    },
  },
];

/**
 * Handle a write tool call.
 */
export async function handleWriteTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'update_block': {
      const postId = args.post_id as number;
      const flatIndex = args.flat_index as number;
      const attributes = args.attributes as Record<string, unknown> | undefined;
      const innerHTML = args.innerHTML as string | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (flatIndex === undefined || flatIndex === null) throw new Error('flat_index is required');
      if (!attributes && !innerHTML) {
        throw new Error('At least one of attributes or innerHTML must be provided');
      }
      return await client.updateBlock(postId, flatIndex, { attributes, innerHTML });
    }

    case 'insert_blocks': {
      const postId = args.post_id as number;
      const after = args.after_top_level as number | 'start' | undefined;
      const before = args.before_top_level as number | undefined;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) throw new Error('At least one block is required in the blocks array');

      const result = await client.insertBlocks(postId, { after, before, blocks });
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'delete_block': {
      const postId = args.post_id as number;
      const topLevelCounter = args.top_level_counter as number;
      const count = args.count as number | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (topLevelCounter === undefined || topLevelCounter === null) throw new Error('top_level_counter is required');

      return await client.deleteBlock(postId, topLevelCounter, count);
    }

    case 'replace_block_range': {
      const postId = args.post_id as number;
      const start = args.start as number;
      const count = args.count as number;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (typeof start !== 'number' || start < 0) throw new Error('start must be a non-negative integer');
      if (typeof count !== 'number' || count < 0) throw new Error('count must be a non-negative integer');
      if (!Array.isArray(blocks)) throw new Error('blocks must be an array (may be empty for a pure delete)');

      const result = await client.replaceBlocksRange(postId, { start, count, blocks });
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'rewrite_post_blocks': {
      const postId = args.post_id as number;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) throw new Error('At least one block is required for a full page rewrite');

      const result = await client.replaceAllBlocks(postId, blocks);
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'revert_to_revision': {
      const postId = args.post_id as number;
      const revisionId = args.revision_id as number;
      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (revisionId === undefined || revisionId === null) throw new Error('revision_id is required');
      return await client.revertToRevision(postId, revisionId);
    }

    default:
      throw new Error(`Unknown write tool: ${toolName}`);
  }
}
