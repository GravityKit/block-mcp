import { describe, it, expect } from 'vitest';
import { skipUnlessLive, makeLiveClient, withTestPost } from './setup.js';

/**
 * Edge-case coverage for the WRITE surface against a live WordPress —
 * every accepted positioning/targeting/payload parameter, exercised for the
 * behaviors that bit us or could: silent misplacement, partial writes,
 * non-atomic replacements, ref-vs-index targeting drift, kses stripping.
 *
 * Rate-limit note: block writes are limited to 10/minute PER POST. Every test
 * gets a fresh post via withTestPost, and no test performs more than ~5
 * writes, so the budget is never near the cap.
 *
 * The seed post (withTestPost) always starts with exactly two top-level
 * blocks: [0] core/heading "Integration test heading", [1] core/paragraph.
 */

/** Decorated error fields attached by the client's response interceptor. */
interface WpErr {
  message?: string;
  wpCode?: string;
  wpStatus?: number;
}

async function grab(promise: Promise<unknown>): Promise<WpErr> {
  try {
    await promise;
    return {};
  } catch (e) {
    return e as WpErr;
  }
}

const P = (text: string) => ({
  name: 'core/paragraph',
  attributes: { content: text },
  innerHTML: `<p>${text}</p>`,
});

describe.skipIf(skipUnlessLive())('integration: insert positioning semantics', () => {
  it('before: 0 prepends (the silent-misplace regression)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.insertBlocks(postId, { before: 0, blocks: [P('EDGE-PREPEND')] });
      expect(res.inserted?.[0]?.path).toEqual([0]);

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const first = (read.blocks as Array<{ innerHTML?: string }>)[0];
      expect(first.innerHTML).toContain('EDGE-PREPEND');
    });
  });

  it("after: 'start' also prepends", async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.insertBlocks(postId, { after: 'start', blocks: [P('EDGE-START')] });
      expect(res.inserted?.[0]?.path).toEqual([0]);
    });
  });

  it('omitted anchors and after: -1 both append', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const omitted = await client.insertBlocks(postId, { blocks: [P('EDGE-APPEND-OMIT')] });
      expect(omitted.inserted?.[0]?.path?.[0]).toBeGreaterThanOrEqual(2);

      const minusOne = await client.insertBlocks(postId, { after: -1, blocks: [P('EDGE-APPEND-NEG')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ innerHTML?: string }>;
      expect(blocks[blocks.length - 1].innerHTML).toContain('EDGE-APPEND-NEG');
      expect(minusOne.inserted?.[0]?.path?.[0]).toBe(blocks.length - 1);
    });
  });

  it('numeric after: N inserts after the Nth visible block', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      // Seed is [heading, paragraph]; after:0 → between them.
      await client.insertBlocks(postId, { after: 0, blocks: [P('EDGE-MIDDLE')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ name: string; innerHTML?: string }>;
      expect(blocks[1].innerHTML).toContain('EDGE-MIDDLE');
      expect(blocks[0].name).toBe('core/heading');
    });
  });

  it('out-of-range numeric position clamps to append (never errors, never misplaces)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.insertBlocks(postId, { after: 9999, blocks: [P('EDGE-CLAMP')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ innerHTML?: string }>;
      expect(blocks[blocks.length - 1].innerHTML).toContain('EDGE-CLAMP');
      expect(res.inserted?.[0]?.path?.[0]).toBe(blocks.length - 1);
    });
  });

  it('before_ref / after_ref anchor to the ref, surviving as siblings shift', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, { fields: 'name,ref' });
      const blocks = read.blocks as Array<{ name: string; ref?: string }>;
      const paragraphRef = blocks.find((b) => b.name === 'core/paragraph')?.ref;
      expect(paragraphRef).toBeTruthy();

      await client.insertBlocks(postId, { before_ref: paragraphRef, blocks: [P('EDGE-BEFORE-REF')] });
      // The paragraph shifted down one — after_ref must still resolve.
      await client.insertBlocks(postId, { after_ref: paragraphRef, blocks: [P('EDGE-AFTER-REF')] });

      const after = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const texts = (after.blocks as Array<{ innerHTML?: string }>).map((b) => b.innerHTML ?? '');
      const beforeIdx = texts.findIndex((t) => t.includes('EDGE-BEFORE-REF'));
      const paraIdx = texts.findIndex((t) => t.includes('Integration test paragraph'));
      const afterIdx = texts.findIndex((t) => t.includes('EDGE-AFTER-REF'));
      expect(beforeIdx).toBe(paraIdx - 1);
      expect(afterIdx).toBe(paraIdx + 1);
    });
  });

  it('bogus before_ref fails loudly instead of appending silently', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const before = await client.getPageBlocks(postId, { fields: 'name' });
      const err = await grab(
        client.insertBlocks(postId, { before_ref: 'blk_00000000d', blocks: [P('EDGE-GHOST')] }),
      );
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);
      // And nothing was written.
      const after = await client.getPageBlocks(postId, { fields: 'name' });
      expect((after.blocks as unknown[]).length).toBe((before.blocks as unknown[]).length);
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: container nesting via innerBlocks', () => {
  it('inserts a styled group with children in ONE call and round-trips the tree', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, {
        before: 0,
        blocks: [
          {
            name: 'core/group',
            attributes: { className: 'is-style-callout-info', layout: { type: 'constrained' } },
            innerHTML: '<div class="wp-block-group is-style-callout-info"></div>',
            innerBlocks: [P('EDGE-CHILD-ONE'), P('EDGE-CHILD-TWO')],
          },
        ],
      });

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const group = (read.blocks as Array<{ name: string; innerBlocks?: Array<{ innerHTML?: string }> }>)[0];
      expect(group.name).toBe('core/group');
      expect(group.innerBlocks).toHaveLength(2);
      expect(group.innerBlocks?.[0].innerHTML).toContain('EDGE-CHILD-ONE');
      expect(group.innerBlocks?.[1].innerHTML).toContain('EDGE-CHILD-TWO');
    });
  });

  it('nests two levels deep (columns → column → paragraph)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, {
        blocks: [
          {
            name: 'core/columns',
            innerHTML: '<div class="wp-block-columns"></div>',
            innerBlocks: [
              {
                name: 'core/column',
                innerHTML: '<div class="wp-block-column"></div>',
                innerBlocks: [P('EDGE-DEEP-LEFT')],
              },
              {
                name: 'core/column',
                innerHTML: '<div class="wp-block-column"></div>',
                innerBlocks: [P('EDGE-DEEP-RIGHT')],
              },
            ],
          },
        ],
      });

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{
        name: string;
        innerBlocks?: Array<{ name: string; innerBlocks?: Array<{ innerHTML?: string }> }>;
      }>;
      const columns = blocks.find((b) => b.name === 'core/columns');
      expect(columns?.innerBlocks).toHaveLength(2);
      expect(columns?.innerBlocks?.[0].innerBlocks?.[0].innerHTML).toContain('EDGE-DEEP-LEFT');
      expect(columns?.innerBlocks?.[1].innerBlocks?.[0].innerHTML).toContain('EDGE-DEEP-RIGHT');
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: update targeting and payloads', () => {
  it('attributes-only heading level change auto-transforms the markup (h2 → h3)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.updateBlock(postId, 0, { attributes: { level: 3 } });
      expect(res.saved?.inner_html).toContain('<h3');
      expect(res.saved?.inner_html).not.toContain('<h2');
    });
  });

  it('innerHTML-only update by ref persists exactly', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const read = await client.getPageBlocks(postId, { fields: 'name,ref' });
      const para = (read.blocks as Array<{ name: string; ref?: string }>).find(
        (b) => b.name === 'core/paragraph',
      );
      const res = await client.updateBlockByRef(postId, para!.ref!, {
        innerHTML: '<p>EDGE-REF-UPDATED</p>',
      });
      expect(res.saved?.inner_html).toContain('EDGE-REF-UPDATED');
    });
  });

  it('kses strips script tags from innerHTML on write', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.updateBlock(postId, 1, {
        innerHTML: '<p>safe<script>alert(1)</script></p>',
      });
      expect(res.saved?.inner_html).toContain('safe');
      expect(res.saved?.inner_html).not.toContain('<script');
    });
  });

  it('batch update: verbose returns per-item saved snapshots in one revision', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = await client.updateBlocksBatch(
        postId,
        [
          { flat_index: 0, attributes: { level: 4 } },
          { flat_index: 1, innerHTML: '<p>EDGE-BATCH-TWO</p>' },
        ],
        { verbose: true },
      );
      expect(res.results).toHaveLength(2);
      const saved = (res.results as Array<{ saved?: { inner_html?: string } }>).map(
        (r) => r.saved?.inner_html ?? '',
      );
      expect(saved[0]).toContain('<h4');
      expect(saved[1]).toContain('EDGE-BATCH-TWO');
    });
  });

  it('batch update is all-or-nothing: one stale ref aborts every item', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(
        client.updateBlocksBatch(postId, [
          { flat_index: 0, attributes: { level: 5 } },
          { ref: 'blk_00000000d', innerHTML: '<p>never</p>' },
        ]),
      );
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);

      // The valid item must NOT have been applied.
      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const first = (read.blocks as Array<{ innerHTML?: string }>)[0];
      expect(first.innerHTML).toContain('<h2');
    });
  });

  it('batch update rejects duplicate targets', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(
        client.updateBlocksBatch(postId, [
          { flat_index: 1, innerHTML: '<p>first</p>' },
          { flat_index: 1, innerHTML: '<p>second</p>' },
        ]),
      );
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: delete targeting', () => {
  it('delete by ref removes exactly the referenced block', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, { before: 0, blocks: [P('EDGE-DOOMED')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,ref,innerHTML' });
      const doomed = (read.blocks as Array<{ ref?: string; innerHTML?: string }>).find((b) =>
        b.innerHTML?.includes('EDGE-DOOMED'),
      );
      expect(doomed?.ref).toBeTruthy();

      await client.deleteBlockByRef(postId, doomed!.ref!);

      const after = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const texts = JSON.stringify(after.blocks);
      expect(texts).not.toContain('EDGE-DOOMED');
      expect((after.blocks as unknown[]).length).toBe(2); // seed intact
    });
  });

  it('count removes exactly N consecutive blocks', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, { before: 0, blocks: [P('EDGE-D1'), P('EDGE-D2')] });
      const res = await client.deleteBlock(postId, 0, 2);
      expect(res.deleted_count).toBe(2);

      const after = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      expect((after.blocks as unknown[]).length).toBe(2);
      expect(JSON.stringify(after.blocks)).not.toContain('EDGE-D');
    });
  });

  it('out-of-range index fails loudly', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(client.deleteBlock(postId, 99));
      expect(err.wpStatus).toBe(400);
      expect(`${err.wpCode} ${err.message}`).toMatch(/invalid_index|out of range/i);
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: replace_block_range semantics', () => {
  it('count: 0 inserts without removing', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.replaceBlocksRange(postId, { start: 0, count: 0, blocks: [P('EDGE-RANGE-INS')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ innerHTML?: string }>;
      expect(blocks).toHaveLength(3);
      expect(blocks[0].innerHTML).toContain('EDGE-RANGE-INS');
    });
  });

  it('empty blocks array is a pure delete', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.replaceBlocksRange(postId, { start: 0, count: 1, blocks: [] });
      const read = await client.getPageBlocks(postId, { fields: 'name' });
      const blocks = read.blocks as Array<{ name: string }>;
      expect(blocks).toHaveLength(1);
      expect(blocks[0].name).toBe('core/paragraph');
    });
  });

  it('an invalid replacement block aborts atomically — original range untouched', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(
        client.replaceBlocksRange(postId, {
          start: 0,
          count: 2,
          blocks: [P('EDGE-OK'), { name: 'gk/does-not-exist', innerHTML: '<p>x</p>' }],
        }),
      );
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ name: string; innerHTML?: string }>;
      expect(blocks).toHaveLength(2);
      expect(blocks[0].name).toBe('core/heading');
      expect(JSON.stringify(blocks)).not.toContain('EDGE-OK');
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: edit_block_tree operations', () => {
  it('update-attrs / update-html / duplicate / remove-block round-trip', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      // update-attrs by path.
      const attrs = await client.mutateBlockTree(postId, {
        op: 'update-attrs',
        path: [0],
        attributes: { level: 3 },
      });
      expect(attrs.success).toBe(true);

      // update-html by ref.
      const read = await client.getPageBlocks(postId, { fields: 'name,ref' });
      const paraRef = (read.blocks as Array<{ name: string; ref?: string }>).find(
        (b) => b.name === 'core/paragraph',
      )?.ref;
      await client.mutateBlockTree(postId, {
        op: 'update-html',
        ref: paraRef,
        innerHTML: '<p>EDGE-MUT-HTML</p>',
      });

      // duplicate the paragraph, then remove the copy.
      await client.mutateBlockTree(postId, { op: 'duplicate', path: [1] });
      let now = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      expect((now.blocks as unknown[]).length).toBe(3);

      await client.mutateBlockTree(postId, { op: 'remove-block', path: [2] });
      now = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      expect((now.blocks as unknown[]).length).toBe(2);
      expect(JSON.stringify(now.blocks)).toContain('EDGE-MUT-HTML');
    });
  });

  it('wrap-in-group honors wrapper attributes; unwrap-group restores the child', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.mutateBlockTree(postId, {
        op: 'wrap-in-group',
        path: [1],
        wrapper: { attributes: { className: 'edge-wrapped' } },
      });
      let read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      let blocks = read.blocks as Array<{ name: string; innerBlocks?: Array<{ name: string }> }>;
      expect(blocks[1].name).toBe('core/group');
      expect(blocks[1].innerBlocks?.[0].name).toBe('core/paragraph');

      await client.mutateBlockTree(postId, { op: 'unwrap-group', path: [1] });
      read = await client.getPageBlocks(postId, { fields: 'name' });
      blocks = read.blocks as Array<{ name: string }>;
      expect(blocks.map((b) => b.name)).toEqual(['core/heading', 'core/paragraph']);
    });
  });

  it('insert-child (with nested innerBlocks) and replace-block inside a container', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, {
        blocks: [
          {
            name: 'core/group',
            innerHTML: '<div class="wp-block-group"></div>',
            innerBlocks: [P('EDGE-IC-SEED')],
          },
        ],
      });

      await client.mutateBlockTree(postId, {
        op: 'insert-child',
        path: [2],
        position: 'end',
        block: P('EDGE-IC-ADDED'),
      });

      await client.mutateBlockTree(postId, {
        op: 'replace-block',
        path: [2, 0],
        block: P('EDGE-IC-REPLACED'),
      });

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const group = (read.blocks as Array<{ name: string; innerBlocks?: Array<{ innerHTML?: string }> }>)[2];
      expect(group.innerBlocks).toHaveLength(2);
      expect(group.innerBlocks?.[0].innerHTML).toContain('EDGE-IC-REPLACED');
      expect(group.innerBlocks?.[1].innerHTML).toContain('EDGE-IC-ADDED');
    });
  });

  it('move uses pre-move indexing for path destinations', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      // Move the paragraph [1] before the heading: destination [0], pre-move.
      const res = await client.mutateBlockTree(postId, {
        op: 'move',
        path: [1],
        destination: [0],
      });
      expect(res.success).toBe(true);

      const read = await client.getPageBlocks(postId, { fields: 'name' });
      expect((read.blocks as Array<{ name: string }>).map((b) => b.name)).toEqual([
        'core/paragraph',
        'core/heading',
      ]);
    });
  });

  it('dry_run previews without writing (content and revisions untouched)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const res = (await client.mutateBlockTree(postId, {
        op: 'remove-block',
        path: [0],
        dry_run: true,
      } as never)) as { success?: boolean; dry_run?: boolean };
      expect(res.success).toBe(true);

      const read = await client.getPageBlocks(postId, { fields: 'name' });
      expect((read.blocks as unknown[]).length).toBe(2); // nothing removed
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: revert_to_revision', () => {
  it('reverting to before_revision_id undoes a write', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      // A freshly created post has no revision yet, so the FIRST write's
      // before_revision_id is 0. Establish a baseline revision first.
      await client.updateBlock(postId, 1, { innerHTML: '<p>Integration test paragraph.</p>' });

      const write = await client.updateBlock(postId, 1, { innerHTML: '<p>EDGE-TO-UNDO</p>' });
      expect(write.saved?.inner_html).toContain('EDGE-TO-UNDO');
      expect(write.before_revision_id).toBeTruthy();

      await client.revertToRevision(postId, write.before_revision_id as number);

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const texts = JSON.stringify(read.blocks);
      expect(texts).not.toContain('EDGE-TO-UNDO');
      expect(texts).toContain('Integration test paragraph');
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: full-page rewrite + PUT rate limit', () => {
  it('rewrite_post_blocks replaces the whole tree (nested defs included)', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.replaceAllBlocks(postId, [
        { name: 'core/heading', attributes: { level: 2, content: 'EDGE-RW' }, innerHTML: '<h2 class="wp-block-heading">EDGE-RW</h2>' },
        {
          name: 'core/group',
          innerHTML: '<div class="wp-block-group"></div>',
          innerBlocks: [P('EDGE-RW-CHILD')],
        },
      ]);

      const read = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const blocks = read.blocks as Array<{ name: string; innerBlocks?: Array<{ innerHTML?: string }> }>;
      expect(blocks).toHaveLength(2);
      expect(blocks[1].innerBlocks?.[0].innerHTML).toContain('EDGE-RW-CHILD');
      expect(JSON.stringify(blocks)).not.toContain('Integration test paragraph');
    });
  });

  it('full rewrites have their own stricter rate limit', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.replaceAllBlocks(postId, [P('EDGE-PUT-1')]);
      await client.replaceAllBlocks(postId, [P('EDGE-PUT-2')]);
      const err = await grab(client.replaceAllBlocks(postId, [P('EDGE-PUT-3')]));
      expect(err.wpStatus).toBe(429);
    });
  });
});

