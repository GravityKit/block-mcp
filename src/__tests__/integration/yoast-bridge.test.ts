import { describe, it, expect } from 'vitest';
import { skipUnlessLive, makeLiveClient, withTestPost, hasRoute } from './setup.js';

/**
 * Yoast SEO bridge round-trip against a live WordPress.
 *
 * The /yoast routes register only when Yoast SEO is active on the target
 * site, so the whole suite no-ops (cleanly) when the route is absent —
 * that absence is itself the documented contract.
 */
describe.skipIf(skipUnlessLive())('integration: Yoast bridge (when active)', () => {
  it('get / update / bulk round-trip SEO fields', async () => {
    const yoastActive = await hasRoute('yoast');
    if (!yoastActive) return;

    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const initial = await client.getYoastSEO(postId);
      expect(initial.post_id).toBe(postId);

      const updated = await client.updateYoastSEO(postId, {
        title: 'EDGE Yoast Title',
        description: 'EDGE Yoast description.',
        focus_keyword: 'edge',
      });
      expect(updated.title).toBe('EDGE Yoast Title');
      expect(updated.description).toBe('EDGE Yoast description.');

      const bulk = await client.bulkUpdateYoastSEO([
        { post_id: postId, description: 'EDGE bulk description.' },
      ]);
      const entry = bulk.find((r) => r.post_id === postId);
      expect(entry).toBeTruthy();
      // Success entries are the post's full SEO read-back; failures carry `error`.
      expect(entry && 'error' in entry ? entry.error : undefined).toBeUndefined();
      expect((entry as { description?: string }).description).toBe('EDGE bulk description.');
    });
  });
});
