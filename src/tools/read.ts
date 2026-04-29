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
      "Get a post's blocks. Pass post_id OR url (server resolves URL — don't shell out). Returns `{post_id, summary, blocks[], block_count, warnings}`. Each block: `{index (flat), top_level_counter? (top-level only), path, name, attributes, innerHTML?, dynamic, storage_mode (\"static\"|\"dynamic\"|\"dual\"), preference? (when non-preferred)}`. Use outline:true or summary_only:true for cheap inspection.",
    annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Get post blocks' },
    outputSchema: {
      type: 'object',
      properties: {
        post_id:     { type: 'number' },
        summary:     { type: 'object' },
        blocks:      { type: 'array' },
        block_count: { type: 'number' },
        warnings:    { type: 'array' },
      },
    },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID. Provide either this or url.',
        },
        url: {
          type: 'string',
          description: 'Full URL (https://site.com/path/) or site-relative path (/path/). Resolved via url_to_postid. Provide either this or post_id.',
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
        include_legacy_paths: {
          type: 'boolean',
          description: 'Add summary.legacy_blocks.paths (per-block path list). Off by default; turn on for migration audits.',
        },
      },
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
      let postId = args.post_id as number | undefined;
      const url = args.url as string | undefined;
      const fields = args.fields as string | undefined;
      const render = args.render as boolean | undefined;
      const search = args.search as string | undefined;
      const blockName = args.block_name as string | undefined;
      const outline = args.outline as boolean | undefined;
      const summaryOnly = args.summary_only as boolean | undefined;
      const includeLegacyPaths = args.include_legacy_paths as boolean | undefined;

      if ((postId === undefined || postId === null) && !url) {
        throw new Error('Either post_id or url is required');
      }

      if (postId === undefined || postId === null) {
        const resolved = await client.resolveUrl(url as string);
        postId = resolved.post_id;
      }

      const response = await client.getPageBlocks(postId, {
        fields, render, search, block_name: blockName, outline,
        summary_only: summaryOnly,
        include_legacy_paths: includeLegacyPaths,
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
