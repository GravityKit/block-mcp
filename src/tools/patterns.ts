/**
 * Pattern Tools
 *
 * MCP tools for inserting WordPress block patterns into pages.
 * Supports both synced patterns (core/block references that stay linked)
 * and inline insertion (independent copy for per-page customization).
 */

import type { WordPressBlockClient } from '../client.js';

/**
 * Tool definitions for the pattern category.
 */
export const PATTERN_TOOLS = [
  {
    name: 'insert_pattern',
    description:
      'Insert a WordPress block pattern into a page. ' +
      'By default, inserts as a synced reference (core/block) so it stays linked ' +
      'to the source pattern — changes to the pattern update all pages. ' +
      'Set synced: false to inline the pattern blocks for per-page customization. ' +
      'Use list_patterns and get_pattern first to find the right pattern.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'WordPress post or page ID to insert the pattern into.',
        },
        pattern_id: {
          type: ['number', 'string'],
          description:
            'Pattern to insert — numeric post ID for synced patterns, ' +
            'or registered pattern name.',
        },
        after: {
          type: 'number',
          description:
            'Insert after this block index (0-based). ' +
            'Use -1 or omit to append at the end of the page.',
        },
        before: {
          type: 'number',
          description:
            'Insert before this block index (alternative to "after").',
        },
        synced: {
          type: 'boolean',
          description:
            'If true (default), insert as a synced core/block reference. ' +
            'If false, inline the pattern blocks as an independent copy ' +
            'that can be edited per-page without affecting other pages.',
        },
      },
      required: ['post_id', 'pattern_id'],
    },
  },
];

/**
 * Handle a pattern tool call.
 *
 * @param toolName - The name of the tool being called
 * @param args - Tool arguments from the AI agent
 * @param client - WordPress Block API client instance
 * @returns Tool result ready for MCP response
 */
export async function handlePatternTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'insert_pattern': {
      const postId = args.post_id as number;
      const patternId = args.pattern_id as number | string;
      const after = args.after as number | undefined;
      const before = args.before as number | undefined;
      const synced = args.synced as boolean | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (patternId === undefined || patternId === null) throw new Error('pattern_id is required');

      const result = await client.insertPattern(postId, {
        pattern_id: patternId,
        after,
        before,
        synced: synced ?? true,
      });

      // Add a hint about sync behavior
      const syncNote = result.synced
        ? 'Pattern inserted as synced reference. Changes to the source pattern will update this page.'
        : 'Pattern blocks inserted inline. This copy is independent and can be edited per-page.';

      return {
        ...result,
        note: syncNote,
      };
    }

    default:
      throw new Error(`Unknown pattern tool: ${toolName}`);
  }
}
