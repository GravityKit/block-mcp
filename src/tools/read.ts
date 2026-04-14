/**
 * Read Tools
 *
 * MCP tools for reading block content from WordPress pages.
 * Enriches raw block data with preference annotations so AI agents
 * immediately see which blocks are legacy and what to use instead.
 */

import type { WordPressBlockClient } from '../client.js';
import { enrichBlockList } from '../preferences.js';

/**
 * Tool definitions for the read category.
 */
export const READ_TOOLS = [
  {
    name: 'get_page_blocks',
    description:
      'Get all blocks on a WordPress page as structured JSON. ' +
      'Each block includes its index, name, attributes, and innerHTML. ' +
      'Legacy blocks are annotated with warnings and suggested replacements. ' +
      'Use block indices from this response when calling update_block, delete_block, or insert_blocks. ' +
      'Use fields param (e.g. "path,name,attributes") for lightweight reads that skip innerHTML. ' +
      'Use render to include rendered output for dynamic blocks. ' +
      'Use search to filter blocks by text content, or block_name to filter by block type.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID to read blocks from.',
        },
        fields: {
          type: 'string',
          description: 'Comma-separated list of fields to include (e.g. "path,name,attributes"). ' +
            'Omit for all fields. Use for lightweight reads when you only need block structure.',
        },
        render: {
          type: 'boolean',
          description: 'Include rendered output for dynamic blocks, expand shortcodes, resolve synced pattern content, and mark blocks as dynamic/static.',
        },
        search: {
          type: 'string',
          description: 'Filter blocks by text content (searches innerHTML). Returns flat list of matches with match_count.',
        },
        block_name: {
          type: 'string',
          description: 'Filter blocks by block name (e.g. "core/button"). Returns flat list of matches with match_count.',
        },
      },
      required: ['post_id'],
    },
  },
];

/**
 * Handle a read tool call.
 *
 * @param toolName - The name of the tool being called
 * @param args - Tool arguments from the AI agent
 * @param client - WordPress Block API client instance
 * @returns Tool result ready for MCP response
 */
export async function handleReadTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'get_page_blocks': {
      const postId = args.post_id as number;
      const fields = args.fields as string | undefined;
      const render = args.render as boolean | undefined;
      const search = args.search as string | undefined;
      const blockName = args.block_name as string | undefined;
      if (postId === undefined || postId === null) {
        throw new Error('post_id is required');
      }

      const response = await client.getPageBlocks(postId, { fields, render, search, block_name: blockName });
      const enriched = enrichBlockList(response.blocks);

      return {
        post_id: postId,
        blocks: enriched.blocks,
        block_count: enriched.blocks.length,
        warnings: enriched.warnings,
        summary: enriched.summary,
      };
    }

    default:
      throw new Error(`Unknown read tool: ${toolName}`);
  }
}
