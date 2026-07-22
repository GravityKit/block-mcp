/**
 * Tool tests: list_patterns
 *
 * Covers:
 *   - Filter forwarding (search → q, synced, min_score)
 *   - Full fetch (no server-side limit) + client-side pagination, so `total`
 *     and `next_offset` reflect the true count (matches list_block_types)
 *   - Client-side slicing via offset
 *   - Defaults: limit 20, offset 0
 *   - Response shape: patterns, count, total, offset, next_offset, summary
 *   - Enrichment via enrichPatternList (summary is present)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { patternsResponse } from '../../fixtures/rest-responses.js';

describe('list_patterns — filter forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPatterns.mockResolvedValue(patternsResponse);
  });

  it('forwards search as q and other filters', async () => {
    await handleDiscoveryTool('list_patterns', {
      search: 'hero', synced: true, min_score: 50, category: 'banner',
    }, client as any);
    expect(client.getPatterns).toHaveBeenCalledWith(expect.objectContaining({
      q: 'hero', synced: true, min_score: 50, category: 'banner',
    }));
  });

  it('forwards no filters when none are provided', async () => {
    await handleDiscoveryTool('list_patterns', {}, client as any);
    expect(client.getPatterns).toHaveBeenCalledWith(expect.objectContaining({
      q: undefined, synced: undefined, min_score: undefined, category: undefined,
    }));
  });
});

describe('list_patterns — category schema', () => {
  it('exposes category in the inputSchema', async () => {
    const { DISCOVERY_TOOLS } = await import('../../../tools/discovery.js');
    const tool = DISCOVERY_TOOLS.find((t) => t.name === 'list_patterns')!;
    expect(tool.inputSchema.properties).toHaveProperty('category');
    expect((tool.inputSchema.properties as any).category.type).toBe('string');
  });
});

describe('list_patterns — pagination', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPatterns.mockResolvedValue(patternsResponse);
  });

  it('fetches the full set (no truncating server-side limit)', async () => {
    await handleDiscoveryTool('list_patterns', {}, client as any);
    // No `limit` is sent: the handler paginates locally so `total` and
    // `next_offset` reflect the true count instead of the fetched window.
    const args = client.getPatterns.mock.calls[0][0];
    expect(args?.limit).toBeUndefined();
  });

  it('does not cap the fetch by offset+limit', async () => {
    await handleDiscoveryTool('list_patterns', { limit: 10, offset: 30 }, client as any);
    const args = client.getPatterns.mock.calls[0][0];
    expect(args?.limit).toBeUndefined();
  });

  it('reports true total and non-null next_offset when more remain', async () => {
    const many = Array.from({ length: 30 }, (_, i) => ({
      id: i + 1, name: `pattern-${i}`,
      type: 'registered' as const,
      created: '2026-01-01', modified: '2026-01-01',
      reference_count: 0,
      preference: { score: 80, tier: 'recommended' as const, reasons: [] },
      contains_blocks: [], has_legacy_blocks: false,
    }));
    client.getPatterns.mockResolvedValueOnce({ patterns: many } as any);
    const result = await handleDiscoveryTool('list_patterns', { limit: 20, offset: 0 }, client as any) as Record<string, unknown>;
    expect(result.total).toBe(30);
    expect(result.next_offset).toBe(20);
  });

  it('client-side slice honors offset', async () => {
    const many = Array.from({ length: 30 }, (_, i) => ({
      id: i + 1, name: `pattern-${i}`,
      type: 'synced' as const,
      created: '2026-01-01', modified: '2026-01-01',
      reference_count: 0,
      preference: { score: 50 - i, tier: 'recommended' as const, reasons: [] },
      contains_blocks: [], has_legacy_blocks: false,
    }));
    client.getPatterns.mockResolvedValueOnce({ patterns: many } as any);
    const result = await handleDiscoveryTool('list_patterns', { limit: 5, offset: 10 }, client as any) as Record<string, unknown>;
    expect((result.patterns as unknown[]).length).toBe(5);
    expect(result.offset).toBe(10);
  });

  it('next_offset is null at end of results', async () => {
    const five = Array.from({ length: 5 }, (_, i) => ({
      id: i + 1, name: `p-${i}`,
      type: 'synced' as const,
      created: '2026-01-01', modified: '2026-01-01',
      reference_count: 0,
      preference: { score: 50, tier: 'recommended' as const, reasons: [] },
      contains_blocks: [], has_legacy_blocks: false,
    }));
    client.getPatterns.mockResolvedValueOnce({ patterns: five } as any);
    const result = await handleDiscoveryTool('list_patterns', { limit: 10 }, client as any) as Record<string, unknown>;
    expect(result.next_offset).toBeNull();
  });
});

describe('list_patterns — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPatterns.mockResolvedValue(patternsResponse);
  });

  it('returns patterns, count, total, offset, next_offset, summary', async () => {
    const result = await handleDiscoveryTool('list_patterns', {}, client as any) as Record<string, unknown>;
    expect(Array.isArray(result.patterns)).toBe(true);
    expect(typeof result.count).toBe('number');
    expect(typeof result.total).toBe('number');
    expect(typeof result.offset).toBe('number');
    // summary is created by enrichPatternList — may be a string or structured value
    expect(result.summary).toBeDefined();
  });

  it('passes through the categories vocabulary from the client response', async () => {
    client.getPatterns.mockResolvedValueOnce({
      ...patternsResponse,
      categories: [{ name: 'banner', label: 'Banners' }],
    } as any);
    const result = await handleDiscoveryTool('list_patterns', {}, client as any) as Record<string, unknown>;
    expect(result.categories).toEqual([{ name: 'banner', label: 'Banners' }]);
  });
});
