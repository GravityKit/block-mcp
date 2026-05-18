/**
 * Tool tests: scan_storage_modes
 *
 * Covers:
 *   - Forwards to client.scanStorageModes with no args
 *   - Returns the raw response (no MCP-side processing)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('scan_storage_modes', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.scanStorageModes.mockResolvedValue({
      scanned_posts: 100, unique_blocks: 25,
      classification: { 'core/paragraph': 'static', 'core/latest-posts': 'dynamic' },
      dual_count: 0, dynamic_count: 1, static_count: 24,
    } as any);
  });

  it('calls client.scanStorageModes with no arguments', async () => {
    await handleDiscoveryTool('scan_storage_modes', {}, client as any);
    expect(client.scanStorageModes).toHaveBeenCalledWith();
  });

  it('returns the raw scan result', async () => {
    const result = await handleDiscoveryTool('scan_storage_modes', {}, client as any) as Record<string, unknown>;
    expect(result.scanned_posts).toBe(100);
    expect(result.unique_blocks).toBe(25);
  });
});
