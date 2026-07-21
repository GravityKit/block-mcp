/**
 * Tool tests: insert_pattern
 *
 * Covers:
 *   - Validation: post_id required, pattern_id required
 *   - Default synced=true
 *   - synced=false forwarded
 *   - after_top_level → after
 *   - before_top_level → before
 *   - String pattern_id accepted
 *   - Response note for synced insertion
 *   - Response note for non-synced insertion
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handlePatternTool } from '../../../tools/patterns.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { patternInsertResponse } from '../../fixtures/rest-responses.js';

describe('insert_pattern — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(handlePatternTool('insert_pattern', { pattern_id: 1 }, client as any))
      .rejects.toThrow('post_id');
  });

  it('rejects a float post_id', async () => {
    await expect(handlePatternTool('insert_pattern', { post_id: 1.5, pattern_id: 1 }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects a negative post_id', async () => {
    await expect(handlePatternTool('insert_pattern', { post_id: -1, pattern_id: 1 }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
  });

  it('rejects an overflow post_id', async () => {
    await expect(
      handlePatternTool('insert_pattern', { post_id: Number.MAX_SAFE_INTEGER + 1, pattern_id: 1 }, client as any)
    ).rejects.toThrow('post_id must be a positive integer');
  });

  it('requires pattern_id', async () => {
    await expect(handlePatternTool('insert_pattern', { post_id: 1 }, client as any))
      .rejects.toThrow('pattern_id');
  });

  it('throws on unknown tool name', async () => {
    await expect(handlePatternTool('unknown_tool', { post_id: 1, pattern_id: 1 }, client as any))
      .rejects.toThrow('Unknown pattern tool');
  });
});

describe('insert_pattern — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.insertPattern.mockResolvedValue(patternInsertResponse);
    vi.clearAllMocks();
  });

  it('calls client with default synced=true', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123 }, client as any);
    expect(client.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      pattern_id: 123, synced: true,
    }));
  });

  it('passes synced=false through', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, synced: false }, client as any);
    expect(client.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({ synced: false }));
  });

  it('maps after_top_level → after in client call', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, after_top_level: 3 }, client as any);
    expect(client.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({ after: 3 }));
  });

  it('maps before_top_level → before in client call', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123, before_top_level: 2 }, client as any);
    expect(client.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({ before: 2 }));
  });

  it('accepts a string pattern_id', async () => {
    await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 'my-pattern-slug' }, client as any);
    expect(client.insertPattern).toHaveBeenCalledWith(1, expect.objectContaining({
      pattern_id: 'my-pattern-slug',
    }));
  });
});

describe('insert_pattern — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    vi.clearAllMocks();
  });

  it('adds a synced-reference note when pattern was inserted as synced', async () => {
    client.insertPattern.mockResolvedValue(patternInsertResponse); // synced: true
    const result = await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123 }, client as any) as any;
    expect(result.note).toContain('synced reference');
  });

  it('adds an inline/independent note when pattern was inserted non-synced', async () => {
    client.insertPattern.mockResolvedValue({
      ...patternInsertResponse,
      synced: false,
      inserted: [{ index: 5, name: 'core/heading' }],
    });
    const result = await handlePatternTool('insert_pattern', {
      post_id: 1, pattern_id: 123, synced: false,
    }, client as any) as any;
    expect(result.note).toContain('inline');
    expect(result.note).toContain('independent');
  });

  it('success flag is present', async () => {
    client.insertPattern.mockResolvedValue(patternInsertResponse);
    const result = await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123 }, client as any) as any;
    expect(result.success).toBe(true);
  });

  it('revision IDs are present', async () => {
    client.insertPattern.mockResolvedValue(patternInsertResponse);
    const result = await handlePatternTool('insert_pattern', { post_id: 1, pattern_id: 123 }, client as any) as any;
    expect(result.before_revision_id).toBe(100);
    expect(result.revision_id).toBe(101);
  });
});
