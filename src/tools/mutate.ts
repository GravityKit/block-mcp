/**
 * Mutate Tools
 *
 * MCP tool for performing structural operations on nested block trees
 * using path-based addressing. Supports update-attrs, update-html,
 * replace-block, remove-block, wrap-in-group, unwrap-group,
 * insert-child, duplicate, and move operations.
 * All operations create WordPress revisions.
 */

import type { WordPressBlockClient } from '../client.js';
import type { MutationOp, MutationRequest, MutationResponse, StaticBlockWarning } from '../types.js';
import { formatPreferenceWarning } from '../preferences.js';

/** All valid mutation operation names. */
const VALID_OPS: Set<MutationOp> = new Set([
  'update-attrs',
  'update-html',
  'replace-block',
  'remove-block',
  'wrap-in-group',
  'unwrap-group',
  'insert-child',
  'duplicate',
  'move',
]);

/**
 * Tool definitions for the mutate category.
 */
export const MUTATE_TOOLS = [{
  name: 'mutate_block_tree',
  description:
    'Perform a single structural operation on a nested block tree using path-based addressing. ' +
    'Supports: update-attrs, update-html, replace-block, remove-block, wrap-in-group, ' +
    'unwrap-group, insert-child, duplicate, move. ' +
    'Use get_page_blocks first to find block paths. Each call creates a WordPress revision. ' +
    'Paths are integer arrays like [0, 2, 1] meaning "block 0 → innerBlock 2 → innerBlock 1". ' +
    'For move: use "before" to specify where blocks should go (pre-move path), and "count" to move multiple consecutive blocks as a section.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      post_id: {
        type: 'number',
        description: 'WordPress post or page ID.',
      },
      op: {
        type: 'string',
        enum: ['update-attrs', 'update-html', 'replace-block', 'remove-block',
               'wrap-in-group', 'unwrap-group', 'insert-child', 'duplicate', 'move'],
        description: 'The mutation operation to perform.',
      },
      path: {
        type: 'array',
        items: { type: 'integer' },
        description: 'Path to the target block as array of indices, e.g. [0, 2, 1]. Get paths from get_page_blocks response.',
      },
      attributes: {
        type: 'object',
        description: 'For update-attrs: attributes to merge into the block.',
      },
      innerHTML: {
        type: 'string',
        description: 'For update-html: replacement HTML for the block inner content.',
      },
      block: {
        type: 'object',
        description: 'For replace-block/insert-child: the new block definition { name, attributes?, innerHTML?, innerBlocks? }.',
        properties: {
          name: { type: 'string', description: 'Fully-qualified block name (e.g. "core/heading").' },
          attributes: { type: 'object' },
          innerHTML: { type: 'string' },
          innerBlocks: { type: 'array' },
        },
      },
      wrapper: {
        type: 'object',
        description: 'For wrap-in-group: optional wrapper block. Defaults to core/group.',
        properties: {
          name: { type: 'string', description: 'Wrapper block name. Default: "core/group".' },
          attributes: { type: 'object' },
        },
      },
      position: {
        type: ['integer', 'string'],
        description: 'For insert-child: position in innerBlocks. Use integer index, "start", or "end" (default).',
      },
      destination: {
        type: 'array',
        items: { type: 'integer' },
        description: 'For move: destination path where the block should be placed.',
      },
      before: {
        type: 'array',
        items: { type: 'integer' },
        description: 'For move: path of the block to insert BEFORE (uses pre-move indexing). Alias for destination.',
      },
      count: {
        type: 'integer',
        description: 'For move: number of consecutive blocks to move. Default: 1. Use for moving sections.',
      },
    },
    required: ['post_id', 'op', 'path'],
  },
}];

/**
 * Validate that a value is an array of integers.
 *
 * @param value - The value to validate
 * @param fieldName - Field name for error messages
 * @returns True if valid
 */
function isIntegerArray(value: unknown, fieldName: string): value is number[] {
  if (!Array.isArray(value)) {
    throw new Error(`${fieldName} must be an array of integers`);
  }
  for (const item of value) {
    if (typeof item !== 'number' || !Number.isInteger(item)) {
      throw new Error(`${fieldName} must contain only integers, got: ${JSON.stringify(item)}`);
    }
  }
  return true;
}

/**
 * Check whether a warning is a static block staleness warning.
 *
 * @param warning - Warning object from the API response
 * @returns True if it is a StaticBlockWarning
 */
function isStaticBlockWarning(warning: unknown): warning is StaticBlockWarning {
  return (
    typeof warning === 'object' &&
    warning !== null &&
    (warning as Record<string, unknown>).type === 'static_markup_stale_risk'
  );
}

