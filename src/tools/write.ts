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
      "Update a block's attributes and/or innerHTML. Attributes merge; innerHTML replaces. Creates a revision.",
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        block_index: {
          type: 'number',
          description: 'Zero-based flat index from get_page_blocks.',
        },
        attributes: {
          type: 'object',
          description: 'Partial attributes to merge.',
        },
        innerHTML: {
          type: 'string',
          description: 'Replacement innerHTML (not merged).',
        },
      },
      required: ['post_id', 'block_index'],
    },
  },
  {
    name: 'insert_blocks',
    description:
      'Insert blocks at a position. Use after/before with the top-level ordinal; omit or after:-1 to append. Legacy namespaces are rejected.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        after: {
          type: ['number', 'string'],
          description:
            'Insert after the block at this top-level ordinal (sequential position among top-level blocks only — the `top_level_ordinal` field on get_page_blocks entries; NOT the flat `index`). -1/omit = append, "start" = prepend.',
        },
        before: {
          type: 'number',
          description:
            'Insert before the block at this top-level ordinal (sequential position among top-level blocks only — the `top_level_ordinal` field on get_page_blocks entries; NOT the flat `index`).',
        },
        blocks: {
          type: 'array',
          description: 'Blocks to insert.',
          items: {
            type: 'object',
            properties: {
              name: {
                type: 'string',
                description: 'Fully-qualified block name (e.g. "core/heading").',
              },
              attributes: {
                type: 'object',
                description: 'Block attributes.',
              },
              innerHTML: {
                type: 'string',
                description: 'Raw HTML content.',
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
      'Remove block(s) at a top-level ordinal. For core/block, removes the reference only, not the source pattern.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        block_index: {
          type: 'number',
          description:
            'Zero-based **top-level ordinal** (sequential position among top-level blocks only — the `top_level_ordinal` field on get_page_blocks entries). NOT the flat `index` field — that one is consumed by `update_block`. Passing a flat index here will land on the wrong block or return "Block index out of range".',
        },
        count: {
          type: 'number',
          description: 'Consecutive top-level blocks to remove. Default 1.',
        },
      },
      required: ['post_id', 'block_index'],
    },
  },
  {
    name: 'replace_all_blocks',
    description:
      'Replace ALL blocks on a page. Creates a revision. Use for major restructuring; prefer update_block/insert_blocks for edits.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        blocks: {
          type: 'array',
          description: 'Complete blocks array (replaces all).',
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
                description: 'Raw HTML content.',
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
      'Revert a post to a revision ID returned by a prior write response.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        revision_id: {
          type: 'number',
          description: 'Revision ID to restore.',
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
