/**
 * Client-level URL routing tests for ref endpoints.
 *
 * These verify WordPressBlockClient builds the correct URLs and encodes
 * refs properly. Uses an axios adapter to capture requests without hitting
 * the network.
 *
 * Covers:
 *   - updateBlockByRef → PATCH /posts/{id}/blocks/by-ref/{ref}
 *   - deleteBlockByRef → DELETE /posts/{id}/blocks/by-ref/{ref}
 *   - deleteBlockByRef count > 1 → query param
 *   - deleteBlockByRef count <= 1 → no count param
 *   - updateBlock (index path) → PATCH /posts/{id}/blocks/{index} (no by-ref)
 *   - URL encoding for refs with reserved characters
 *   - getPageBlocks persist_refs query param (omit / false / true)
 *   - insertBlocks after_ref / before_ref in body
 *   - mutateBlockTree ref in body (path absent)
 *   - mutateBlockTree path in body (ref absent)
 *   - mutateBlockTree before_ref in move body
 *   - mutateBlockTree destination_ref in move body
 *   - Input guards: empty ref, missing post_id, missing data
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import axios from 'axios';
import { WordPressBlockClient } from '../../../client.js';

type CapturedRequest = {
  method: string;
  url: string;
  baseURL: string;
  data?: unknown;
  params?: Record<string, unknown>;
};

let captured: CapturedRequest[] = [];

function safeJsonParse(s: string): unknown {
  try { return JSON.parse(s); } catch { return s; }
}

const realCreate = axios.create.bind(axios);

beforeEach(() => {
  captured = [];
  vi.spyOn(axios, 'create').mockImplementation((cfg: any = {}) => {
    const inst = realCreate(cfg);
    inst.defaults.adapter = ((config: any) => {
      captured.push({
        method: (config.method || 'get').toUpperCase(),
        url: config.url || '',
        baseURL: config.baseURL || '',
        data: typeof config.data === 'string' ? safeJsonParse(config.data) : config.data,
        params: config.params,
      });
      return Promise.resolve({
        data: { success: true, blocks: [], inserted: [], removed: 0, before_revision_id: 1, revision_id: 2 },
        status: 200, statusText: 'OK', headers: {}, config,
      });
    }) as any;
    return inst;
  });
});

function makeClient() {
  return new WordPressBlockClient({
    wordpress_url: 'https://example.test',
    auth: { username: 'u', application_password: 'p p p p' },
  });
}

// ── URL routing ───────────────────────────────────────────────────────────────

describe('Client — ref endpoint URL routing', () => {
  it('updateBlockByRef hits /blocks/by-ref/{ref} with PATCH', async () => {
    const client = makeClient();
    await client.updateBlockByRef(42, 'blk_a3f2c1q9', { attributes: { level: 3 } });
    const req = captured.find((r) => r.url.includes('/blocks/by-ref/'));
    expect(req).toBeDefined();
    expect(req!.method).toBe('PATCH');
    expect(req!.url).toBe('/posts/42/blocks/by-ref/blk_a3f2c1q9');
    expect(req!.data).toEqual({ attributes: { level: 3 } });
  });

  it('deleteBlockByRef hits /blocks/by-ref/{ref} with DELETE', async () => {
    const client = makeClient();
    await client.deleteBlockByRef(42, 'blk_target');
    const req = captured.find((r) => r.url.includes('/blocks/by-ref/'));
    expect(req).toBeDefined();
    expect(req!.method).toBe('DELETE');
    expect(req!.url).toBe('/posts/42/blocks/by-ref/blk_target');
  });

  it('deleteBlockByRef forwards count > 1 as a query param', async () => {
    const client = makeClient();
    await client.deleteBlockByRef(42, 'blk_target', 3);
    const req = captured.find((r) => r.url.includes('/blocks/by-ref/'));
    expect(req!.params).toEqual({ count: '3' });
  });

  it('deleteBlockByRef does not include count when count <= 1', async () => {
    const client = makeClient();
    await client.deleteBlockByRef(42, 'blk_target', 1);
    const req = captured.find((r) => r.url.includes('/blocks/by-ref/'));
    expect(req!.params).toEqual({});
  });

  it('updateBlock (index path) hits /blocks/{index} — no by-ref crossover', async () => {
    const client = makeClient();
    await client.updateBlock(42, 7, { attributes: { foo: 'bar' } });
    const req = captured.find((r) => r.method === 'PATCH');
    expect(req!.url).toBe('/posts/42/blocks/7');
    expect(req!.url).not.toContain('by-ref');
  });

  it('encodeURIComponent escapes refs with reserved characters', async () => {
    const client = makeClient();
    await client.updateBlockByRef(42, 'weird/ref#abc', { innerHTML: '<p>x</p>' });
    const req = captured.find((r) => r.url.includes('/blocks/by-ref/'));
    expect(req!.url).toBe('/posts/42/blocks/by-ref/weird%2Fref%23abc');
  });
});

// ── persist_refs query param ──────────────────────────────────────────────────

describe('Client — getPageBlocks persist_refs', () => {
  it('omits persist_refs when not specified', async () => {
    const client = makeClient();
    await client.getPageBlocks(42);
    const req = captured.find((r) => r.url === '/posts/42/blocks' && r.method === 'GET');
    expect(req!.params).not.toHaveProperty('persist_refs');
  });

  it('sends persist_refs=false explicitly', async () => {
    const client = makeClient();
    await client.getPageBlocks(42, { persist_refs: false });
    const req = captured.find((r) => r.url === '/posts/42/blocks' && r.method === 'GET');
    expect(req!.params?.persist_refs).toBe('false');
  });

  it('sends persist_refs=true explicitly', async () => {
    const client = makeClient();
    await client.getPageBlocks(42, { persist_refs: true });
    const req = captured.find((r) => r.url === '/posts/42/blocks' && r.method === 'GET');
    expect(req!.params?.persist_refs).toBe('true');
  });
});

// ── insertBlocks after_ref / before_ref body params ───────────────────────────

describe('Client — insertBlocks ref body params', () => {
  it('forwards after_ref in JSON body', async () => {
    const client = makeClient();
    await client.insertBlocks(42, {
      after_ref: 'blk_anchor',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/blocks');
    expect((req!.data as Record<string, unknown>).after_ref).toBe('blk_anchor');
  });

  it('forwards before_ref in JSON body', async () => {
    const client = makeClient();
    await client.insertBlocks(42, {
      before_ref: 'blk_anchor2',
      blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
    });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/blocks');
    expect((req!.data as Record<string, unknown>).before_ref).toBe('blk_anchor2');
  });
});

// ── mutateBlockTree ref body ──────────────────────────────────────────────────

describe('Client — mutateBlockTree ref/path body', () => {
  it('sends ref in body when provided (no path)', async () => {
    const client = makeClient();
    await client.mutateBlockTree(42, { op: 'update-attrs', ref: 'blk_target', attributes: { level: 3 } });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/mutate');
    const body = req!.data as Record<string, unknown>;
    expect(body.ref).toBe('blk_target');
    expect(body).not.toHaveProperty('path');
  });

  it('sends path in body when provided (no ref)', async () => {
    const client = makeClient();
    await client.mutateBlockTree(42, { op: 'update-attrs', path: [0, 1], attributes: { level: 3 } });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/mutate');
    const body = req!.data as Record<string, unknown>;
    expect(body.path).toEqual([0, 1]);
    expect(body).not.toHaveProperty('ref');
  });

  it('forwards before_ref in move body', async () => {
    const client = makeClient();
    await client.mutateBlockTree(42, { op: 'move', ref: 'blk_source', before_ref: 'blk_anchor' });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/mutate');
    expect((req!.data as Record<string, unknown>).before_ref).toBe('blk_anchor');
  });

  it('forwards destination_ref in move body', async () => {
    const client = makeClient();
    await client.mutateBlockTree(42, { op: 'move', ref: 'blk_source', destination_ref: 'blk_parent' });
    const req = captured.find((r) => r.method === 'POST' && r.url === '/posts/42/mutate');
    expect((req!.data as Record<string, unknown>).destination_ref).toBe('blk_parent');
  });
});

// ── Input guards ──────────────────────────────────────────────────────────────

describe('Client — input guards', () => {
  it('updateBlockByRef rejects empty ref', async () => {
    const client = makeClient();
    await expect(client.updateBlockByRef(42, '', { attributes: {} })).rejects.toThrow(/Ref is required/);
  });

  it('updateBlockByRef rejects missing post_id', async () => {
    const client = makeClient();
    await expect(client.updateBlockByRef(undefined as any, 'blk_x', { attributes: {} }))
      .rejects.toThrow(/Post ID is required/);
  });

  it('updateBlockByRef requires attributes or innerHTML', async () => {
    const client = makeClient();
    await expect(client.updateBlockByRef(42, 'blk_x', {})).rejects.toThrow(/attributes or innerHTML/);
  });

  it('deleteBlockByRef rejects empty ref', async () => {
    const client = makeClient();
    await expect(client.deleteBlockByRef(42, '')).rejects.toThrow(/Ref is required/);
  });
});