/**
 * Format a static block staleness warning into a human-readable string.
 *
 * @param warning - The static block warning
 * @returns Formatted warning string
 */
function formatStaticBlockWarning(warning: StaticBlockWarning): string {
  const attrs = warning.changed_attrs.join(', ');
  return `WARNING: Changing ${attrs} on static block ${warning.block_name} without updating innerHTML may leave markup stale.`;
}

/**
 * Handle a mutate tool call.
 *
 * @param toolName - The name of the tool being called
 * @param args - Tool arguments from the AI agent
 * @param client - WordPress Block API client instance
 * @returns Tool result ready for MCP response
 */
export async function handleMutateTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  if (toolName !== 'mutate_block_tree') {
    throw new Error(`Unknown mutate tool: ${toolName}`);
  }

  const postId = args.post_id as number;
  const op = args.op as string;
  const path = args.path;

  // --- Common validation ---

  if (postId === undefined || postId === null) {
    throw new Error('post_id is required');
  }

  if (!op || !VALID_OPS.has(op as MutationOp)) {
    throw new Error(
      `op must be one of: ${[...VALID_OPS].join(', ')}. Got: ${JSON.stringify(op)}`
    );
  }

  isIntegerArray(path, 'path');

  // --- Per-operation validation ---

  const requestBody: MutationRequest = {
    op: op as MutationOp,
    path: path as number[],
  };

  switch (op) {
    case 'update-attrs': {
      const attributes = args.attributes as Record<string, unknown> | undefined;
      if (!attributes || typeof attributes !== 'object') {
        throw new Error('update-attrs requires an "attributes" object');
      }
      requestBody.attributes = attributes;
      break;
    }

    case 'update-html': {
      const innerHTML = args.innerHTML as string | undefined;
      if (innerHTML === undefined || innerHTML === null) {
        throw new Error('update-html requires an "innerHTML" string');
      }
      requestBody.innerHTML = innerHTML;
      break;
    }

    case 'replace-block': {
      const block = args.block as { name?: string } | undefined;
      if (!block || typeof block !== 'object' || !block.name) {
        throw new Error('replace-block requires a "block" object with a "name" property');
      }
      requestBody.block = block as MutationRequest['block'];
      break;
    }

    case 'remove-block': {
      // No extra fields required
      break;
    }

    case 'wrap-in-group': {
      // Optional wrapper
      if (args.wrapper !== undefined) {
        requestBody.wrapper = args.wrapper as MutationRequest['wrapper'];
      }
      break;
    }

    case 'unwrap-group': {
      // No extra fields required
      break;
    }

    case 'insert-child': {
      const block = args.block as { name?: string } | undefined;
      if (!block || typeof block !== 'object' || !block.name) {
        throw new Error('insert-child requires a "block" object with a "name" property');
      }
      requestBody.block = block as MutationRequest['block'];

      if (args.position !== undefined) {
        const position = args.position;
        if (typeof position === 'number' && Number.isInteger(position)) {
          requestBody.position = position;
        } else if (position === 'start' || position === 'end') {
          requestBody.position = position;
        } else {
          throw new Error('position must be an integer, "start", or "end"');
        }
      }
      break;
    }

    case 'duplicate': {
      // No extra fields required
      break;
    }

    case 'move': {
      const before = args.before;
      const destination = args.destination;

      // Accept either before or destination (before takes precedence)
      if (before !== undefined && before !== null) {
        isIntegerArray(before, 'before');
        requestBody.before = before as number[];
      } else if (destination !== undefined && destination !== null) {
        isIntegerArray(destination, 'destination');
        requestBody.destination = destination as number[];
      } else {
        throw new Error('move requires either a "before" or "destination" array of integers');
      }

      // Validate count if provided
      if (args.count !== undefined && args.count !== null) {
        const count = args.count as number;
        if (typeof count !== 'number' || !Number.isInteger(count) || count < 1) {
          throw new Error('count must be a positive integer');
        }
        requestBody.count = count;
      }
      break;
    }
  }

  // --- Execute mutation ---

  const result: MutationResponse = await client.mutateBlockTree(postId, requestBody);

  // --- Format warnings ---

  if (result.warnings && result.warnings.length > 0) {
    const formattedWarnings = result.warnings.map((warning) => {
      if (isStaticBlockWarning(warning)) {
        return formatStaticBlockWarning(warning);
      }
      return formatPreferenceWarning(warning);
    });

    return {
      ...result,
      formatted_warnings: formattedWarnings,
    };
  }

  return result;
}
