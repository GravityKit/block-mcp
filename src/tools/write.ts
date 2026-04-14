/**
 * Write Tools
 *
 * MCP tools for modifying block content on WordPress pages.
 * Includes single-block updates, block insertion, deletion,
 * and full page rewrites. All operations create WordPress revisions.
 */

import type { WordPressBlockClient } from '../client.js';
import { formatPreferenceWarning } from '../preferences.js';

/**
 * Tool definitions for the write category.
 */
export const WRITE_TOOLS = [
  {
    name: 'update_block',
    description:
      "Update a single block's attributes and/or HTML content. " +
      'Use get_page_blocks first to find the block index. ' +
      'Attributes are merged (partial update); innerHTML is replaced entirely. ' +
      'Creates a WordPress revision for every change.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID containing the block.',
        },
        block_index: {
          type: 'number',
          description:
            'Zero-based index of the block to update ' +
            '(from get_page_blocks response).',
        },
        attributes: {
          type: 'object',
          description:
            'Partial attribute update. Keys are merged with existing attributes. ' +
            'Example: { "content": "New text", "level": 2 }',
        },
        innerHTML: {
          type: 'string',
          description:
            "Replacement HTML for the block's inner content. " +
            'Replaces the entire innerHTML (not merged).',
        },
      },
      required: ['post_id', 'block_index'],
    },
  },
  {
    name: 'insert_blocks',
    description:
      'Insert one or more blocks at a specific position on a page. ' +
      'Specify either "after" (insert after index) or "before" (insert before index). ' +
      'Use after: -1 or omit to append at the end. ' +
      'Warns if any inserted blocks are from legacy namespaces. ' +
      'Always check list_block_types and list_patterns before inserting.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID.',
        },
        after: {
          type: ['number', 'string'],
          description:
            'Insert after this block index (0-based). ' +
            'Use -1 or omit to append at end. Use "start" to prepend.',
        },
        before: {
          type: 'number',
          description:
            'Insert before this block index (alternative to "after").',
        },
        blocks: {
          type: 'array',
          description: 'Array of blocks to insert.',
          items: {
            type: 'object',
            properties: {
              name: {
                type: 'string',
                description:
                  'Fully-qualified block name (e.g. "core/heading", "filter/testimonial-wall").',
              },
              attributes: {
                type: 'object',
                description: 'Block attributes (e.g. { "level": 2, "content": "Title" }).',
              },
              innerHTML: {
                type: 'string',
                description: 'Optional raw HTML content for the block.',
              },
            },
            required: ['name'],
          },
        },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'delete_block',
    description:
      'Remove a block (or consecutive blocks) from a page. ' +
      'Use get_page_blocks first to find the correct index. ' +
      'If the block is a synced pattern reference (core/block), this removes ' +
      'the reference — it does NOT delete the pattern itself.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID.',
        },
        block_index: {
          type: 'number',
          description: 'Zero-based index of the block to remove.',
        },
        count: {
          type: 'number',
          description:
            'Number of consecutive blocks to remove starting at block_index. ' +
            'Default: 1. Use for removing a group of related blocks.',
        },
      },
      required: ['post_id', 'block_index'],
    },
  },
  {
    name: 'replace_all_blocks',
    description:
      'Full page rewrite — replaces ALL blocks on a page. ' +
      'Creates a revision before overwriting so changes can be reverted. ' +
      'Validates all block names against the registry. ' +
      'Use this for major page restructuring; prefer update_block/insert_blocks ' +
      'for surgical edits.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID.',
        },
        blocks: {
          type: 'array',
          description: 'Complete array of blocks for the page (replaces everything).',
          items: {
            type: 'object',
            properties: {
              name: {
                type: 'string',
                description: 'Fully-qualified block name.',
              },
              attributes: {
                type: 'object',
                description: 'Block attributes.',
              },
              innerHTML: {
                type: 'string',
                description: 'Raw HTML content for the block.',
              },
            },
            required: ['name'],
          },
        },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'revert_to_revision',
    description:
      'Revert a post to a previous revision. Use the revision_id from a prior ' +
      "write operation's before_revision_id or revision_id field to undo changes.",
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID.',
        },
        revision_id: {
          type: 'number',
          description: 'The revision ID to restore (from a previous write response).',
        },
      },
      required: ['post_id', 'revision_id'],
    },
  },
];

/**
 * Handle a write tool call.
 *
 * @param toolName - The name of the tool being called
 * @param args - Tool arguments from the AI agent
 * @param client - WordPress Block API client instance
 * @returns Tool result ready for MCP response
 */
export async function handleWriteTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'update_block': {
      const postId = args.post_id as number;
      const blockIndex = args.block_index as number;
      const attributes = args.attributes as Record<string, unknown> | undefined;
      const innerHTML = args.innerHTML as string | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (blockIndex === undefined || blockIndex === null) {
        throw new Error('block_index is required');
      }
      if (!attributes && !innerHTML) {
        throw new Error('At least one of attributes or innerHTML must be provided');
      }

      const result = await client.updateBlock(postId, blockIndex, {
        attributes,
        innerHTML,
      });

      return result;
    }

    case 'insert_blocks': {
      const postId = args.post_id as number;
      const after = args.after as number | 'start' | undefined;
      const before = args.before as number | undefined;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) {
        throw new Error('At least one block is required in the blocks array');
      }

      const result = await client.insertBlocks(postId, {
        after,
        before,
        blocks,
      });

      // Enrich warnings with human-readable messages
      if (result.warnings && result.warnings.length > 0) {
        const formattedWarnings = result.warnings.map(formatPreferenceWarning);
        return {
          ...result,
          formatted_warnings: formattedWarnings,
        };
      }

      return result;
    }

    case 'delete_block': {
      const postId = args.post_id as number;
      const blockIndex = args.block_index as number;
      const count = args.count as number | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (blockIndex === undefined || blockIndex === null) {
        throw new Error('block_index is required');
      }

      const result = await client.deleteBlock(postId, blockIndex, count);
      return result;
    }

    case 'replace_all_blocks': {
      const postId = args.post_id as number;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) {
        throw new Error('At least one block is required for a full page rewrite');
      }

      const result = await client.replaceAllBlocks(postId, blocks);

      // Enrich warnings with human-readable messages
      if (result.warnings && result.warnings.length > 0) {
        const formattedWarnings = result.warnings.map(formatPreferenceWarning);
        return {
          ...result,
          formatted_warnings: formattedWarnings,
        };
      }

      return result;
    }

    case 'revert_to_revision': {
      const postId = args.post_id as number;
      const revisionId = args.revision_id as number;
      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (revisionId === undefined || revisionId === null) throw new Error('revision_id is required');
      const result = await client.revertToRevision(postId, revisionId);
      return result;
    }

    default:
      throw new Error(`Unknown write tool: ${toolName}`);
  }
}
