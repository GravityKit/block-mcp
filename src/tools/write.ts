/**
 * Write Tools — single-block updates, insertion, deletion, range/full
 * replacement, revert. All ops create a WordPress revision.
 *
 * Naming conventions (1.4.0+):
 *   - `flat_index`         = sequential position across ALL blocks (incl. nested).
 *   - `top_level_counter`  = sequential position among top-level blocks only.
 *   - tool names use verb_noun; range tools spell out the scope.
 */

import type { WordPressBlockClient } from '../client.js';
import { formatPreferenceWarning } from '../preferences.js';
import { enrichBlock, enrichBlocks, type BlockDef } from '../enrichers.js';

/** Shape shared by every block-input arg in this module. */
const BLOCK_INPUT_SCHEMA = {
  type: 'object',
  properties: {
    name: { type: 'string', description: 'Fully-qualified block name (e.g. "core/heading").' },
    attributes: { type: 'object', description: 'Block attributes.' },
    innerHTML: { type: 'string', description: 'Wrapper HTML for container blocks (e.g. "<ul class=\"wp-block-list\"></ul>"); leaf-block HTML otherwise.' },
    innerBlocks: { type: 'array', description: 'Child blocks. Nest recursively to build lists, columns, groups, etc.', items: { type: 'object' } },
  },
  required: ['name'],
} as const;

/**
 * Output schema for write ops that return inserted-block refs (insert + replace).
 *
 * Per-ref shape mirrors the disambiguated 1.4.0 surface: `top_level_counter`
 * for ordinal addressing, `path` for `edit_block_tree` chaining. The legacy
 * `index` field was dropped from the schema in 1.4.0 so typed clients see
 * exactly the two canonical addressing modes.
 */
const INSERTED_REFS_SCHEMA = {
  type: 'object',
  properties: {
    success:            { type: 'boolean' },
    inserted: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          top_level_counter: { type: 'number' },
          path:              { type: 'array', items: { type: 'integer' } },
          // ref is present on every insert path the PHP plugin returns —
          // bake it into the shared schema instead of overriding the parent
          // shape per-tool.
          ref:               { type: 'string' },
          name:              { type: 'string' },
        },
      },
    },
    warnings:           { type: 'array' },
    before_revision_id: { type: 'number' },
    revision_id:        { type: 'number' },
  },
} as const;

/** Output schema for write ops that report a single revision result. */
const REVISION_ONLY_SCHEMA = {
  type: 'object',
  properties: {
    success:            { type: 'boolean' },
    before_revision_id: { type: 'number' },
    revision_id:        { type: 'number' },
  },
} as const;

