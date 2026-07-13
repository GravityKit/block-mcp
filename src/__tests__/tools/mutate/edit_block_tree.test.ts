/**
 * Tool tests: edit_block_tree (9 ops)
 *
 * Ops covered: update-attrs, update-html, replace-block, remove-block,
 * wrap-in-group, unwrap-group, insert-child, duplicate, move.
 *
 * Each op has dedicated describe blocks covering:
 *   - Validation (per-op required fields)
 *   - Forwarding to client.mutateBlockTree
 *   - Common: path/ref XOR, op enum, post_id required, integer-path shape
 *   - Warning formatting (static_markup_stale_risk + preference warning)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleMutateTool } from '../../../tools/mutate.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import {
  mutationUpdateAttrsResponse, mutationWithStaticWarning,
} from '../../fixtures/rest-responses.js';
import { assertMutationResponse } from '../../helpers/schema-asserts.js';

// ── Common validation ─────────────────────────────────────────────────────────

describe('edit_block_tree — common validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('rejects unknown top-level tool name', async () => {
    await expect(
      handleMutateTool('not_a_real_tool', { post_id: 1, op: 'update-attrs', path: [0] }, client as any)
    ).rejects.toThrow(/Unknown mutate tool/);
  });

  it('requires post_id', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { op: 'update-attrs', path: [0], attributes: {} }, client as any)
    ).rejects.toThrow(/post_id is required/);
  });

  it('rejects unknown op', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'frobnicate', path: [0] }, client as any)
    ).rejects.toThrow(/op must be one of/);
  });

  it('rejects missing op', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, path: [0] }, client as any)
    ).rejects.toThrow(/op must be one of/);
  });

  it('rejects both path and ref together', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'remove-block', path: [0], ref: 'blk_a',
      }, client as any)
    ).rejects.toThrow(/path.*OR.*ref/i);
  });

  it('rejects when neither path nor ref is given', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'remove-block' }, client as any)
    ).rejects.toThrow(/path.*or.*ref/i);
  });

  it('rejects non-array path', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'remove-block', path: 'not-an-array',
      }, client as any)
    ).rejects.toThrow(/path must be an array of integers/);
  });

  it('rejects path with non-integer elements', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'remove-block', path: [0, 1.5],
      }, client as any)
    ).rejects.toThrow(/path must contain only integers/);
  });

  it('rejects empty path', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'remove-block', path: [],
      }, client as any)
    ).rejects.toThrow(/path must not be empty/);
  });

  it('forwards path verbatim to client.mutateBlockTree', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'remove-block', path: [0, 2, 1],
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'remove-block', path: [0, 2, 1],
    }));
  });

  it('forwards ref verbatim to client.mutateBlockTree', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'remove-block', ref: 'blk_xyz',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'remove-block', ref: 'blk_xyz',
    }));
  });
});

// ── op: update-attrs ──────────────────────────────────────────────────────────

describe('edit_block_tree — update-attrs', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('requires attributes object', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-attrs', path: [0] }, client as any)
    ).rejects.toThrow(/attributes/);
  });

  it('forwards attributes to client', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0], attributes: { level: 3 },
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'update-attrs', attributes: { level: 3 },
    }));
  });
});

// ── op: update-html ───────────────────────────────────────────────────────────

describe('edit_block_tree — update-html', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('requires innerHTML string', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'update-html', path: [0] }, client as any)
    ).rejects.toThrow(/innerHTML/);
  });

  it('forwards innerHTML to client', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-html', path: [0], innerHTML: '<p>New</p>',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'update-html', innerHTML: '<p>New</p>',
    }));
  });

  it('empty string innerHTML is forwarded (allowed)', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-html', path: [0], innerHTML: '',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      innerHTML: '',
    }));
  });
});

// ── op: replace-block ─────────────────────────────────────────────────────────

describe('edit_block_tree — replace-block', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('requires a block object with name', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'replace-block', path: [0] }, client as any)
    ).rejects.toThrow(/"block" object.*"name"/);
  });

  it('rejects block missing name property', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'replace-block', path: [0], block: { attributes: {} },
      }, client as any)
    ).rejects.toThrow(/name/);
  });

  it('forwards the block to client', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'replace-block', path: [0],
      block: { name: 'core/paragraph', attributes: { content: 'Hi' } },
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'replace-block', block: { name: 'core/paragraph', attributes: { content: 'Hi' } },
    }));
  });
});

// ── op: insert-child ──────────────────────────────────────────────────────────

describe('edit_block_tree — insert-child', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('requires a block object with name', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'insert-child', path: [0] }, client as any)
    ).rejects.toThrow(/"block" object.*"name"/);
  });

  it('forwards integer position', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'insert-child', path: [0],
      block: { name: 'core/paragraph' }, position: 2,
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'insert-child', position: 2,
    }));
  });

  it('forwards "start" position', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'insert-child', path: [0],
      block: { name: 'core/paragraph' }, position: 'start',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      position: 'start',
    }));
  });

  it('forwards "end" position', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'insert-child', path: [0],
      block: { name: 'core/paragraph' }, position: 'end',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      position: 'end',
    }));
  });

  it('coerces a numeric-string position to an integer', async () => {
    // The inputSchema advertises position as integer|string, so a numeric
    // string ("3") is schema-conformant and must be accepted as the index.
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'insert-child', path: [0],
      block: { name: 'core/paragraph' }, position: '3',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      position: 3,
    }));
  });

  it('rejects invalid position string', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'insert-child', path: [0],
        block: { name: 'core/paragraph' }, position: 'middle',
      }, client as any)
    ).rejects.toThrow(/position must be/);
  });

  it('rejects non-integer numeric position', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'insert-child', path: [0],
        block: { name: 'core/paragraph' }, position: 1.5,
      }, client as any)
    ).rejects.toThrow(/position must be/);
  });

  it('omits position when not provided', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'insert-child', path: [0], block: { name: 'core/paragraph' },
    }, client as any);
    const arg = client.mutateBlockTree.mock.calls[0]![1] as Record<string, unknown>;
    expect('position' in arg).toBe(false);
  });
});

// ── op: wrap-in-group ─────────────────────────────────────────────────────────

describe('edit_block_tree — wrap-in-group', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('works without a wrapper (defaults applied server-side)', async () => {
    await handleMutateTool('edit_block_tree', { post_id: 1, op: 'wrap-in-group', path: [0] }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalled();
    const arg = client.mutateBlockTree.mock.calls[0]![1] as Record<string, unknown>;
    expect('wrapper' in arg).toBe(false);
  });

  it('forwards custom wrapper when provided', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'wrap-in-group', path: [0],
      wrapper: { name: 'core/cover', attributes: { url: 'x.jpg' } },
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      wrapper: { name: 'core/cover', attributes: { url: 'x.jpg' } },
    }));
  });
});

// ── ops with no payload: remove-block, unwrap-group, duplicate ────────────────

describe('edit_block_tree — payload-less ops', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it.each(['remove-block', 'unwrap-group', 'duplicate'] as const)(
    'forwards %s with no extra payload',
    async (op) => {
      await handleMutateTool('edit_block_tree', { post_id: 1, op, path: [0] }, client as any);
      expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
        op, path: [0],
      }));
    }
  );
});

// ── op: move ──────────────────────────────────────────────────────────────────

describe('edit_block_tree — move', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('requires destination or destination_ref', async () => {
    await expect(
      handleMutateTool('edit_block_tree', { post_id: 1, op: 'move', path: [0] }, client as any)
    ).rejects.toThrow(/destination/);
  });

  it('rejects both destination and destination_ref together', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', path: [0],
        destination: [1], destination_ref: 'blk_b',
      }, client as any)
    ).rejects.toThrow(/destination.*OR.*destination_ref/i);
  });

  it('forwards destination path', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'move', path: [0], destination: [2],
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'move', destination: [2],
    }));
  });

  it('forwards destination_ref', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'move', path: [0], destination_ref: 'blk_target',
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      op: 'move', destination_ref: 'blk_target',
    }));
  });

  it('rejects non-integer destination', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', path: [0], destination: [1.5],
      }, client as any)
    ).rejects.toThrow(/integers/);
  });

  it('forwards integer count', async () => {
    await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'move', path: [0], destination: [2], count: 3,
    }, client as any);
    expect(client.mutateBlockTree).toHaveBeenCalledWith(1, expect.objectContaining({
      count: 3,
    }));
  });

  it('rejects count < 1', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', path: [0], destination: [2], count: 0,
      }, client as any)
    ).rejects.toThrow(/positive integer/);
  });

  it('rejects non-integer count', async () => {
    await expect(
      handleMutateTool('edit_block_tree', {
        post_id: 1, op: 'move', path: [0], destination: [2], count: 1.5,
      }, client as any)
    ).rejects.toThrow(/positive integer/);
  });
});

// ── Response shape ────────────────────────────────────────────────────────────

describe('edit_block_tree — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.mutateBlockTree.mockResolvedValue(mutationUpdateAttrsResponse);
  });

  it('returns a valid MutationResponse', async () => {
    const result = await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0], attributes: { level: 3 },
    }, client as any);
    assertMutationResponse(result);
  });
});

// ── Warning formatting ────────────────────────────────────────────────────────

describe('edit_block_tree — warning formatting', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('formats static_markup_stale_risk warnings', async () => {
    client.mutateBlockTree.mockResolvedValueOnce(mutationWithStaticWarning as any);
    const result = await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0], attributes: { url: 'x' },
    }, client as any) as { formatted_warnings: string[] };
    expect(Array.isArray(result.formatted_warnings)).toBe(true);
    expect(result.formatted_warnings.length).toBeGreaterThan(0);
    expect(result.formatted_warnings[0]).toMatch(/WARNING|static/i);
  });

  it('omits formatted_warnings when response has no warnings', async () => {
    const result = await handleMutateTool('edit_block_tree', {
      post_id: 1, op: 'update-attrs', path: [0], attributes: { level: 3 },
    }, client as any) as Record<string, unknown>;
    expect('formatted_warnings' in result).toBe(false);
  });
});
