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
      "Get a post's blocks. Returns a summary (block counts, headings, sections) and the nested blocks with path, name, attributes, innerHTML, and text_preview (stripped text, ~100 chars). Use path for mutate_block_tree. Legacy blocks annotated with replacements. Start with outline=true or summary_only=true for fast inspection before drilling in.",
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        fields: {
          type: 'string',
          description: 'Comma-separated fields (e.g. "path,name,text_preview"). Omit for all.',
        },
        render: {
          type: 'boolean',
          description: 'Expand shortcodes, resolve synced patterns, mark dynamic/static.',
        },
        search: {
          type: 'string',
          description: 'Filter by text in innerHTML. Returns flat matches.',
        },
        block_name: {
          type: 'string',
          description: 'Filter by block name (e.g. "core/button"). Returns flat matches.',
        },
        outline: {
          type: 'boolean',
          description: 'Return only headings and named sections as a flat outline. Fast page structure view.',
        },
        summary_only: {
          type: 'boolean',
          description: 'Return only the summary object (no blocks). Fastest page inspection.',
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
      const outline = args.outline as boolean | undefined;
      const summaryOnly = args.summary_only as boolean | undefined;
      if (postId === undefined || postId === null) {
        throw new Error('post_id is required');
      }

      const response = await client.getPageBlocks(postId, {
        fields, render, search, block_name: blockName, outline, summary_only: summaryOnly,
      });

      // summary_only mode: return server summary as-is.
      if (summaryOnly) {
        return {
          post_id: postId,
          summary: (response as { summary?: unknown }).summary,
        };
      }

      const enriched = enrichBlockList(response.blocks || []);

      return {
        post_id: postId,
        summary: (response as { summary?: unknown }).summary,
        blocks: enriched.blocks,
        block_count: enriched.blocks.length,
        warnings: enriched.warnings,
      };
    }

    default:
      throw new Error(`Unknown read tool: ${toolName}`);
  }
}