export const WRITE_TOOLS = [
  {
    name: 'update_block',
    description:
      'Update one block by flat_index OR by ref (stable gk_ref from get_page_blocks). Provide exactly one targeting field. Refs are recommended for chained mutations because they survive sibling shifts. attributes are SHALLOW-merged at top level — pass full arrays, not deltas. innerHTML replaces atomically. For dual-storage blocks (e.g. yoast/faq-block) you MUST send both fields together; innerHTML-only is rejected. Response includes `saved.inner_html` and `saved.attributes` — the canonical post-save snapshot from the database. Do not fetch the public page to verify edits.',
    // idempotentHint is false: every call creates a new revision, and
    // revision history is observable to other readers. Same-input/same-state
    // is true at the block level but not at the post level.
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Update one block' },
    outputSchema: {
      type: 'object',
      properties: {
        success: { type: 'boolean' },
        block: { type: 'object', properties: { index: { type: 'number' }, name: { type: 'string' }, attributes: { type: 'object' }, ref: { type: 'string' } } },
        saved: {
          type: 'object',
          description: 'Canonical post-save snapshot of the updated block — exactly what is now in post_content. For dynamic blocks, inner_html is the stored template, not the rendered output.',
          properties: {
            flat_index: { type: 'number' },
            block_name: { type: 'string' },
            attributes: { type: 'object' },
            inner_html: { type: 'string' },
            is_dynamic: { type: 'boolean' },
            ref: { type: 'string' },
          },
        },
        before_revision_id: { type: 'number' },
        revision_id: { type: 'number' },
      },
    },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        flat_index: {
          type: 'number',
          description: 'Zero-based flat `index` from get_page_blocks (counts every block, including innerBlocks). Provide this OR `ref`.',
        },
        ref: {
          type: 'string',
          description: 'Stable gk_ref (e.g. "blk_a3f2c1q9") from get_page_blocks. Survives sibling shifts so chained mutations don\'t go stale. Provide this OR `flat_index`.',
        },
        block_name: {
          type: 'string',
          description: 'Block type being updated (e.g. "kevinbatdorf/code-block-pro"). Required to activate enrichers — e.g. auto-generating codeHTML from code + language.',
        },
        attributes: { type: 'object', description: 'Partial attrs (top-level shallow merge). Enrichers derive computed fields (e.g. codeHTML) automatically when block_name is provided.' },
        innerHTML: { type: 'string', description: 'Replacement innerHTML.' },
      },
      required: ['post_id'],
    },
  },
  {
    name: 'update_blocks',
    description:
      'Update N independent blocks atomically in ONE revision. Each item targets one block by `ref` (recommended) or `flat_index`, with `attributes` and/or `innerHTML`. Validation is all-or-nothing: any stale ref / out-of-range index / dual-storage rejection / duplicate target aborts the batch with itemized errors — no partial writes hit disk. Max 50 items per call. Counts as ONE write against the per-post rate limit. Use this instead of looping update_block when fixing multiple blocks on the same post — keeps revision history clean. Pass `verbose: true` to include `saved.inner_html` + `saved.attributes` per result for per-item verification without a re-read.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Batch-update blocks' },
    outputSchema: {
      type: 'object',
      properties: {
        success: { type: 'boolean' },
        count:   { type: 'number' },
        results: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              batch_index: { type: 'number' },
              block: {
                type: 'object',
                properties: {
                  index:      { type: 'number' },
                  name:       { type: 'string' },
                  attributes: { type: 'object' },
                  ref:        { type: 'string' },
                },
              },
              saved: {
                type: 'object',
                description: 'Canonical post-save snapshot. Present only when called with `verbose: true`.',
                properties: {
                  flat_index: { type: 'number' },
                  block_name: { type: 'string' },
                  attributes: { type: 'object' },
                  inner_html: { type: 'string' },
                  is_dynamic: { type: 'boolean' },
                  ref:        { type: 'string' },
                },
              },
            },
          },
        },
        before_revision_id: { type: 'number' },
        revision_id:        { type: 'number' },
      },
    },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        updates: {
          type: 'array',
          description: 'List of update items (1..50). Each item targets one block; same item shape as update_block.',
          items: {
            type: 'object',
            properties: {
              ref:        { type: 'string', description: 'Stable gk_ref. Provide this OR flat_index.' },
              flat_index: { type: 'number', description: 'Flat index from get_page_blocks. Provide this OR ref.' },
              block_name: { type: 'string', description: 'Block type (e.g. "core/paragraph"). Required for enrichers when attributes are provided.' },
              attributes: { type: 'object', description: 'Partial attrs (top-level shallow merge).' },
              innerHTML:  { type: 'string', description: 'Replacement innerHTML.' },
            },
          },
        },
        verbose: {
          type: 'boolean',
          description: 'When true, each result includes `saved.inner_html` + `saved.attributes` (the canonical post-save snapshot). Default false to keep batch responses compact.',
        },
      },
      required: ['post_id', 'updates'],
    },
  },
  {
    name: 'insert_blocks',
    description:
      'Insert blocks at a top-level position. Anchoring options (use one): `after_ref`/`before_ref` (stable gk_ref — recommended), or `after_top_level`/`before_top_level` (top_level_counter). Omit anchors or set after_top_level:-1 to append; "start" prepends. Legacy-tier blocks rejected per the site policy. Response.inserted[] carries `ref`, `path`, and `top_level_counter` so you can chain edit_block_tree without re-reading.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Insert blocks' },
    outputSchema: INSERTED_REFS_SCHEMA,
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        after_top_level: {
          type: ['number', 'string'],
          description: 'top_level_counter to insert AFTER. -1/omit = append, "start" = prepend.',
        },
        before_top_level: {
          type: 'number',
          description: 'top_level_counter to insert BEFORE.',
        },
        after_ref: {
          type: 'string',
          description: 'gk_ref of the top-level block to insert AFTER. Recommended — survives sibling shifts. Takes precedence over after_top_level.',
        },
        before_ref: {
          type: 'string',
          description: 'gk_ref of the top-level block to insert BEFORE. Takes precedence over before_top_level.',
        },
        blocks: {
          type: 'array',
          description: 'Blocks to insert.',
          items: BLOCK_INPUT_SCHEMA,
        },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'delete_block',
    description: 'Remove block(s) by top_level_counter OR by ref. Provide exactly one. For core/block, removes the reference only — not the source pattern.',
    // idempotentHint is false: deleting at counter N twice removes a
    // *different* block the second time (indices shift after the first).
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Delete blocks' },
    outputSchema: { type: 'object', properties: { ...REVISION_ONLY_SCHEMA.properties, removed: { type: 'number' } } },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        top_level_counter: {
          type: 'number',
          description: 'Zero-based top_level_counter (sequential position among top-level blocks). Provide this OR `ref`.',
        },
        ref: {
          type: 'string',
          description: 'gk_ref of the block to remove (or the leading block if count > 1). Survives sibling shifts. Provide this OR `top_level_counter`.',
        },
        count: { type: 'number', description: 'Consecutive top-level blocks to remove. Default 1.' },
      },
      required: ['post_id'],
    },
  },
  {
    name: 'replace_block_range',
    description:
      'Atomic single-revision swap of N top-level blocks for M new blocks (M can be 0, 1, or N). Safer than delete+insert (no half-written intermediate state). Distinct from rewrite_post_blocks (which replaces the entire post).',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Replace a range of blocks' },
    outputSchema: { ...INSERTED_REFS_SCHEMA, properties: { ...INSERTED_REFS_SCHEMA.properties, removed: { type: 'number' } } },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        start: { type: 'number', description: 'top_level_counter of first block to replace.' },
        count: { type: 'number', description: 'How many top-level blocks to remove. Pass 0 to insert without removing.' },
        blocks: { type: 'array', description: 'Replacement blocks. May be empty (pure delete) or any length.', items: BLOCK_INPUT_SCHEMA },
      },
      required: ['post_id', 'start', 'count', 'blocks'],
    },
  },
  {
    name: 'rewrite_post_blocks',
    description: 'Replace ALL blocks on a page in one revision. Use for major restructuring; prefer update_block / insert_blocks / replace_block_range for edits.',
    // idempotentHint is false for the same reason as update_block: every
    // call creates a revision; history is observable.
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Rewrite the entire post' },
    outputSchema: INSERTED_REFS_SCHEMA,
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        blocks: { type: 'array', description: 'Complete blocks array (replaces all).', items: BLOCK_INPUT_SCHEMA },
      },
      required: ['post_id', 'blocks'],
    },
  },
  {
    name: 'revert_to_revision',
    description: 'Restore a post to a revision. Pass `before_revision_id` from a prior write to UNDO that write; pass `revision_id` to REDO it.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true, title: 'Revert post to revision' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'Post ID.' },
        revision_id: { type: 'number', description: 'Revision ID to restore.' },
      },
      required: ['post_id', 'revision_id'],
    },
  },
];

