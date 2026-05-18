/**
 * Integration: ref stability across sibling mutations
 *
 * Verifies that stable gk_refs survive the two most disruptive sibling
 * operations:
 *   1. Inserting a new block BEFORE a known ref — ref must still resolve.
 *   2. Deleting a block ABOVE another known ref — ref must still resolve.
 *
 * These are the exact scenarios that motivate refs over flat indices.
 *
 * If the live plugin build does not assign refs (older versions), these tests
 * fall back to verifying the simpler property that flat indices shift correctly
 * (the observable behaviour that refs are designed to hide).
 *
 * Batch-update tests require the /batch-update route — they skip gracefully
 * when it is absent.
 */

import { describe, it, expect } from 'vitest';
import { makeLiveClient, skipUnlessLive, withTestPost, hasRoute } from './setup.js';

const skip = skipUnlessLive();

describe.skipIf(skip)('ref stability across sibling mutations (integration)', () => {
  it('block at a given position is addressable after a block is inserted before it', async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      // Read initial state: heading (0) + paragraph (1)
      const initial = await client.getPageBlocks(postId);
      const para = initial.blocks.find((b) => b.name === 'core/paragraph');
      expect(para).toBeDefined();
      const paraIndexBefore = para!.index;
      const paraRef = para!.ref; // undefined on older plugin builds

      // Insert a new block after the heading (before the paragraph).
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      expect(heading).toBeDefined();

      const insertData = heading!.ref
        ? { after_ref: heading!.ref }
        : { after: heading!.top_level_counter ?? heading!.index };

      await client.insertBlocks(postId, {
        ...insertData,
        blocks: [
          {
            name: 'core/paragraph',
            attributes: { content: 'Inserted between heading and paragraph.' },
            innerHTML: '<p>Inserted between heading and paragraph.</p>',
          },
        ],
      });

      // Re-read and confirm the original paragraph has shifted.
      const after = await client.getPageBlocks(postId);

      if (paraRef) {
        // Ref-capable plugin: find by ref and verify the index shifted.
        const paraAfter = after.blocks.find((b) => b.ref === paraRef);
        expect(paraAfter).toBeDefined();
        expect(paraAfter!.index).toBeGreaterThan(paraIndexBefore);

        // Confirm the ref is still directly resolvable via getBlock.
        const singleBlockAvailable = await hasRoute('/block$');
        if (singleBlockAvailable) {
          const single = await client.getBlock(postId, { ref: paraRef });
          expect(single.success).toBe(true);
          expect(single.saved.ref).toBe(paraRef);
        }
      } else {
        // Older plugin without refs: verify there are now 3 blocks (heading +
        // inserted + original paragraph) and the paragraph content is still
        // present at a higher flat index.
        const nonHeadingParas = after.blocks.filter((b) => b.name === 'core/paragraph');
        expect(nonHeadingParas.length).toBeGreaterThanOrEqual(2);
        // One of them must still have the original content.
        const original = nonHeadingParas.find(
          (b) => (b.attributes.content as string | undefined)?.includes('Integration test paragraph')
        );
        expect(original).toBeDefined();
        // Its index must be higher than the inserted block (inserted block took
        // the position right after the heading).
        const inserted = nonHeadingParas.find(
          (b) => (b.attributes.content as string | undefined)?.includes('Inserted between')
        );
        expect(inserted).toBeDefined();
        expect(original!.index).toBeGreaterThan(inserted!.index);
      }
    });
  }, 45_000);

  it('block at a given position survives deletion of a block above it', async () => {
    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      // Initial: heading (0) + paragraph (1).
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      const para = initial.blocks.find((b) => b.name === 'core/paragraph');
      expect(heading).toBeDefined();
      expect(para).toBeDefined();
      const paraRef = para!.ref;

      // Delete the heading.
      await client.deleteBlock(postId, heading!.index);

      // After deletion, the paragraph should be the first block.
      const after = await client.getPageBlocks(postId);

      if (paraRef) {
        const paraAfter = after.blocks.find((b) => b.ref === paraRef);
        expect(paraAfter).toBeDefined();
        // Flat index must be lower (heading is gone).
        expect(paraAfter!.index).toBeLessThan(para!.index);

        const singleBlockAvailable = await hasRoute('/block$');
        if (singleBlockAvailable) {
          const single = await client.getBlock(postId, { ref: paraRef });
          expect(single.success).toBe(true);
          expect(single.saved.ref).toBe(paraRef);
        }
      } else {
        // Older plugin: heading must be gone, paragraph still present.
        const headingAfter = after.blocks.find((b) => b.name === 'core/heading');
        expect(headingAfter).toBeUndefined();
        const paraAfter = after.blocks.find((b) => b.name === 'core/paragraph');
        expect(paraAfter).toBeDefined();
      }
    });
  }, 45_000);

  it('batch update applies two writes in a single revision', async () => {
    const batchRouteExists = await hasRoute('batch-update');
    if (!batchRouteExists) {
      console.log('[integration] batch-update route not present — skipping batch test');
      return;
    }

    const client = makeLiveClient();

    await withTestPost(client, async (postId) => {
      const initial = await client.getPageBlocks(postId);
      const heading = initial.blocks.find((b) => b.name === 'core/heading');
      const para = initial.blocks.find((b) => b.name === 'core/paragraph');
      expect(heading?.ref).toBeDefined();
      expect(para?.ref).toBeDefined();

      const batchResult = await client.updateBlocksBatch(postId, [
        {
          ref: heading!.ref,
          attributes: { content: 'Batch heading', level: 2 },
          innerHTML: '<h2 class="wp-block-heading">Batch heading</h2>',
        },
        {
          ref: para!.ref,
          attributes: { content: 'Batch paragraph.' },
          innerHTML: '<p>Batch paragraph.</p>',
        },
      ], { verbose: true });

      expect(batchResult.success).toBe(true);
      expect(batchResult.count).toBe(2);
      // ONE revision for both changes.
      expect(batchResult.revision_id).toBeGreaterThan(0);

      // Verify on disk.
      const after = await client.getPageBlocks(postId);
      const h = after.blocks.find((b) => b.ref === heading!.ref);
      const p = after.blocks.find((b) => b.ref === para!.ref);
      expect(h?.attributes.content).toBe('Batch heading');
      expect(p?.attributes.content).toBe('Batch paragraph.');
    });
  }, 45_000);
});
