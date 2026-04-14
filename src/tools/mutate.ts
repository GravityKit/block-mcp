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
    'Run one structural op on a nested block tree by path. Ops: update-attrs, update-html, replace-block, remove-block, wrap-in-group, unwrap-group, insert-child, duplicate, move. Paths are integer arrays ([0,2,1] = block 0 > innerBlock 2 > innerBlock 1) from get_page_blocks. Creates a revision.',
  inputSchema: {
    type: 'object' as const,
    properties: {
      post_id: {
        type: 'number',
        description: 'Post ID.',
      },
      op: {
        type: 'string',
        enum: ['update-attrs', 'update-html', 'replace-block', 'remove-block',
               'wrap-in-group', 'unwrap-group', 'insert-child', 'duplicate', 'move'],
        description: 'Operation to perform.',
      },
      path: {
        type: 'array',
        items: { type: 'integer' },
        description: 'Target block path, e.g. [0,2,1].',
      },
      attributes: {
        type: 'object',
        description: 'update-attrs: attributes to merge.',
      },
      innerHTML: {
        type: 'string',
        description: 'update-html: replacement innerHTML.',
      },
      block: {
        type: 'object',
        description: 'replace-block/insert-child: { name, attributes?, innerHTML?, innerBlocks? }.',
        properties: {
          name: { type: 'string', description: 'Fully-qualified block name.' },
          attributes: { type: 'object' },
          innerHTML: { type: 'string' },
          innerBlocks: { type: 'array' },
        },
      },
      wrapper: {
        type: 'object',
        description: 'wrap-in-group: optional wrapper block. Default core/group.',
        properties: {
          name: { type: 'string', description: 'Wrapper name. Default "core/group".' },
          attributes: { type: 'object' },
        },
      },
      position: {
        type: ['integer', 'string'],
        description: 'insert-child: index, "start", or "end" (default).',
      },
      destination: {
        type: 'array',
        items: { type: 'integer' },
        description: 'move: destination path.',
      },
      before: {
        type: 'array',
        items: { type: 'integer' },
        description: 'move: path to insert BEFORE (pre-move indexing). Alias for destination.',
      },
      count: {
        type: 'integer',
        description: 'move: consecutive blocks to move. Default 1.',
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
