/**
 * Tool tests: get_post_info
 *
 * Covers:
 *   - Requires one of: post_id, url, slug (rejects when all three missing)
 *   - Numeric post_id passes through
 *   - String post_id matching /^[0-9]+$/ coerced to integer
 *   - Non-numeric string post_id rejected
 *   - url alone accepted
 *   - slug alone accepted (with optional post_type scope)
 *   - Forwarding includes post_type when supplied
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('get_post_info — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPostInfo.mockResolvedValue({ post_id: 1 } as any);
  });

  it('throws when none of post_id, url, slug is provided', async () => {
    await expect(
      handleDiscoveryTool('get_post_info', {}, client as any)
    ).rejects.toThrow(/post_id, url, or slug/);
  });

  it('throws on non-numeric string post_id', async () => {
    await expect(
      handleDiscoveryTool('get_post_info', { post_id: 'abc' }, client as any)
    ).rejects.toThrow(/post_id must be a positive integer/);
  });

  it('throws on float post_id and does NOT call the client', async () => {
    // post_id is documented as a positive integer. A float input must
    // throw the documented error AND short-circuit before reaching the
    // client — otherwise validation drift would only surface server-side.
    await expect(
      handleDiscoveryTool('get_post_info', { post_id: 1.5 }, client as any)
    ).rejects.toThrow(/post_id must be a positive integer/);
    expect(client.getPostInfo).not.toHaveBeenCalled();
  });

  it('accepts a positive integer post_id and forwards it', async () => {
    await handleDiscoveryTool('get_post_info', { post_id: 1 }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ post_id: 1 }));
  });

  it('throws on object post_id', async () => {
    await expect(
      handleDiscoveryTool('get_post_info', { post_id: {} }, client as any)
    ).rejects.toThrow(/post_id must be a positive integer/);
  });
});

describe('get_post_info — post_id resolution', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPostInfo.mockResolvedValue({ post_id: 1 } as any);
  });

  it('forwards numeric post_id', async () => {
    await handleDiscoveryTool('get_post_info', { post_id: 42 }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ post_id: 42 }));
  });

  it('coerces well-formed integer-string post_id', async () => {
    await handleDiscoveryTool('get_post_info', { post_id: '99' }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ post_id: 99 }));
  });
});

describe('get_post_info — url and slug routing', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.getPostInfo.mockResolvedValue({ post_id: 1 } as any);
  });

  it('forwards url alone', async () => {
    await handleDiscoveryTool('get_post_info', { url: '/about/' }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ url: '/about/' }));
  });

  it('forwards slug alone', async () => {
    await handleDiscoveryTool('get_post_info', { slug: 'about-us' }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith(expect.objectContaining({ slug: 'about-us' }));
  });

  it('forwards slug + post_type', async () => {
    await handleDiscoveryTool('get_post_info', { slug: 'about-us', post_type: 'page' }, client as any);
    expect(client.getPostInfo).toHaveBeenCalledWith({
      post_id: undefined, url: undefined, slug: 'about-us', post_type: 'page',
    });
  });
});
