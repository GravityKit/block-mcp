/**
 * Tool tests: get_site_usage
 *
 * Covers:
 *   - Forwards refresh:undefined when not set
 *   - Forwards refresh:true to bust the transient cache
 *   - Returns raw client response (no MCP-side enrichment)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { siteUsageResponse } from '../../fixtures/rest-responses.js';

describe('get_site_usage', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getSiteUsage.mockResolvedValue(siteUsageResponse);
  });

  it('forwards undefined refresh when not set', async () => {
    await handleDiscoveryTool('get_site_usage', {}, client as any);
    expect(client.getSiteUsage).toHaveBeenCalledWith(undefined);
  });

  it('forwards refresh:true', async () => {
    await handleDiscoveryTool('get_site_usage', { refresh: true }, client as any);
    expect(client.getSiteUsage).toHaveBeenCalledWith(true);
  });

  it('forwards refresh:false', async () => {
    await handleDiscoveryTool('get_site_usage', { refresh: false }, client as any);
    expect(client.getSiteUsage).toHaveBeenCalledWith(false);
  });

  it('returns the raw response', async () => {
    const result = await handleDiscoveryTool('get_site_usage', {}, client as any);
    expect(result).toBe(siteUsageResponse);
  });
});
