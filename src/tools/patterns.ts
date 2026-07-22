/**
 * Pattern Tools
 *
 * MCP tools for inserting WordPress block patterns into pages.
 * Supports both synced patterns (core/block references that stay linked)
 * and inline insertion (independent copy for per-page customization).
 */

import type { WordPressBlockClient } from '../client.js';
import type { CreatePatternRequest } from '../types.js';
import { coercePostId } from '../coerce.js';
import { BLOCK_INPUT_SCHEMA } from './write.js';

/**
 * Tool definitions for the pattern category.
 */
export const PATTERN_TOOLS = [
  {
    name: 'create_pattern',
    description:
      'Extract repeated sections into a reusable pattern, then reference it. Creates a synced pattern (a wp_block post) from either structured `blocks` (validated the same way as create_post — legacy blocks rejected) or raw `content` — exactly one of the two. `sync_status:"synced"` (default) means edits to the pattern update every page that references it; `"unsynced"` creates an independent one-off starting point instead. Response includes a ready-to-insert `reference` snippet (`{blockName:"core/block", attrs:{ref}}`) for insert_blocks.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Create pattern' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        title: { type: 'string', description: 'Pattern title (required, non-empty).' },
        blocks: {
          type: 'array',
          description: 'Structured blocks. Mutually exclusive with content — provide exactly one. Validated against block registry and preference tier — legacy blocks are rejected.',
          items: BLOCK_INPUT_SCHEMA,
        },
        content: {
          type: 'string',
          description: 'Raw post_content (HTML or block markup). Mutually exclusive with blocks — provide exactly one.',
        },
        sync_status: {
          type: 'string',
          enum: ['synced', 'unsynced'],
          description: '"synced" (default): edits propagate to every page referencing this pattern. "unsynced": an independent copy, no propagation.',
        },
        slug: { type: 'string' },
        status: {
          type: 'string',
          enum: ['publish', 'draft'],
          description: 'Default publish.',
        },
      },
      required: ['title'],
    },
  },
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
        after_top_level: {
          type: 'number',
          description: 'top_level_counter to insert AFTER. -1/omit = append. Matches insert_blocks naming.',
        },
        before_top_level: {
          type: 'number',
          description: 'top_level_counter to insert BEFORE. Matches insert_blocks naming.',
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
    case 'create_pattern': {
      if (typeof args.title !== 'string' || args.title.trim() === '') {
        throw new Error('create_pattern: a non-empty "title" is required');
      }
      const hasBlocks = Array.isArray(args.blocks) && args.blocks.length > 0;
      const hasContent = typeof args.content === 'string' && args.content !== '';
      if (hasBlocks === hasContent) {
        throw new Error('create_pattern: provide exactly one of "content" or "blocks"');
      }

      return await client.createPattern({
        title: args.title,
        blocks: hasBlocks ? (args.blocks as CreatePatternRequest['blocks']) : undefined,
        content: hasContent ? (args.content as string) : undefined,
        sync_status: (args.sync_status as 'synced' | 'unsynced' | undefined) ?? 'synced',
        slug: args.slug as string | undefined,
        status: (args.status as 'publish' | 'draft' | undefined) ?? 'publish',
      });
    }

    case 'insert_pattern': {
      const postId = coercePostId(args.post_id, 'insert_pattern');
      const patternId = args.pattern_id as number | string;
      const after = args.after_top_level as number | undefined;
      const before = args.before_top_level as number | undefined;
      const synced = args.synced as boolean | undefined;

      if (postId === undefined) throw new Error('post_id is required');
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
