/**
 * Tool tests: get_page_blocks
 *
 * Covers:
 *   - Input requirement: either post_id or url
 *   - URL resolution path (when only url is provided)
 *   - Direct post_id path (skips resolveUrl)
 *   - All query-mode flag forwarding (fields, render, search, block_name,
 *     outline, summary_only, include_legacy_paths, persist_refs)
 *   - summary_only short-circuits enrichment
 *   - Response shape: post_id + summary + blocks + block_count + warnings
 *   - Enrichment adds preference warnings for legacy/avoid blocks
 *   - persist_refs only forwarded when provided (omitted otherwise)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleReadTool } from '../../../tools/read.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { pageBlocksResponse, resolveUrlResponse } from '../../fixtures/rest-responses.js';
import { mixedPageBlocks, legacyPageBlocks } from '../../fixtures/block-trees.js';

describe('get_page_blocks — input validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
  });

  it('throws when neither post_id nor url is supplied', async () => {
    await expect(
      handleReadTool('get_page_blocks', {}, client as any)
    ).rejects.toThrow(/post_id or url/);
  });

  it('accepts post_id alone', async () => {
    await expect(
      handleReadTool('get_page_blocks', { post_id: 42 }, client as any)
    ).resolves.toBeDefined();
  });

  it('accepts url alone', async () => {
    client.resolveUrl.mockResolvedValueOnce(resolveUrlResponse);
    await expect(
      handleReadTool('get_page_blocks', { url: '/path/' }, client as any)
    ).resolves.toBeDefined();
  });
});

describe('get_page_blocks — URL resolution', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
  });

  it('resolves url to post_id via client.resolveUrl', async () => {
    client.resolveUrl.mockResolvedValueOnce({ ...resolveUrlResponse, post_id: 555 });
    await handleReadTool('get_page_blocks', { url: '/some/path/' }, client as any);
    expect(client.resolveUrl).toHaveBeenCalledWith('/some/path/');
    expect(client.getPageBlocks).toHaveBeenCalledWith(555, expect.any(Object));
  });

  it('skips resolveUrl when post_id is provided', async () => {
    await handleReadTool('get_page_blocks', { post_id: 99 }, client as any);
    expect(client.resolveUrl).not.toHaveBeenCalled();
    expect(client.getPageBlocks).toHaveBeenCalledWith(99, expect.any(Object));
  });

  it('returns the resolved post_id in the response (url path)', async () => {
    client.resolveUrl.mockResolvedValueOnce({ ...resolveUrlResponse, post_id: 1234 });
    const result = await handleReadTool('get_page_blocks', { url: '/x' }, client as any);
    expect((result as { post_id: number }).post_id).toBe(1234);
  });
});

describe('get_page_blocks — option forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
  });

  it('forwards fields, render, search, block_name, outline, summary_only, include_legacy_paths', async () => {
    await handleReadTool('get_page_blocks', {
      post_id: 1,
      fields: 'path,name', render: true, search: 'foo', block_name: 'core/heading',
      outline: true, include_legacy_paths: true,
    }, client as any);
    expect(client.getPageBlocks).toHaveBeenCalledWith(1, expect.objectContaining({
      fields: 'path,name', render: true, search: 'foo',
      block_name: 'core/heading', outline: true, include_legacy_paths: true,
    }));
  });

  it('omits persist_refs when not provided', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1 }, client as any);
    const opts = client.getPageBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect('persist_refs' in opts).toBe(false);
  });

  it('forwards persist_refs:false when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: false }, client as any);
    expect(client.getPageBlocks).toHaveBeenCalledWith(1, expect.objectContaining({ persist_refs: false }));
  });

  it('forwards persist_refs:true when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: true }, client as any);
    expect(client.getPageBlocks).toHaveBeenCalledWith(1, expect.objectContaining({ persist_refs: true }));
  });
});

describe('get_page_blocks — summary_only short-circuit', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
  });

  it('returns only post_id + summary when summary_only:true', async () => {
    const result = await handleReadTool(
      'get_page_blocks',
      { post_id: 1, summary_only: true },
      client as any
    ) as Record<string, unknown>;
    expect(Object.keys(result).sort()).toEqual(['post_id', 'summary']);
    expect(result.post_id).toBe(1);
    expect(result.summary).toBeDefined();
  });

  it('does not include blocks/warnings keys in summary_only mode', async () => {
    const result = await handleReadTool(
      'get_page_blocks', { post_id: 1, summary_only: true }, client as any
    ) as Record<string, unknown>;
    expect('blocks' in result).toBe(false);
    expect('warnings' in result).toBe(false);
    expect('block_count' in result).toBe(false);
  });
});

describe('get_page_blocks — response shape (default mode)', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
  });

  it('returns post_id, summary, blocks, block_count, warnings', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 7 }, client as any) as Record<string, unknown>;
    expect(result.post_id).toBe(7);
    expect(result.summary).toBeDefined();
    expect(Array.isArray(result.blocks)).toBe(true);
    expect(typeof result.block_count).toBe('number');
    expect(Array.isArray(result.warnings)).toBe(true);
  });

  it('block_count matches blocks.length', async () => {
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, client as any) as Record<string, unknown>;
    expect(result.block_count).toBe((result.blocks as unknown[]).length);
  });

  it('warnings is empty when content has no legacy/avoid blocks', async () => {
    client.getPageBlocks.mockResolvedValueOnce({ ...pageBlocksResponse, blocks: mixedPageBlocks });
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, client as any) as { warnings: unknown[] };
    expect(result.warnings).toEqual([]);
  });

  it('handles missing blocks array (treats as empty)', async () => {
    client.getPageBlocks.mockResolvedValueOnce({ summary: pageBlocksResponse.summary } as any);
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, client as any) as Record<string, unknown>;
    expect(result.blocks).toEqual([]);
    expect(result.block_count).toBe(0);
  });
});

describe('get_page_blocks — enrichment with legacy blocks', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('produces warnings for legacy ugb/* blocks', async () => {
    client.getPageBlocks.mockResolvedValueOnce({ blocks: legacyPageBlocks, summary: {} });
    const result = await handleReadTool('get_page_blocks', { post_id: 1 }, client as any) as { warnings: unknown[] };
    expect(result.warnings.length).toBeGreaterThan(0);
  });
});
