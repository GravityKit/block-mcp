/**
 * Tool tests: resolve_url
 *
 * Covers:
 *   - Requires non-empty string url
 *   - Rejects missing/null/empty/non-string url
 *   - Forwards url verbatim
 *   - Returns raw client response
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { resolveUrlResponse } from '../../fixtures/rest-responses.js';

describe('resolve_url — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.resolveUrl.mockResolvedValue(resolveUrlResponse);
  });

  it('rejects missing url', async () => {
    await expect(
      handleDiscoveryTool('resolve_url', {}, client as any)
    ).rejects.toThrow(/url is required/);
  });

  it('rejects empty-string url', async () => {
    await expect(
      handleDiscoveryTool('resolve_url', { url: '' }, client as any)
    ).rejects.toThrow(/url is required/);
  });

  it('rejects non-string url', async () => {
    await expect(
      handleDiscoveryTool('resolve_url', { url: 123 }, client as any)
    ).rejects.toThrow(/url is required/);
  });

  it('rejects null url', async () => {
    await expect(
      handleDiscoveryTool('resolve_url', { url: null }, client as any)
    ).rejects.toThrow(/url is required/);
  });
});

describe('resolve_url — forwarding', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.resolveUrl.mockResolvedValue(resolveUrlResponse);
  });

  it('forwards a full URL', async () => {
    await handleDiscoveryTool('resolve_url', { url: 'https://example.test/about/' }, client as any);
    expect(client.resolveUrl).toHaveBeenCalledWith('https://example.test/about/');
  });

  it('forwards a relative path', async () => {
    await handleDiscoveryTool('resolve_url', { url: '/about/' }, client as any);
    expect(client.resolveUrl).toHaveBeenCalledWith('/about/');
  });

  it('returns the raw client response', async () => {
    const result = await handleDiscoveryTool('resolve_url', { url: '/x' }, client as any);
    expect(result).toBe(resolveUrlResponse);
  });
});