/**
 * Handle a write tool call.
 */
export async function handleWriteTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'update_block': {
      const postId = args.post_id as number;
      const flatIndex = args.flat_index as number | undefined;
      const ref = args.ref as string | undefined;
      const blockName = args.block_name as string | undefined;
      let attributes = args.attributes as Record<string, unknown> | undefined;
      let innerHTML = args.innerHTML as string | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      const hasIndex = typeof flatIndex === 'number' && Number.isFinite(flatIndex) && flatIndex >= 0;
      const hasRef = typeof ref === 'string' && ref.length > 0;
      if (!hasIndex && !hasRef) {
        throw new Error('Provide either flat_index (non-negative integer) or ref');
      }
      if (hasIndex && hasRef) {
        throw new Error('Provide flat_index OR ref, not both');
      }
      if (!attributes && !innerHTML) {
        throw new Error('At least one of attributes or innerHTML must be provided');
      }

      // When block_name is provided, run enrichers so computed fields (e.g.
      // codeHTML for CBP) are derived automatically from the supplied attrs.
      if (blockName && attributes) {
        const blockDef: BlockDef = { name: blockName, attributes, ...(innerHTML ? { innerHTML } : {}) };
        const enriched = await enrichBlock(blockDef);
        attributes = enriched.attributes;
        if (enriched.innerHTML !== undefined) innerHTML = enriched.innerHTML;
      }

      if (hasRef) {
        return await client.updateBlockByRef(postId, ref as string, { attributes, innerHTML });
      }
      return await client.updateBlock(postId, flatIndex as number, { attributes, innerHTML });
    }

    case 'update_blocks': {
      const postId = args.post_id as number;
      const updates = args.updates as Array<{
        ref?: string;
        flat_index?: number;
        block_name?: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }> | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!Array.isArray(updates) || updates.length === 0) {
        throw new Error('updates must be a non-empty array');
      }

      // Pre-validate every item client-side so an obviously-broken batch
      // surfaces a precise per-item error before paying the network round-trip.
      // Server still re-validates (canonical authority).
      const normalized: Array<{
        ref?: string;
        flat_index?: number;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }> = [];
      for (let i = 0; i < updates.length; i++) {
        const item = updates[i];
        if (!item || typeof item !== 'object') {
          throw new Error(`updates[${i}]: each item must be an object`);
        }
        const hasItemRef = typeof item.ref === 'string' && item.ref.length > 0;
        const hasItemIndex = typeof item.flat_index === 'number'
          && Number.isFinite(item.flat_index)
          && item.flat_index >= 0;
        if (hasItemRef === hasItemIndex) {
          throw new Error(`updates[${i}]: provide exactly one of ref or flat_index`);
        }
        const hasAttrs = item.attributes && Object.keys(item.attributes).length > 0;
        const hasHTML = typeof item.innerHTML === 'string';
        if (!hasAttrs && !hasHTML) {
          throw new Error(`updates[${i}]: at least one of attributes or innerHTML is required`);
        }

        // Run enrichers when block_name + attributes are both supplied so
        // computed fields (e.g. CBP codeHTML) get derived automatically —
        // mirrors update_block's behavior on a per-item basis.
        let attributes = item.attributes;
        let innerHTML = item.innerHTML;
        if (item.block_name && attributes) {
          const enriched = await enrichBlock({
            name: item.block_name,
            attributes,
            ...(innerHTML ? { innerHTML } : {}),
          });
          attributes = enriched.attributes;
          if (enriched.innerHTML !== undefined) innerHTML = enriched.innerHTML;
        }

        normalized.push({
          ...(hasItemRef ? { ref: item.ref } : {}),
          ...(hasItemIndex ? { flat_index: item.flat_index } : {}),
          ...(attributes ? { attributes } : {}),
          ...(innerHTML !== undefined ? { innerHTML } : {}),
        });
      }

      if (args.verbose === true) {
        return await client.updateBlocksBatch(postId, normalized, { verbose: true });
      }
      return await client.updateBlocksBatch(postId, normalized);
    }

    case 'insert_blocks': {
      const postId = args.post_id as number;
      const after = args.after_top_level as number | 'start' | undefined;
      const before = args.before_top_level as number | undefined;
      const afterRef = args.after_ref as string | undefined;
      const beforeRef = args.before_ref as string | undefined;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) throw new Error('At least one block is required in the blocks array');

      const result = await client.insertBlocks(postId, {
        after,
        before,
        ...(afterRef ? { after_ref: afterRef } : {}),
        ...(beforeRef ? { before_ref: beforeRef } : {}),
        blocks: await enrichBlocks(blocks as BlockDef[]),
      });
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'delete_block': {
      const postId = args.post_id as number;
      const topLevelCounter = args.top_level_counter as number | undefined;
      const ref = args.ref as string | undefined;
      const count = args.count as number | undefined;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      const hasCounter = typeof topLevelCounter === 'number' && Number.isFinite(topLevelCounter) && topLevelCounter >= 0;
      const hasRef = typeof ref === 'string' && ref.length > 0;
      if (!hasCounter && !hasRef) {
        throw new Error('Provide either top_level_counter (non-negative integer) or ref');
      }
      if (hasCounter && hasRef) {
        throw new Error('Provide top_level_counter OR ref, not both');
      }
      if (count !== undefined && count !== null) {
        if (typeof count !== 'number' || !Number.isInteger(count) || count < 1) {
          throw new Error('count must be a positive integer');
        }
      }

      if (hasRef) {
        return await client.deleteBlockByRef(postId, ref as string, count);
      }
      return await client.deleteBlock(postId, topLevelCounter as number, count);
    }

    case 'replace_block_range': {
      const postId = args.post_id as number;
      const start = args.start as number;
      const count = args.count as number;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (typeof start !== 'number' || start < 0) throw new Error('start must be a non-negative integer');
      if (typeof count !== 'number' || count < 0) throw new Error('count must be a non-negative integer');
      if (!Array.isArray(blocks)) throw new Error('blocks must be an array (may be empty for a pure delete)');

      const result = await client.replaceBlocksRange(postId, { start, count, blocks: await enrichBlocks(blocks as BlockDef[]) });
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'rewrite_post_blocks': {
      const postId = args.post_id as number;
      const blocks = args.blocks as Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;

      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (!blocks || blocks.length === 0) throw new Error('At least one block is required for a full page rewrite');

      const result = await client.replaceAllBlocks(postId, await enrichBlocks(blocks as BlockDef[]));
      if (result.warnings && result.warnings.length > 0) {
        return { ...result, formatted_warnings: result.warnings.map(formatPreferenceWarning) };
      }
      return result;
    }

    case 'revert_to_revision': {
      const postId = args.post_id as number;
      const revisionId = args.revision_id as number;
      if (postId === undefined || postId === null) throw new Error('post_id is required');
      if (revisionId === undefined || revisionId === null) throw new Error('revision_id is required');
      return await client.revertToRevision(postId, revisionId);
    }

    default:
      throw new Error(`Unknown write tool: ${toolName}`);
  }
}
