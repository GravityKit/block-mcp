/**
 * Integration: dual-storage enforcement (yoast/faq-block)
 *
 * A "dual" block (e.g. yoast/faq-block, yoast/how-to-block) requires BOTH
 * `attributes` AND `innerHTML` on every write. Sending only `attributes`
 * must return HTTP 400 with code `dual_storage_requires_both`.
 *
 * This test:
 *   1. Calls list_block_types to detect whether Yoast is installed.
 *      If the `yoast/faq-block` type is absent, the test is skipped.
 *   2. Creates a throwaway post with a yoast/faq-block.
 *   3. Sends an update with only `attributes` (no innerHTML).
 *   4. Asserts the 400 / dual_storage_requires_both response.
 *
 * If dual-storage scanning has not yet been run on the site (the scan builds
 * the classification map), the plugin may not know that yoast/faq-block is
 * `dual` and the write could succeed with 200 instead of 400. In that case
 * we emit a console.log and allow the test to pass — the enforcement only
 * fires after a storage-mode scan has been run.
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { makeLiveClient, skipUnlessLive, withTestPost, LIVE_ENV } from './setup.js';
import axios from 'axios';

const skip = skipUnlessLive();

describe.skipIf(skip)('dual-storage enforcement (integration)', () => {
  let yoastInstalled = false;
  let dualBlockName: string | null = null;

  beforeAll(async () => {
    if (skip) return;
    try {
      const client = makeLiveClient();
      const result = await client.getBlockTypes({ namespace: 'yoast' });
      const candidates = result.block_types.filter(
        (bt) =>
          bt.name === 'yoast/faq-block' ||
          bt.name === 'yoast/how-to-block' ||
          bt.storage_mode === 'dual'
      );
      if (candidates.length > 0) {
        yoastInstalled = true;
        dualBlockName = candidates[0].name;
      }
    } catch {
      yoastInstalled = false;
    }
  });

  it('update with only attributes on a dual-storage block returns 400 or 200 (requires scan)', async () => {
    if (!yoastInstalled || !dualBlockName) {
      console.log('[integration] No dual-storage block found (Yoast not installed or no dual blocks) — skipping');
      return;
    }

    const client = makeLiveClient();

    const faqInnerHtml = '<div class="schema-faq"><div class="schema-faq-section"><strong class="schema-faq-question">Test Q</strong><p class="schema-faq-answer">Test A</p></div></div>';
    const faqAttributes = {
      questions: [{ id: 'faq-q1', question: 'Test Q', answer: 'Test A', jsonAnswer: 'Test A', jsonQuestion: 'Test Q' }],
    };

    await withTestPost(client, async (postId) => {
      // Insert the dual-storage block.
      const inserted = await client.insertBlocks(postId, {
        after: 'start',
        blocks: [
          {
            name: dualBlockName!,
            attributes: faqAttributes,
            innerHTML: faqInnerHtml,
          },
        ],
      });
      expect(inserted.success).toBe(true);
      const blockIndex = inserted.inserted[0]?.index;
      expect(blockIndex).toBeGreaterThanOrEqual(0);

      // Try to update with ONLY attributes — dual enforcement should reject it.
      const credentials = Buffer.from(
        `${LIVE_ENV.user}:${LIVE_ENV.password}`
      ).toString('base64');
      const url = `${LIVE_ENV.url.replace(/\/+$/, '')}/wp-json/gk-block-api/v1/posts/${postId}/blocks/${blockIndex}`;

      const response = await axios.patch(
        url,
        { attributes: faqAttributes },
        {
          headers: {
            Authorization: `Basic ${credentials}`,
            'Content-Type': 'application/json',
          },
          timeout: 15_000,
          validateStatus: () => true,
        }
      );

      if (response.status === 400) {
        // Dual-storage enforcement is active — verify the error code.
        const body = response.data as Record<string, unknown>;
        expect(body.code).toBe('dual_storage_requires_both');
        expect(body.message).toBeTruthy();
      } else if (response.status === 200) {
        // The plugin returned a 200 because the storage-mode scan classifying
        // this block as `dual` had not been run on this site. Silently
        // logging-and-continuing turns the test green even though the
        // enforcement path was never exercised — the same outcome a real
        // regression that broke enforcement would produce. Fail loudly so
        // CI surfaces the missing precondition instead of papering over it.
        throw new Error(
          `[integration] dual-storage not enforced for ${dualBlockName} ` +
            '— call scan_storage_modes to classify the block as dual, then re-run this suite. ' +
            'The 200 response means the precondition for this test is not met, not that the test passed.'
        );
      } else {
        // Any other status is unexpected.
        throw new Error(`Unexpected status ${response.status} from dual-storage update`);
      }
    });
  }, 45_000);

  it('update with both attributes AND innerHTML on a dual block succeeds', async () => {
    if (!yoastInstalled || !dualBlockName) {
      console.log('[integration] No dual-storage block found — skipping');
      return;
    }

    const client = makeLiveClient();

    const faqInnerHtml = '<div class="schema-faq"><div class="schema-faq-section"><strong class="schema-faq-question">Test Q</strong><p class="schema-faq-answer">Test A</p></div></div>';
    const faqAttributes = {
      questions: [{ id: 'faq-q1', question: 'Test Q', answer: 'Test A', jsonAnswer: 'Test A', jsonQuestion: 'Test Q' }],
    };

    await withTestPost(client, async (postId) => {
      const inserted = await client.insertBlocks(postId, {
        after: 'start',
        blocks: [{ name: dualBlockName!, attributes: faqAttributes, innerHTML: faqInnerHtml }],
      });
      const blockIndex = inserted.inserted[0]?.index;
      expect(blockIndex).toBeGreaterThanOrEqual(0);

      // Update with BOTH attributes AND innerHTML — must always succeed.
      const updatedHtml = '<div class="schema-faq"><div class="schema-faq-section"><strong class="schema-faq-question">Updated Q</strong><p class="schema-faq-answer">Test A</p></div></div>';
      const result = await client.updateBlock(postId, blockIndex, {
        attributes: {
          questions: [{ id: 'faq-q1', question: 'Updated Q', answer: 'Test A', jsonAnswer: 'Test A', jsonQuestion: 'Updated Q' }],
        },
        innerHTML: updatedHtml,
      });
      expect(result.success).toBe(true);
    });
  }, 45_000);
});
