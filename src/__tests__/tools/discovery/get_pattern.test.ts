/**
 * Tool tests: get_pattern
 *
 * Covers:
 *   - Requires pattern_id (numeric or string)
 *   - Forwards numeric ID
 *   - Forwards string name
 *   - Returns the raw client response
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('get_pattern — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPattern.mockResolvedValue({ id: 1, name: 'Pattern', content: '' } as any);
  });

  it('requires pattern_id', async () => {
    await expect(
      handleDiscoveryTool('get_pattern', {}, client as any)
    ).rejects.toThrow(/pattern_id is required/);
  });

  it('rejects null pattern_id', async () => {
    await expect(
      handleDiscoveryTool('get_pattern', { pattern_id: null }, client as any)
    ).rejects.toThrow(/pattern_id is required/);
  });
});

describe('get_pattern — routing', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPattern.mockResolvedValue({ id: 1, name: 'Pattern', content: '' } as any);
  });

  it('forwards numeric ID', async () => {
    await handleDiscoveryTool('get_pattern', { pattern_id: 42 }, client as any);
    expect(client.getPattern).toHaveBeenCalledWith(42);
  });

  it('forwards string name (registered pattern)', async () => {
    await handleDiscoveryTool('get_pattern', { pattern_id: 'twentytwentyfive/hero' }, client as any);
    expect(client.getPattern).toHaveBeenCalledWith('twentytwentyfive/hero');
  });

  it('returns the raw client response', async () => {
    const fake = { id: 9, name: 'X', content: '<!-- wp:paragraph /-->' };
    client.getPattern.mockResolvedValueOnce(fake as any);
    const result = await handleDiscoveryTool('get_pattern', { pattern_id: 9 }, client as any);
    expect(result).toBe(fake);
  });
});
