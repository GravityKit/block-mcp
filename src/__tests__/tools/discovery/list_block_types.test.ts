/**
 * Tool tests: list_block_types
 *
 * Covers:
 *   - Filter forwarding (namespace, category, tier, storage_mode, search,
 *     preferred_only, usage_only)
 *   - Pagination (limit defaults 50, offset defaults 0, next_offset math)
 *   - Enrichment: response includes `guidance` string
 *   - Response shape: block_types, count, total, offset, next_offset, guidance
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { blockTypesResponse } from '../../fixtures/rest-responses.js';

describe('list_block_types — filter forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlockTypes.mockResolvedValue(blockTypesResponse);
  });

  it('forwards every documented filter', async () => {
    await handleDiscoveryTool('list_block_types', {
      namespace: 'core', category: 'text', tier: 'preferred',
      storage_mode: 'static', search: 'para', preferred_only: true, usage_only: true,
      include_supports: true,
    }, client as any);
    expect(client.getBlockTypes).toHaveBeenCalledWith({
      namespace: 'core', category: 'text', tier: 'preferred',
      storage_mode: 'static', search: 'para', preferred_only: true, usage_only: true,
      include_supports: true,
    });
  });

  it('forwards no filters when none are provided (all undefined)', async () => {
    await handleDiscoveryTool('list_block_types', {}, client as any);
    expect(client.getBlockTypes).toHaveBeenCalledWith({
      namespace: undefined, category: undefined, tier: undefined,
      storage_mode: undefined, search: undefined, preferred_only: undefined, usage_only: undefined,
      include_supports: undefined,
    });
  });
});

describe('list_block_types — include_supports schema', () => {
  it('exposes include_supports in the inputSchema', async () => {
    const { DISCOVERY_TOOLS } = await import('../../../tools/discovery.js');
    const tool = DISCOVERY_TOOLS.find((t) => t.name === 'list_block_types')!;
    expect(tool.inputSchema.properties).toHaveProperty('include_supports');
    expect((tool.inputSchema.properties as any).include_supports.type).toBe('boolean');
  });
});

describe('list_block_types — include_supports validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlockTypes.mockResolvedValue(blockTypesResponse);
  });

  it('rejects a non-boolean include_supports (e.g. the string "false") instead of silently forwarding it', async () => {
    // A bare `as boolean` cast would let the truthy string "false" flow
    // through to client.ts's own truthy check and be sent as
    // include_supports=true — the exact opposite of what was asked.
    await expect(
      handleDiscoveryTool('list_block_types', { include_supports: 'false' }, client as any),
    ).rejects.toThrow(/include_supports.*must be a boolean/i);
    expect(client.getBlockTypes).not.toHaveBeenCalled();
  });

  it('accepts a real boolean include_supports', async () => {
    await expect(
      handleDiscoveryTool('list_block_types', { include_supports: false }, client as any),
    ).resolves.toBeDefined();
  });
});

describe('list_block_types — pagination', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
  });

  it('defaults to limit 50 and offset 0', async () => {
    // Build 60 fake block types so pagination matters
    const many = Array.from({ length: 60 }, (_, i) => ({
      name: `core/block-${i}`, title: `Block ${i}`,
      preference: { tier: 'preferred', score: 90 },
    }));
    client.getBlockTypes.mockResolvedValueOnce({ block_types: many } as any);
    const result = await handleDiscoveryTool('list_block_types', {}, client as any) as Record<string, unknown>;
    expect(result.count).toBe(50);
    expect(result.offset).toBe(0);
    expect(result.next_offset).toBe(50);
    expect(result.total).toBe(60);
  });

  it('honors explicit limit and offset', async () => {
    const many = Array.from({ length: 30 }, (_, i) => ({
      name: `core/block-${i}`, title: `Block ${i}`,
      preference: { tier: 'preferred', score: 90 },
    }));
    client.getBlockTypes.mockResolvedValueOnce({ block_types: many } as any);
    const result = await handleDiscoveryTool('list_block_types', { limit: 10, offset: 20 }, client as any) as Record<string, unknown>;
    expect(result.count).toBe(10);
    expect(result.offset).toBe(20);
    expect(result.next_offset).toBeNull();
  });

  it('next_offset is null when the page ends at total', async () => {
    client.getBlockTypes.mockResolvedValueOnce(blockTypesResponse);
    const result = await handleDiscoveryTool('list_block_types', { limit: 100 }, client as any) as Record<string, unknown>;
    expect(result.next_offset).toBeNull();
  });
});

describe('list_block_types — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getBlockTypes.mockResolvedValue(blockTypesResponse);
  });

  it('returns block_types, count, total, offset, next_offset, guidance', async () => {
    const result = await handleDiscoveryTool('list_block_types', {}, client as any) as Record<string, unknown>;
    expect(Array.isArray(result.block_types)).toBe(true);
    expect(typeof result.count).toBe('number');
    expect(typeof result.total).toBe('number');
    expect(typeof result.offset).toBe('number');
    expect(typeof result.guidance).toBe('string');
  });

  it('guidance is a non-empty string when results exist', async () => {
    const result = await handleDiscoveryTool('list_block_types', {}, client as any) as Record<string, unknown>;
    expect((result.guidance as string).length).toBeGreaterThan(0);
  });
});
