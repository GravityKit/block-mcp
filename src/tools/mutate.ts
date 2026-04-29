/**
 * Edit Block Tree — path-based structural mutation engine.
 *
 * Single tool exposing 9 ops (update-attrs, update-html, replace-block,
 * remove-block, wrap-in-group, unwrap-group, insert-child, duplicate,
 * move). Path is an integer array from get_page_blocks (e.g. [0,2,1] =
 * top-level block 0 → innerBlock 2 → innerBlock 1). Creates a revision.
 *
 * Renamed from `mutate_block_tree` in 1.4.0 (verb-noun consistency).
 */

import type { WordPressBlockClient } from '../client.js';
import type { MutationOp, MutationRequest, MutationResponse, StaticBlockWarning } from '../types.js';
import { formatPreferenceWarning } from '../preferences.js';

const OPS: readonly MutationOp[] = [
  'update-attrs',
  'update-html',
  'replace-block',
  'remove-block',
  'wrap-in-group',
  'unwrap-group',
  'insert-child',
  'duplicate',
  'move',
] as const;

export const MUTATE_TOOLS = [{
  name: 'edit_block_tree',
  description:
    'Run one structural op on a nested block tree by path. Ops: update-attrs, update-html, replace-block, remove-block, wrap-in-group, unwrap-group, insert-child, duplicate, move. `path` is an integer array from get_page_blocks ([0,2,1] = block 0 → innerBlock 2 → innerBlock 1). Creates a revision.',
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Edit block tree by path' },
  outputSchema: {
    type: 'object',
    properties: {
      success:            { type: 'boolean' },
      op:                 { type: 'string' },
      path:               { type: 'array', items: { type: 'integer' } },
      block:              { type: 'object', properties: { name: { type: 'string' }, attributes: { type: 'object' } } },
      warnings:           { type: 'array' },
      formatted_warnings: { type: 'array', items: { type: 'string' } },
      before_revision_id: { type: 'number' },
      revision_id:        { type: 'number' },
    },
  },
  inputSchema: {
    type: 'object' as const,
    properties: {
      post_id:     { type: 'number',  description: 'Post ID.' },
      op:          { type: 'string',  enum: [...OPS], description: 'Operation to perform.' },
      path:        { type: 'array',   items: { type: 'integer' }, description: 'Target block path (e.g. [0,2,1]).' },
      attributes:  { type: 'object',  description: 'update-attrs: attributes to merge.' },
      innerHTML:   { type: 'string',  description: 'update-html: replacement innerHTML.' },
      block: {
        type: 'object',
        description: 'replace-block / insert-child: { name, attributes?, innerHTML?, innerBlocks? }.',
        properties: {
          name:        { type: 'string', description: 'Fully-qualified block name.' },
          attributes:  { type: 'object' },
          innerHTML:   { type: 'string' },
          innerBlocks: { type: 'array' },
        },
      },
      wrapper: {
        type: 'object',
        description: 'wrap-in-group: optional wrapper block. Default core/group.',
        properties: {
          name:       { type: 'string', description: 'Wrapper name. Default "core/group".' },
          attributes: { type: 'object' },
        },
      },
      position:    { type: ['integer', 'string'], description: 'insert-child: index, "start", or "end" (default).' },
      destination: { type: 'array', items: { type: 'integer' }, description: 'move: destination path.' },
      before:      { type: 'array', items: { type: 'integer' }, description: 'move: insert BEFORE this path (pre-move indexing). Alias for destination.' },
      count:       { type: 'integer', description: 'move: consecutive blocks to move. Default 1.' },
    },
    required: ['post_id', 'op', 'path'],
  },
}];

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

function isStaticBlockWarning(warning: unknown): warning is StaticBlockWarning {
  return (
    typeof warning === 'object' &&
    warning !== null &&
    (warning as Record<string, unknown>).type === 'static_markup_stale_risk'
  );
}

function formatStaticBlockWarning(warning: StaticBlockWarning): string {
  return `WARNING: Changing ${warning.changed_attrs.join(', ')} on static block ${warning.block_name} without updating innerHTML may leave markup stale.`;
}

export async function handleMutateTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  if (toolName !== 'edit_block_tree') {
    throw new Error(`Unknown mutate tool: ${toolName}`);
  }

  const postId = args.post_id as number;
  const op = args.op as string;
  const path = args.path;

  if (postId === undefined || postId === null) throw new Error('post_id is required');
  // Op validation comes from the schema enum at request time; this guard
  // exists for direct programmatic callers that bypass the MCP transport.
  if (!op || !(OPS as readonly string[]).includes(op)) {
    throw new Error(`op must be one of: ${OPS.join(', ')}. Got: ${JSON.stringify(op)}`);
  }
  isIntegerArray(path, 'path');

  const requestBody: MutationRequest = {
    op: op as MutationOp,
    path: path as number[],
  };

  switch (op) {
    case 'update-attrs': {
      const attributes = args.attributes as Record<string, unknown> | undefined;
      if (!attributes || typeof attributes !== 'object') throw new Error('update-attrs requires an "attributes" object');
      requestBody.attributes = attributes;
      break;
    }
    case 'update-html': {
      const innerHTML = args.innerHTML as string | undefined;
      if (innerHTML === undefined || innerHTML === null) throw new Error('update-html requires an "innerHTML" string');
      requestBody.innerHTML = innerHTML;
      break;
    }
    case 'replace-block':
    case 'insert-child': {
      const block = args.block as { name?: string } | undefined;
      if (!block || typeof block !== 'object' || !block.name) {
        throw new Error(`${op} requires a "block" object with a "name" property`);
      }
      requestBody.block = block as MutationRequest['block'];
      if (op === 'insert-child' && args.position !== undefined) {
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
    case 'wrap-in-group': {
      if (args.wrapper !== undefined) requestBody.wrapper = args.wrapper as MutationRequest['wrapper'];
      break;
    }
    case 'remove-block':
    case 'unwrap-group':
    case 'duplicate':
      break;
    case 'move': {
      const before = args.before;
      const destination = args.destination;
      if (before !== undefined && before !== null) {
        isIntegerArray(before, 'before');
        requestBody.before = before as number[];
      } else if (destination !== undefined && destination !== null) {
        isIntegerArray(destination, 'destination');
        requestBody.destination = destination as number[];
      } else {
        throw new Error('move requires either a "before" or "destination" array of integers');
      }
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

  const result: MutationResponse = await client.mutateBlockTree(postId, requestBody);

  if (result.warnings && result.warnings.length > 0) {
    const formattedWarnings = result.warnings.map((warning) => {
      if (isStaticBlockWarning(warning)) return formatStaticBlockWarning(warning);
      return formatPreferenceWarning(warning);
    });
    return { ...result, formatted_warnings: formattedWarnings };
  }
  return result;
}
