/**
 * Integration: read → edit → read verification (happy path)
 *
 * Creates a throwaway post, reads its blocks, mutates the heading, reads
 * again, and asserts that:
 *   - The write succeeds (200, success: true).
 *   - revision_id advances after the write.
 *   - The heading content attribute reflects the new value on re-read.
 *   - If the plugin assigns gk_refs, the ref is stable across the edit.
 *   - If the plugin returns a `saved` snapshot, it matches the re-read.
 */

import { describe, it, expect } from 'vitest';
import { makeLiveClient, skipUnlessLive, withTestPost, hasRoute } from './setup.js';

const skip = skipUnlessLive();

describe.skipIf(skip)('read → edit → read (integration)', () => {
  it('updates a heading block and verifies the change persists via get_page_blocks', async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      // ── Step 1: initial read ───────────────────────────────────────
      const before = await client.getPageBlocks(postId);
      expect(before.blocks.length).toBeGreaterThanOrEqual(1);

      const heading = before.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();

      const headingIndex = heading!.index;
      const headingRef = heading!.ref; // may be undefined on older plugin builds

      // ── Step 2: mutate the heading ─────────────────────────────────
      const updated = await client.updateBlock(postId, headingIndex, {
        attributes: { content: 'Updated by integration test', level: 2 },
        innerHTML: '<h2 class="wp-block-heading">Updated by integration test</h2>',
      });

      expect(updated.success).toBe(true);
      expect(updated.revision_id).toBeGreaterThan(0);
      // revision_id must advance IF a real save happened (before_revision_id = 0
      // on posts with no prior revisions is valid on some WP configs).
      if (updated.before_revision_id > 0) {
        expect(updated.revision_id).toBeGreaterThan(updated.before_revision_id);
      }

      // If this plugin build returns a `saved` snapshot, validate it.
      if (updated.saved) {
        expect(updated.saved.inner_html).toContain('Updated by integration test');
        expect(updated.saved.block_name).toBe('core/heading');
        // Ref stability: if a ref was present before and the plugin returns
        // saved.ref, they must match.
        if (headingRef && updated.saved.ref) {
          expect(updated.saved.ref).toBe(headingRef);
        }
      }

      // ── Step 3: re-read and verify the change landed on disk ───────
      const after = await client.getPageBlocks(postId);
      const updatedHeading = after.blocks.find((b) => b.name === 'core/heading');
      expect(updatedHeading).toBeDefined();
      // Content attribute must match what we wrote.
      expect(updatedHeading!.attributes.content).toBe('Updated by integration test');

      // If refs are present on both the before and after blocks, they must match.
      if (headingRef && updatedHeading!.ref) {
        expect(updatedHeading!.ref).toBe(headingRef);
      }

      // If the write response carried a saved snapshot, the flat_index must
      // point at the same block we see in the re-read.
      if (updated.saved) {
        expect(updatedHeading!.index).toBe(updated.saved.flat_index);
      }
    });
  }, 30_000);

  it('get_block(ref) returns current snapshot for a block that has a ref', async () => {
    // Only run this sub-test when the single-block fetch route exists.
    // The single-block fetch endpoint is /posts/{id}/block (no trailing 's').
    // Use the anchored regex form to avoid matching /posts/{id}/blocks.
    const singleBlockRouteExists = await hasRoute('/block$');
    if (!singleBlockRouteExists) {
      console.log('[integration] /block single-fetch route not present — skipping get_block sub-test');
      return;
    }

    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      // Read to get the heading.
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();

      // Write an update.
      await client.updateBlock(postId, heading!.index, {
        attributes: { content: 'Ref-verified heading', level: 2 },
        innerHTML: '<h2 class="wp-block-heading">Ref-verified heading</h2>',
      });

      // Use get_block by flat index (always available even without ref support).
      const single = await client.getBlock(postId, { flatIndex: heading!.index });
      expect(single.success).toBe(true);
      expect(single.saved.inner_html).toContain('Ref-verified heading');
    });
  }, 30_000);

  it('get_block(ref) works when the block has a stable ref', async () => {
    const singleBlockRouteExists = await hasRoute('/block$');
    if (!singleBlockRouteExists) {
      console.log('[integration] /block single-fetch route not present — skipping ref sub-test');
      return;
    }

    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();

      // If this plugin version assigns refs, verify ref-based fetch.
      if (!heading!.ref) {
        console.log('[integration] Block has no ref on this plugin build — skipping ref fetch');
        return;
      }

      const ref = heading!.ref;
      await client.updateBlockByRef(postId, ref, {
        attributes: { content: 'Ref-verified heading', level: 2 },
        innerHTML: '<h2 class="wp-block-heading">Ref-verified heading</h2>',
      });

      const single = await client.getBlock(postId, { ref });
      expect(single.success).toBe(true);
      expect(single.saved.inner_html).toContain('Ref-verified heading');
      expect(single.saved.ref).toBe(ref);
    });
  }, 30_000);
});
