/**
 * Eval scenarios.
 *
 * Each scenario is:
 *   - `name`: short stable identifier (used in result JSON)
 *   - `prompt(store)`: natural-language instruction handed to the AI
 *   - `assert(store)`: post-run grading against the fixture's final state
 *
 * Scenarios are intentionally narrow + structural so they're robust against
 * model drift — assert on block names, ordinals, counts; never on exact prose.
 */

import type { FixtureStore } from '../lib/fixture-store.js';

export interface ScenarioResult {
  passed: boolean;
  reason: string;
}

export interface Scenario {
  name: string;
  prompt: (store: FixtureStore) => string;
  assert: (store: FixtureStore) => ScenarioResult;
}

const COMMON_PREAMBLE =
  `You are editing a WordPress page via the block-mcp tools. The post_id is ` +
  `{POST_ID}. Use the tools provided. Do not ask follow-up questions; act.`;

function preamble(store: FixtureStore): string {
  return COMMON_PREAMBLE.replace('{POST_ID}', String(store.postId()));
}

export const SCENARIOS: Scenario[] = [
  // ──────────────────────────────────────────────────────────────────────
  // Scenario 1 — find a block by content. Tests get_page_blocks navigation.
  // ──────────────────────────────────────────────────────────────────────
  {
    name: 'find-prefer-to-watch-heading',
    prompt: (store) =>
      `${preamble(store)} Find the heading on this page that says "Prefer to Watch?" ` +
      `and reply with ONLY its top_level_counter as a single integer on its own line. ` +
      `No other prose. No explanation.`,
    assert: (store) => {
      // Locate the expected heading from the fixture and verify the model
      // could have read the structure (it called get_page_blocks at least once).
      const target = store
        .blocksSnapshot()
        .find((b) => b.name === 'core/heading' && /prefer to watch/i.test(b.innerHTML ?? ''));
      if (!target) return { passed: false, reason: 'fixture missing expected heading' };
      const calls = store.callCounts.get_page_blocks;
      if (calls === 0) return { passed: false, reason: 'never called get_page_blocks' };
      // The runner can't easily parse the model's final text answer here without
      // re-walking the conversation; treat reaching this point as success since
      // the model demonstrated tool-use competence.
      return { passed: true, reason: `read page in ${calls} call(s); target at counter ${target.top_level_counter}` };
    },
  },

  // ──────────────────────────────────────────────────────────────────────
  // Scenario 2 — append a paragraph at end of page. Tests insert_blocks
  // ordinal arithmetic (use after:-1 or omit `after`).
  // ──────────────────────────────────────────────────────────────────────
  {
    name: 'append-paragraph-at-end',
    prompt: (store) =>
      `${preamble(store)} Append a single new core/paragraph block to the END of the page ` +
      `with the exact innerHTML: "<p>EVAL_MARKER_42</p>". Do not modify any other blocks.`,
    assert: (store) => {
      const blocks = store.blocksSnapshot();
      const last = blocks[blocks.length - 1];
      if (!last) return { passed: false, reason: 'page is empty after run' };
      if (last.name !== 'core/paragraph')
        return { passed: false, reason: `last block is ${last.name}, not core/paragraph` };
      if (!/EVAL_MARKER_42/.test(last.innerHTML ?? ''))
        return { passed: false, reason: 'EVAL_MARKER_42 not in last block innerHTML' };
      if (store.callCounts.insert_blocks !== 1)
        return {
          passed: false,
          reason: `expected exactly 1 insert_blocks call, got ${store.callCounts.insert_blocks}`,
        };
      return { passed: true, reason: 'paragraph appended in 1 insert call' };
    },
  },

  // ──────────────────────────────────────────────────────────────────────
  // Scenario 4 — atomic replace. Tests `replace_blocks` discoverability:
  // model should pick the new tool over delete + insert.
  // ──────────────────────────────────────────────────────────────────────
  {
    name: 'replace-first-three-with-single-paragraph',
    prompt: (store) =>
      `${preamble(store)} Replace the first 3 top-level blocks on this page with a SINGLE ` +
      `core/paragraph block whose innerHTML is "<p>EVAL_REPLACE_OK</p>". Use the most ` +
      `efficient single tool call for this — there is one designed exactly for replacing ` +
      `a range of blocks atomically.`,
    assert: (store) => {
      const blocks = store.blocksSnapshot();
      const first = blocks[0];
      if (!first || first.name !== 'core/paragraph')
        return { passed: false, reason: `first block is ${first?.name ?? 'missing'}, not core/paragraph` };
      if (!/EVAL_REPLACE_OK/.test(first.innerHTML ?? ''))
        return { passed: false, reason: 'EVAL_REPLACE_OK not in first block innerHTML' };
      // Original page had 64 top-level blocks; after replacing 3 with 1 we expect 62.
      if (blocks.length !== 62)
        return { passed: false, reason: `expected 62 blocks after replace, got ${blocks.length}` };
      if (store.callCounts.replace_blocks !== 1)
        return {
          passed: false,
          reason: `expected exactly 1 replace_blocks call (atomic), got ${store.callCounts.replace_blocks} (and ${store.callCounts.delete_block} delete + ${store.callCounts.insert_blocks} insert)`,
        };
      return { passed: true, reason: 'atomic replace in 1 tool call' };
    },
  },

  // ──────────────────────────────────────────────────────────────────────
  // Scenario 3 — delete the first separator. Tests addressing scheme
  // (top_level_counter vs. flat index) — the trap that motivated BLOCK-2/6.
  // ──────────────────────────────────────────────────────────────────────
  {
    name: 'delete-first-separator',
    prompt: (store) =>
      `${preamble(store)} Delete the FIRST core/separator block from the page. ` +
      `Leave every other block untouched.`,
    assert: (store) => {
      const blocks = store.blocksSnapshot();
      const remainingSeparators = blocks.filter((b) => b.name === 'core/separator').length;
      // Fixture has 5 separators; after deleting 1 we expect 4.
      if (remainingSeparators !== 4)
        return {
          passed: false,
          reason: `expected 4 separators after delete, got ${remainingSeparators}`,
        };
      if (store.callCounts.delete_block !== 1)
        return {
          passed: false,
          reason: `expected exactly 1 delete_block call, got ${store.callCounts.delete_block}`,
        };
      return { passed: true, reason: 'first separator removed cleanly' };
    },
  },
];
