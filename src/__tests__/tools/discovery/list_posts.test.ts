/**
 * Tool tests: list_posts
 *
 * Covers:
 *   - Forwards all filters verbatim (search, post_type, post_status,
 *     per_page, page)
 *   - All undefined when args is empty
 *   - Returns raw client response
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('list_posts', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.findPosts.mockResolvedValue({
      posts: [{ post_id: 1, title: 'Hello', slug: 'hello', post_type: 'post', post_status: 'publish', post_url: 'x', modified: '2026-01-01' }],
      total: 1, page: 1, per_page: 20, total_pages: 1,
    } as any);
  });

  it('forwards all filters', async () => {
    await handleDiscoveryTool('list_posts', {
      search: 'foo', post_type: 'page', post_status: 'draft', per_page: 50, page: 2,
    }, client as any);
    expect(client.findPosts).toHaveBeenCalledWith({
      search: 'foo', post_type: 'page', post_status: 'draft', per_page: 50, page: 2,
    });
  });

  it('passes undefineds when args is empty', async () => {
    await handleDiscoveryTool('list_posts', {}, client as any);
    expect(client.findPosts).toHaveBeenCalledWith({
      search: undefined, post_type: undefined, post_status: undefined,
      per_page: undefined, page: undefined,
    });
  });

  it('returns the raw client response', async () => {
    const result = await handleDiscoveryTool('list_posts', {}, client as any) as Record<string, unknown>;
    expect(Array.isArray(result.posts)).toBe(true);
    expect(result.total).toBe(1);
  });
});
