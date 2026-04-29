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
      'Insert a pattern. Default synced=true inserts a core/block reference (edits to source update all pages); synced=false inlines blocks for per-page edits. NOTE: registered (non-numeric) patterns cannot be synced — server forces synced=false. Response includes `synced` (actual mode used) so you can detect the override.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Insert pattern' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: {
          type: 'number',
          description: 'Post ID.',
        },
        pattern_id: {
          type: ['number', 'string'],
          description: 'Numeric post ID (synced) or registered pattern name.',
        },
        after: {
          type: 'number',
          description: 'Insert after index. -1/omit = append.',
        },
        before: {
          type: 'number',
          description: 'Insert before index (alternative to after).',
        },
        synced: {
          type: 'boolean',
          description: 'true (default) = synced reference; false = inline copy.',
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