describe.skipIf(skipUnlessLive())('integration: mutate guard rails', () => {
  it('rejects an unknown op with a structured 400', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      const err = await grab(
        client.mutateBlockTree(postId, { op: 'explode' as never, path: [0] }),
      );
      expect(err.wpStatus).toBe(400);
    });
  });

  it('rejects moving a container into its own descendant', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, {
        blocks: [
          {
            name: 'core/group',
            innerHTML: '<div class="wp-block-group"></div>',
            innerBlocks: [P('EDGE-NEST')],
          },
        ],
      });
      const err = await grab(
        client.mutateBlockTree(postId, { op: 'move', path: [2], destination: [2, 0] }),
      );
      expect(err.wpStatus).toBeGreaterThanOrEqual(400);
    });
  });

  it('move supports ref destinations and count for consecutive siblings', async () => {
    const client = makeLiveClient();
    await withTestPost(client, async (postId) => {
      await client.insertBlocks(postId, { blocks: [P('EDGE-MV-A'), P('EDGE-MV-B')] });
      const read = await client.getPageBlocks(postId, { fields: 'name,ref,innerHTML' });
      const blocks = read.blocks as Array<{ ref?: string; innerHTML?: string }>;
      const headingRef = blocks[0].ref;

      // Move the two inserted paragraphs (count: 2) before the heading.
      const res = await client.mutateBlockTree(postId, {
        op: 'move',
        path: [2],
        count: 2,
        destination_ref: headingRef,
      });
      expect(res.success).toBe(true);

      const after = await client.getPageBlocks(postId, { fields: 'name,innerHTML' });
      const texts = (after.blocks as Array<{ innerHTML?: string }>).map((b) => b.innerHTML ?? '');
      expect(texts[0]).toContain('EDGE-MV-A');
      expect(texts[1]).toContain('EDGE-MV-B');
      expect(texts[2]).toContain('Integration test heading');
    });
  });
});
