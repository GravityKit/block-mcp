/**
 * In-memory fixture store.
 *
 * Loads a saved `get_page_blocks` JSON payload and exposes mutation methods
 * that mirror the real MCP write ops (insert_blocks, delete_block,
 * update_block) — but in memory, with no network I/O. Each mutation returns
 * the same shape the real MCP would return so the AI can't tell the
 * difference.
 *
 * NOT a complete MCP implementation. Just enough surface for the eval
 * scenarios to exercise.
 */

import { readFileSync } from 'node:fs';

export interface FixtureBlock {
  index: number;
  top_level_counter?: number;
  path?: number[];
  name: string;
  attributes: Record<string, unknown>;
  innerHTML?: string;
  innerBlocks?: FixtureBlock[];
  dynamic?: boolean;
  storage_mode?: 'static' | 'dynamic' | 'dual';
  text_preview?: string;
  section?: string;
  [k: string]: unknown;
}

export interface FixturePayload {
  source_url: string;
  post_id: number;
  post_type: string;
  title: string;
  slug: string;
  fetched_at: string;
  response: {
    summary: Record<string, unknown>;
    blocks: FixtureBlock[];
    block_count: number;
    warnings: unknown[];
  };
}

export class FixtureStore {
  private payload: FixturePayload;
  /** Counts mutation calls across the run, used by the runner to grade scenarios. */
  public callCounts = {
    get_page_blocks: 0,
    update_block: 0,
    insert_blocks: 0,
    delete_block: 0,
    replace_block_range: 0,
  };

  constructor(path: string) {
    this.payload = JSON.parse(readFileSync(path, 'utf8')) as FixturePayload;
    // Defensive: ensure top_level_counter exists even on fixtures captured before BLOCK-5 shipped.
    let counter = 0;
    for (const block of this.payload.response.blocks) {
      if (block.top_level_counter === undefined) block.top_level_counter = counter;
      counter++;
    }
  }

  postId(): number {
    return this.payload.post_id;
  }

  /**
   * MCP `get_page_blocks` shape. Honors a small subset of params:
   *   summary_only, search, block_name.
   */
  getPageBlocks(args: {
    summary_only?: boolean;
    search?: string;
    block_name?: string;
  } = {}): unknown {
    this.callCounts.get_page_blocks++;
    if (args.summary_only) {
      return { post_id: this.payload.post_id, summary: this.payload.response.summary };
    }
    let blocks = this.payload.response.blocks;
    if (args.search) {
      const needle = args.search.toLowerCase();
      blocks = blocks.filter((b) =>
        (b.innerHTML ?? '').toLowerCase().includes(needle) ||
        (b.text_preview ?? '').toLowerCase().includes(needle),
      );
    }
    if (args.block_name) {
      blocks = blocks.filter((b) => b.name === args.block_name);
    }
    return {
      post_id: this.payload.post_id,
      summary: this.payload.response.summary,
      blocks,
      block_count: blocks.length,
      warnings: this.payload.response.warnings,
    };
  }

  insertBlocks(args: {
    after_top_level?: number | 'start';
    before_top_level?: number;
    blocks: Array<{ name: string; attributes?: Record<string, unknown>; innerHTML?: string }>;
  }): unknown {
    this.callCounts.insert_blocks++;
    const all = this.payload.response.blocks;
    let visibleInsert = all.length;
    if (args.after_top_level === 'start') visibleInsert = 0;
    else if (typeof args.after_top_level === 'number') visibleInsert = args.after_top_level === -1 ? all.length : args.after_top_level + 1;
    else if (typeof args.before_top_level === 'number') visibleInsert = args.before_top_level;

    const inserted = args.blocks.map((b, i) => ({
      index: visibleInsert + i,
      top_level_counter: visibleInsert + i,
      path: [visibleInsert + i],
      name: b.name,
    }));

    const newBlocks: FixtureBlock[] = args.blocks.map((b, i) => ({
      index: visibleInsert + i,
      top_level_counter: visibleInsert + i,
      path: [visibleInsert + i],
      name: b.name,
      attributes: b.attributes ?? {},
      innerHTML: b.innerHTML,
    }));

    all.splice(visibleInsert, 0, ...newBlocks);
    // Re-number indexes + top_level_counters after the splice.
    all.forEach((blk, idx) => {
      blk.index = idx;
      blk.top_level_counter = idx;
      blk.path = [idx];
    });

    return {
      success: true,
      inserted,
      warnings: [],
      before_revision_id: 0,
      revision_id: 1,
    };
  }

  deleteBlock(args: { top_level_counter: number; count?: number }): unknown {
    this.callCounts.delete_block++;
    const count = args.count ?? 1;
    const all = this.payload.response.blocks;
    if (args.top_level_counter < 0 || args.top_level_counter >= all.length) {
      throw new Error('Block index out of range');
    }
    all.splice(args.top_level_counter, count);
    all.forEach((blk, idx) => {
      blk.index = idx;
      blk.top_level_counter = idx;
      blk.path = [idx];
    });
    return { success: true, removed: count, before_revision_id: 0, revision_id: 1 };
  }

  updateBlock(args: {
    flat_index: number;
    attributes?: Record<string, unknown>;
    innerHTML?: string;
  }): unknown {
    this.callCounts.update_block++;
    const all = this.payload.response.blocks;
    const target = all[args.flat_index];
    if (!target) throw new Error('Block index out of range');
    if (args.attributes) target.attributes = { ...target.attributes, ...args.attributes };
    if (args.innerHTML !== undefined) target.innerHTML = args.innerHTML;
    return {
      success: true,
      block: { index: target.index, name: target.name, attributes: target.attributes },
      before_revision_id: 0,
      revision_id: 1,
    };
  }

  replaceBlocks(args: {
    start: number;
    count: number;
    blocks: Array<{ name: string; attributes?: Record<string, unknown>; innerHTML?: string }>;
  }): unknown {
    this.callCounts.replace_block_range++;
    const all = this.payload.response.blocks;
    if (args.start < 0 || args.start > all.length) throw new Error('range.start out of bounds');
    const count = Math.max(0, Math.min(args.count, all.length - args.start));

    const newBlocks: FixtureBlock[] = args.blocks.map((b, i) => ({
      index: args.start + i,
      top_level_counter: args.start + i,
      path: [args.start + i],
      name: b.name,
      attributes: b.attributes ?? {},
      innerHTML: b.innerHTML,
    }));

    all.splice(args.start, count, ...newBlocks);
    all.forEach((blk, idx) => {
      blk.index = idx;
      blk.top_level_counter = idx;
      blk.path = [idx];
    });

    return {
      success: true,
      removed: count,
      inserted: newBlocks.map((b) => ({
        index: b.index,
        top_level_counter: b.top_level_counter,
        path: b.path,
        name: b.name,
      })),
      warnings: [],
      before_revision_id: 0,
      revision_id: 1,
    };
  }

  /** Snapshot of the current block list (for scenario assertions). */
  blocksSnapshot(): FixtureBlock[] {
    return this.payload.response.blocks.map((b) => ({ ...b }));
  }
}
