#!/usr/bin/env node
/**
 * Agent-loop benchmark — measures end-to-end latency of "user types a prompt
 * → MCP tool gets called → page is edited" across two WordPress MCPs and
 * multiple Claude models. Complements scripts/mcp-compare.mjs, which only
 * times the transport layer.
 *
 * What this measures vs. mcp-compare.mjs:
 *   - mcp-compare.mjs: how fast the WP REST APIs respond. Deterministic, free.
 *   - this script:     how long the whole agent loop takes — prompt parse,
 *                      tool selection, MCP round-trip, response parse,
 *                      follow-up reasoning. Noisy, costs API tokens, but
 *                      reflects the real-world UX.
 *
 * Per scenario × MCP × model × trial:
 *   1. Re-seed the test page to a known state via `wp eval-file`.
 *   2. Spawn `claude --bare --print --output-format json --mcp-config <one MCP only>
 *      --model <X>` with the scenario's prompt.
 *   3. Time wall-clock; parse the JSON output to count tool_use entries.
 *   4. Read the page back through gk-block-api and run the scenario's
 *      validator. Only the validator decides if the trial passed — not the
 *      agent's claim of success.
 *   5. Record { wall_clock_ms, tool_calls, validated, cost_usd, error? }.
 *
 * Per-call MCP isolation: each invocation gets a config that exposes ONLY one
 * MCP, so the agent can't cheat by picking the better tool when both are
 * available.
 *
 * Required env:
 *   WP_BASE                Site URL
 *   WP_USER                WordPress username with edit caps
 *   WP_PASS                Application Password
 *   BLOCK_MCP_DIST         Absolute path to dist/index.cjs (the built block-mcp server)
 *   WP_PATH                WordPress install path (for the SSH-driven re-seed)
 *   WP_LIVE_HOST/USER/PORT/SSH_PASSWORD  SSH credentials for re-seed + reset
 *
 * Optional env:
 *   SCENARIOS              Comma-separated scenario names. Default: all.
 *   MCPS                   Comma-separated MCP names: block-mcp, wp-mcp. Default: both.
 *   MODELS                 Comma-separated model names. Default: sonnet,haiku.
 *   TRIALS                 Trials per (scenario, MCP, model). Default: 2.
 *   MAX_BUDGET_USD         Per-invocation budget cap. Default: 0.50.
 *   PER_CALL_TIMEOUT_MS    Wall-clock timeout per invocation. Default: 90000 (90s).
 *
 * Usage:
 *   WP_BASE=... WP_USER=... WP_PASS=... BLOCK_MCP_DIST=/path/to/dist/index.cjs \
 *   WP_PATH=... WP_LIVE_HOST=... WP_LIVE_USER=... WP_LIVE_PORT=... WP_LIVE_SSH_PASSWORD=... \
 *     node scripts/mcp-agent-bench.mjs
 */

import { spawn, execSync } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import https from 'node:https';
import axios from 'axios';

// ── Required env ───────────────────────────────────────────────────────────
// LOCAL_WP_PATH set → run wp-cli directly (no SSH). Otherwise SSH-based remote.
const LOCAL_WP_PATH = process.env.LOCAL_WP_PATH;
const REQUIRED = LOCAL_WP_PATH
  ? ['WP_BASE', 'WP_USER', 'WP_PASS', 'BLOCK_MCP_DIST', 'LOCAL_WP_PATH']
  : ['WP_BASE', 'WP_USER', 'WP_PASS', 'BLOCK_MCP_DIST', 'WP_PATH', 'WP_LIVE_HOST', 'WP_LIVE_USER', 'WP_LIVE_PORT', 'WP_LIVE_SSH_PASSWORD'];
for (const k of REQUIRED) {
  if (!process.env[k]) { console.error(`Missing env: ${k}`); process.exit(1); }
}
const {
  WP_BASE, WP_USER, WP_PASS, BLOCK_MCP_DIST, WP_PATH,
  WP_LIVE_HOST, WP_LIVE_USER, WP_LIVE_PORT, WP_LIVE_SSH_PASSWORD,
} = process.env;

const SCENARIOS_FILTER = (process.env.SCENARIOS || '').split(',').filter(Boolean);
const MCPS_FILTER      = (process.env.MCPS      || '').split(',').filter(Boolean);
const MODELS_FILTER    = (process.env.MODELS    || '').split(',').filter(Boolean);
const TRIALS           = Math.max(1, parseInt(process.env.TRIALS           || '2', 10));
const MAX_BUDGET_USD   = process.env.MAX_BUDGET_USD || '0.50';
const PER_CALL_TIMEOUT_MS = parseInt(process.env.PER_CALL_TIMEOUT_MS || '90000', 10);
// FAIL_FAST_BLOCK_MCP=1 → abort the whole bench as soon as Block MCP fails
// validation on any scenario. Used during scenario-tuning so a broken
// validator or prompt doesn't burn the rest of the matrix on the other
// MCPs. Default: off (run the full matrix even if Block MCP fails).
const FAIL_FAST_BLOCK_MCP = process.env.FAIL_FAST_BLOCK_MCP === '1';

// ── MCP configs (per-call isolation: only the named MCP is exposed) ────────
const AI_ENGINE_MCP_URL = process.env.AI_ENGINE_MCP_URL;
const AI_ENGINE_BEARER  = process.env.AI_ENGINE_BEARER;

const MCP_CONFIGS = {
  'block-mcp': {
    label: 'Block MCP',
    config: {
      mcpServers: {
        'block-mcp': {
          command: 'node',
          args: [BLOCK_MCP_DIST],
          env: {
            WORDPRESS_URL: WP_BASE,
            WORDPRESS_USER: WP_USER,
            WORDPRESS_APP_PASSWORD: WP_PASS,
          },
        },
      },
    },
  },
  'wp-mcp': {
    label: 'WP REST MCP (InstaWP/mcp-wp)',
    config: {
      mcpServers: {
        'wp-mcp': {
          command: 'npx',
          args: ['-y', '@instawp/mcp-wp'],
          env: {
            WORDPRESS_API_URL: WP_BASE,
            WORDPRESS_USERNAME: WP_USER,
            WORDPRESS_PASSWORD: WP_PASS,
          },
        },
      },
    },
  },
  'ai-engine': {
    label: 'AI Engine Pro',
    skip: !AI_ENGINE_MCP_URL || !AI_ENGINE_BEARER,
    config: {
      mcpServers: {
        'ai-engine': {
          type: 'http',
          url: AI_ENGINE_MCP_URL,
          headers: { Authorization: `Bearer ${AI_ENGINE_BEARER}` },
        },
      },
    },
  },
};

// ── Scenarios ──────────────────────────────────────────────────────────────
// Each scenario:
//   - prompt:    user's natural-language ask. {POST_ID} gets substituted.
//   - validate:  fn(blocks) → { ok: bool, why: string }. Reads the page back
//                via gk-block-api and inspects the actual state, regardless
//                of what the agent claimed.
const SCENARIOS = {
  'change-h2-to-h3': {
    label: 'Change one H2 to H3',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the H2 heading "Code samples" and change it to an H3. Use the available MCP to do it. Don't paraphrase — keep the heading text exactly the same, just change the level.`,
    validate(blocks) {
      // Walk the tree looking for a heading whose text is "Code samples".
      const found = [];
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/heading') {
            const text = (b.text_preview || '').trim();
            const level = b.attributes?.level;
            if (text === 'Code samples') found.push({ level });
          }
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (found.length !== 1) return { ok: false, why: `expected exactly 1 "Code samples" heading; found ${found.length}` };
      if (found[0].level !== 3) return { ok: false, why: `heading is still level ${found[0].level}, expected 3` };
      return { ok: true, why: 'H2 "Code samples" was changed to H3' };
    },
  },
  'add-paragraph-after-intro': {
    label: 'Add a paragraph after the Introduction heading',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, immediately after the H2 heading "Introduction", insert a new paragraph block with the exact text: "Welcome to the benchmark." Use the available MCP. Don't change anything else on the page.`,
    validate(blocks) {
      // Find the "Introduction" heading at the top level, then check the next
      // sibling is a paragraph containing the expected text.
      let introIdx = -1;
      for (let i = 0; i < blocks.length; i++) {
        const b = blocks[i];
        if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Introduction') { introIdx = i; break; }
      }
      if (introIdx === -1) return { ok: false, why: 'no top-level "Introduction" heading found' };
      const next = blocks[introIdx + 1];
      if (!next || next.name !== 'core/paragraph') return { ok: false, why: 'block after "Introduction" is not a paragraph' };
      if (!(next.text_preview || '').includes('Welcome to the benchmark')) {
        return { ok: false, why: `paragraph text is "${next.text_preview}", expected to contain "Welcome to the benchmark."` };
      }
      return { ok: true, why: 'paragraph correctly inserted after Introduction' };
    },
  },

  // ── Cool-ops scenarios that exercise structural editing ────────────────────
  // Designed to exercise capabilities that go beyond "rewrite a block in place":
  // moving across siblings, inserting INTO an existing container, deleting a
  // block. These are where the standard WP REST API shape (whole-page rewrites)
  // gets fragile.

  'move-conclusion-up': {
    label: 'Move the Conclusion heading above Introduction',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the H2 heading "Conclusion" near the bottom of the page and move just that heading block (not the paragraphs below it) so it appears ABOVE the H2 heading "Introduction" in the page order. The Conclusion heading must end up earlier in the block list than Introduction. Use the available MCP. Keep the heading text identical.`,
    validate(blocks) {
      // Find both at top level by their text. Conclusion must precede
      // Introduction. We accept any reasonable destination — the spirit of
      // the test is "the move actually happened", not pixel-perfect placement.
      const concIdx  = blocks.findIndex((b) => b.name === 'core/heading' && (b.text_preview || '').trim() === 'Conclusion');
      const introIdx = blocks.findIndex((b) => b.name === 'core/heading' && (b.text_preview || '').trim() === 'Introduction');
      if (concIdx === -1)  return { ok: false, why: 'Conclusion heading missing from top level' };
      if (introIdx === -1) return { ok: false, why: 'Introduction heading missing from top level' };
      if (concIdx >= introIdx) {
        return { ok: false, why: `Conclusion at idx ${concIdx} is not before Introduction at idx ${introIdx}` };
      }
      // Move, not copy.
      let count = 0;
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Conclusion') count++;
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (count !== 1) return { ok: false, why: `expected exactly 1 Conclusion heading; found ${count}` };
      return { ok: true, why: `Conclusion (idx ${concIdx}) now precedes Introduction (idx ${introIdx})` };
    },
  },

  'insert-into-group': {
    label: 'Add a paragraph INSIDE the existing "Grouped section" group',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, there's an H2 "Grouped section" followed by a core/group block. INSIDE that group block (as a child, after the existing children), add a new paragraph with the exact text: "Inserted at the bottom of the group." Don't add it as a sibling to the group — it must be a child of the group. Use the available MCP.`,
    validate(blocks) {
      // Find the core/group that follows "Grouped section". It's a top-level
      // sibling of the heading.
      let headingIdx = -1;
      for (let i = 0; i < blocks.length; i++) {
        const b = blocks[i];
        if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Grouped section') {
          headingIdx = i; break;
        }
      }
      if (headingIdx === -1) return { ok: false, why: 'no "Grouped section" heading found' };
      // The group is the next core/group sibling at the top level.
      let group = null;
      for (let i = headingIdx + 1; i < blocks.length; i++) {
        if (blocks[i].name === 'core/group') { group = blocks[i]; break; }
        if (blocks[i].name === 'core/heading') break; // hit the next section
      }
      if (!group) return { ok: false, why: 'no core/group after "Grouped section" heading' };
      const children = group.innerBlocks || [];
      if (!children.length) return { ok: false, why: 'group has no children at all' };
      const last = children[children.length - 1];
      if (last.name !== 'core/paragraph') {
        return { ok: false, why: `last child of group is ${last.name}, expected core/paragraph` };
      }
      const text = (last.text_preview || '').trim();
      if (!text.includes('Inserted at the bottom of the group')) {
        return { ok: false, why: `last paragraph text is "${text}", expected to contain "Inserted at the bottom of the group."` };
      }
      // Make sure the agent didn't ALSO add it as a top-level sibling.
      const topMatches = blocks.filter(
        (b) => b.name === 'core/paragraph' && (b.text_preview || '').includes('Inserted at the bottom of the group'),
      );
      if (topMatches.length) {
        return { ok: false, why: 'paragraph was added as a top-level sibling, not as a child of the group' };
      }
      return { ok: true, why: 'paragraph correctly inserted inside the group as the last child' };
    },
  },

  'add-row-to-table': {
    label: 'Add a row to the existing comparison table',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the existing core/table block (it has a header row "Approach | Risk | Speed" and two body rows). Add a third body row at the BOTTOM with these three cells, in order: "Hand-rolled HTML", "High", "Slow". Use the available MCP. Don't change any other rows or anything else on the page.`,
    validate(blocks) {
      // Find the table anywhere in the tree.
      let table = null;
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/table' && !table) table = b;
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (!table) return { ok: false, why: 'no core/table block on the page' };

      // Different shapes: WP usually stores rows in attributes.body[].cells[].content
      // but some serialisers leave the data in innerHTML only. Build a single
      // lowercased haystack.
      const haystack = [
        table.text_preview || '',
        table.innerHTML || '',
        JSON.stringify(table.attributes || {}),
      ].join(' ').toLowerCase();

      // The new row's cells must all be present.
      const required = ['hand-rolled html', 'high', 'slow'];
      const missing = required.filter((s) => !haystack.includes(s));
      if (missing.length) {
        return { ok: false, why: `table is missing required new-row cells: ${missing.join(', ')}` };
      }

      // The original cells must still be there too — we're adding, not replacing.
      const original = ['approach', 'risk', 'speed', 'whole-page rewrite', 'block-level edit'];
      const lost = original.filter((s) => !haystack.includes(s));
      if (lost.length) {
        return { ok: false, why: `original table cells were destroyed: ${lost.join(', ')}` };
      }

      // Body row count: try to detect via attributes.body; fall back to <tr> count
      // in innerHTML excluding the header.
      let bodyRowCount = null;
      const attrs = table.attributes || {};
      if (Array.isArray(attrs.body)) {
        bodyRowCount = attrs.body.length;
      } else if (typeof table.innerHTML === 'string' && table.innerHTML.length) {
        const tbody = table.innerHTML.toLowerCase().match(/<tbody>[\s\S]*?<\/tbody>/);
        if (tbody) {
          bodyRowCount = (tbody[0].match(/<tr\b/g) || []).length;
        } else {
          // No explicit tbody — count <tr> outside of <thead>.
          const thead = table.innerHTML.toLowerCase().match(/<thead>[\s\S]*?<\/thead>/);
          const total = (table.innerHTML.toLowerCase().match(/<tr\b/g) || []).length;
          const head  = thead ? (thead[0].match(/<tr\b/g) || []).length : 0;
          bodyRowCount = total - head;
        }
      }
      if (bodyRowCount !== null && bodyRowCount < 3) {
        return { ok: false, why: `expected at least 3 body rows after add; found ${bodyRowCount}` };
      }
      return { ok: true, why: `row added; body now has ${bodyRowCount ?? 'unknown'} rows including the new one` };
    },
  },

  'delete-column-from-table': {
    label: 'Delete the "Risk" column from the comparison table',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the existing core/table block (it has a header row "Approach | Risk | Speed" and two body rows). Delete the entire "Risk" column — that means removing the "Risk" header cell AND the corresponding cell in every body row. The table should end up with just two columns: "Approach" and "Speed". Use the available MCP. Don't change anything else.`,
    validate(blocks) {
      let table = null;
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/table' && !table) table = b;
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (!table) return { ok: false, why: 'no core/table block on the page' };

      const haystack = [
        table.text_preview || '',
        table.innerHTML || '',
        JSON.stringify(table.attributes || {}),
      ].join(' ').toLowerCase();

      // The Risk-column values must be gone.
      const removed = ['risk', 'high', 'low'];
      const surviving = removed.filter((s) => haystack.includes(s));
      if (surviving.length) {
        return { ok: false, why: `Risk-column values still present: ${surviving.join(', ')}` };
      }

      // The other two columns must survive.
      const required = ['approach', 'speed', 'whole-page rewrite', 'block-level edit'];
      const lost = required.filter((s) => !haystack.includes(s));
      if (lost.length) {
        return { ok: false, why: `non-Risk columns were destroyed: ${lost.join(', ')}` };
      }

      // Cell count per row should be 2 — verify if we can read the structure.
      const attrs = table.attributes || {};
      const rowsToCheck = [];
      if (Array.isArray(attrs.head) && attrs.head[0]?.cells) rowsToCheck.push(attrs.head[0].cells.length);
      if (Array.isArray(attrs.body)) {
        for (const r of attrs.body) {
          if (Array.isArray(r?.cells)) rowsToCheck.push(r.cells.length);
        }
      }
      if (rowsToCheck.length && rowsToCheck.some((n) => n !== 2)) {
        return { ok: false, why: `expected every row to have 2 cells; got ${rowsToCheck.join(',')}` };
      }
      return { ok: true, why: '"Risk" column removed; Approach + Speed columns intact' };
    },
  },

  'delete-cta': {
    label: 'Delete the "Call to action" heading block',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the H2 heading "Call to action" and delete just that one heading block. Don't delete anything else — only the H2 heading itself. Use the available MCP.`,
    validate(blocks) {
      // Should be no heading anywhere with text "Call to action".
      let count = 0;
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Call to action') count++;
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (count !== 0) return { ok: false, why: `"Call to action" heading still present (found ${count})` };
      // Also: page should still have plenty of content (don't accept "deleted everything").
      if (blocks.length < 8) {
        return { ok: false, why: `page has only ${blocks.length} top-level blocks; agent likely over-deleted` };
      }
      return { ok: true, why: '"Call to action" heading deleted, rest of page intact' };
    },
  },

  // ── Live-page scenarios (use SEED_SCRIPT=seed-bench-page-live.php) ─────────
  // These target a snapshot of https://www.gravitykit.com/for/developers/ —
  // a real production marketing landing page heavy in Stackable blocks plus
  // ten core/group sections (Header, Trusted by, features, Side to side,
  // Perks, Tools, Testimonials, Case studies, FAQs). The agents see real
  // production complexity: deep nesting, mixed namespaces (core + stackable),
  // metadata.name-tagged sections, and a Stackable-heavy block mix where
  // Block MCP's tier policy treats the namespace as 'avoid'.
  //
  // No core/table on this page → table-modification scenarios stay synthetic-only.

  'live-change-h2-to-h3': {
    label: '[live] Change H2 "Build anything and everything" to H3',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the H2 heading with the text "Build anything and everything" and change it to H3 (level 3). Don't paraphrase — keep the heading text exactly the same, just change the level. Use the available MCP.`,
    validate(blocks) {
      const found = [];
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Build anything and everything') {
            found.push({ level: b.attributes?.level });
          }
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (found.length !== 1) return { ok: false, why: `expected exactly 1 "Build anything and everything" heading; found ${found.length}` };
      if (found[0].level !== 3) return { ok: false, why: `heading is still level ${found[0].level}, expected 3` };
      return { ok: true, why: 'heading changed to H3' };
    },
  },

  'live-move-faqs-before-testimonials': {
    label: '[live] Move the FAQs group to right before the Testimonials group',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, the page has these named core/group blocks at the top level (in order): Header, Trusted by, features, Tools, Testimonials, Case studies, FAQs. Move the "FAQs" group block (the entire group, with all its children) to be IMMEDIATELY BEFORE the "Testimonials" group. The new top-level order should be: Header, Trusted by, features, Tools, FAQs, Testimonials, Case studies. Don't change anything inside the groups. Use the available MCP.`,
    validate(blocks) {
      // Build the ordered list of named-group sections at the top level only.
      const sections = blocks
        .filter((b) => b.name === 'core/group' && b.attributes?.metadata?.name)
        .map((b) => b.attributes.metadata.name);
      const expected = ['Header', 'Trusted by', 'features', 'Tools', 'FAQs', 'Testimonials', 'Case studies'];
      let cursor = 0;
      for (const name of expected) {
        const idx = sections.indexOf(name, cursor);
        if (idx === -1) return { ok: false, why: `expected section "${name}" not found in order; got: ${sections.join(' → ')}` };
        cursor = idx + 1;
      }
      const faqsIdx = sections.indexOf('FAQs');
      const testimonialsIdx = sections.indexOf('Testimonials');
      if (faqsIdx === -1 || testimonialsIdx === -1) return { ok: false, why: 'FAQs or Testimonials section missing' };
      if (faqsIdx >= testimonialsIdx) return { ok: false, why: `FAQs (idx ${faqsIdx}) is not before Testimonials (idx ${testimonialsIdx})` };
      const faqsCount = sections.filter((n) => n === 'FAQs').length;
      if (faqsCount !== 1) return { ok: false, why: `expected exactly 1 FAQs section; found ${faqsCount}` };
      return { ok: true, why: 'FAQs group moved to right before Testimonials' };
    },
  },

  'live-insert-into-trusted-by': {
    label: '[live] Add a paragraph inside the "Trusted by" group',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the core/group block whose metadata.name is "Trusted by". INSIDE that group block (as a child, after all existing children), add a new core/paragraph with the exact text: "Built by developers, for developers." It must be a child of the Trusted by group, not a top-level sibling. Don't touch anything else. Use the available MCP.`,
    validate(blocks) {
      const target = blocks.find((b) => b.name === 'core/group' && b.attributes?.metadata?.name === 'Trusted by');
      if (!target) return { ok: false, why: 'no "Trusted by" group at top level' };
      const children = target.innerBlocks || [];
      if (!children.length) return { ok: false, why: '"Trusted by" group has no children at all' };
      const last = children[children.length - 1];
      if (last.name !== 'core/paragraph') {
        return { ok: false, why: `last child of "Trusted by" is ${last.name}, expected core/paragraph` };
      }
      const text = (last.text_preview || last.innerHTML || '').toLowerCase();
      if (!text.includes('built by developers, for developers')) {
        return { ok: false, why: `last paragraph is "${text.slice(0, 80)}", expected the new tagline` };
      }
      // Make sure the agent didn't ALSO add it as a top-level sibling.
      const stray = blocks.some(
        (b) => b !== target
          && b.name === 'core/paragraph'
          && (b.text_preview || b.innerHTML || '').toLowerCase().includes('built by developers, for developers'),
      );
      if (stray) {
        return { ok: false, why: 'paragraph was also added at the top level, not just inside the group' };
      }
      return { ok: true, why: 'paragraph correctly inserted as last child of "Trusted by" group' };
    },
  },

  'live-add-h2-between-features-and-tools': {
    label: '[live] Insert an H2 between the "features" group and the "Tools" group',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the core/group block whose metadata.name is "features" — it sits at the top level. Immediately after it (as a top-level sibling, BEFORE the next group whose metadata.name is "Tools"), insert a new core/heading at level 2 with the exact text: "What's next?" Don't touch the features group's contents or any other block. Use the available MCP.`,
    validate(blocks) {
      // featuresIdx is at top level; the very next non-empty top-level block
      // must be the new H2. Spacers between sections are normal — skip them.
      const featuresIdx = blocks.findIndex((b) => b.name === 'core/group' && b.attributes?.metadata?.name === 'features');
      if (featuresIdx === -1) return { ok: false, why: 'no "features" group at top level' };

      // Find the first non-spacer block after features.
      let next = null;
      for (let i = featuresIdx + 1; i < blocks.length; i++) {
        if (blocks[i].name === 'core/spacer') continue;
        next = blocks[i];
        break;
      }
      if (!next) return { ok: false, why: 'nothing meaningful follows features at top level' };
      if (next.name !== 'core/heading') {
        return { ok: false, why: `first non-spacer block after features is ${next.name}, expected core/heading` };
      }
      if (next.attributes?.level !== 2) {
        return { ok: false, why: `inserted heading is level ${next.attributes?.level}, expected 2` };
      }
      const text = (next.text_preview || '').trim();
      if (text !== "What's next?") {
        return { ok: false, why: `inserted heading text is "${text}", expected "What's next?"` };
      }
      // Tools must still appear after the inserted heading at the top level.
      const toolsIdx = blocks.findIndex((b) => b.name === 'core/group' && b.attributes?.metadata?.name === 'Tools');
      if (toolsIdx === -1) {
        return { ok: false, why: 'Tools group is missing — agent likely clobbered it' };
      }
      return { ok: true, why: 'H2 "What\'s next?" inserted between features and Tools' };
    },
  },

  'live-delete-h2': {
    label: '[live] Delete the H2 "Avoid costly maintenance work"',
    prompt: ({ POST_ID }) =>
      `On WordPress page ${POST_ID}, find the heading with the text "Avoid costly maintenance work" and delete just that one heading block. Don't delete anything around it — only the heading itself. Use the available MCP.`,
    validate(blocks) {
      let count = 0;
      const walk = (arr) => {
        for (const b of arr) {
          if (b.name === 'core/heading' && (b.text_preview || '').trim() === 'Avoid costly maintenance work') count++;
          if (b.innerBlocks) walk(b.innerBlocks);
        }
      };
      walk(blocks);
      if (count !== 0) return { ok: false, why: `"Avoid costly maintenance work" still present (found ${count})` };
      // All nine named groups should still be at the top level — easy
      // catch for over-deletion.
      const sections = blocks
        .filter((b) => b.name === 'core/group' && b.attributes?.metadata?.name)
        .map((b) => b.attributes.metadata.name);
      const expected = ['Header', 'Trusted by', 'features', 'Side to side', 'Perks', 'Tools', 'Testimonials', 'Case studies', 'FAQs'];
      const missing = expected.filter((n) => !sections.includes(n));
      if (missing.length) {
        return { ok: false, why: `top-level groups missing after delete: ${missing.join(', ')}` };
      }
      return { ok: true, why: 'heading deleted; all top-level sections intact' };
    },
  },
};

// ── Helpers ────────────────────────────────────────────────────────────────
function runWP(cmd) {
  if (LOCAL_WP_PATH) {
    return execSync(`wp --path="${LOCAL_WP_PATH}" ${cmd}`, { encoding: 'utf8' });
  }
  const ssh = `sshpass -p "${WP_LIVE_SSH_PASSWORD}" ssh -o StrictHostKeyChecking=no -p ${WP_LIVE_PORT} ${WP_LIVE_USER}@${WP_LIVE_HOST}`;
  return execSync(`${ssh} "cd ${WP_PATH} && wp ${cmd.replace(/"/g, '\\"')}"`, { encoding: 'utf8' });
}

// Which seed script to run. Default: seed-bench-page.php (synthetic). Override
// to seed-bench-page-live.php for the live-page bench dimension that uses a
// snapshot of a real published page. Anything else is an absolute path.
const SEED_SCRIPT = process.env.SEED_SCRIPT || 'seed-bench-page.php';

function reseedPage() {
  const seedPath = LOCAL_WP_PATH
    ? join(import.meta.dirname, SEED_SCRIPT)
    : `/tmp/${SEED_SCRIPT.split('/').pop()}`;
  const out = runWP(`eval-file ${seedPath} 2>&1 | grep -v Deprecated | tail -1`).trim();
  const id = parseInt(out, 10);
  if (!id) throw new Error(`reseed failed; got: ${out}`);
  return id;
}

function uploadSeedScript() {
  if (LOCAL_WP_PATH) return; // local — file is in scripts/ already, no upload needed
  const scp = `sshpass -p "${WP_LIVE_SSH_PASSWORD}" scp -o StrictHostKeyChecking=no -P ${WP_LIVE_PORT}`;
  const localScript = join(import.meta.dirname, SEED_SCRIPT);
  const remoteName  = SEED_SCRIPT.split('/').pop();
  execSync(`${scp} ${localScript} ${WP_LIVE_USER}@${WP_LIVE_HOST}:/tmp/${remoteName}`, { stdio: 'ignore' });
}

function resetRateLimit(postId) {
  // 2>/dev/null swallows the noisy "Transient was not deleted" warning
  // wp-cli emits when the transient doesn't exist yet (the common case
  // on a freshly seeded post).
  try { runWP(`transient delete gk_block_api_rate_${postId} 2>/dev/null`); } catch {}
}

// gk-block-api client for validation reads (independent of which MCP the agent used).
// rejectUnauthorized:false so local self-signed certs work too.
const gk = axios.create({
  baseURL: `${WP_BASE}/wp-json/gk-block-api/v1`,
  timeout: 30000,
  httpsAgent: new https.Agent({ rejectUnauthorized: false, keepAlive: true }),
  headers: { Authorization: `Basic ${Buffer.from(`${WP_USER}:${WP_PASS}`).toString('base64')}` },
});

async function readBlocks(postId) {
  const r = await gk.get(`/posts/${postId}/blocks`);
  return r.data.blocks;
}

function spawnClaude({ mcpConfigJsonPath, model, prompt }) {
  return new Promise((resolve) => {
    const start = process.hrtime.bigint();
    const args = [
      '--bare',
      '--print',
      '--output-format', 'json',
      '--no-session-persistence',
      // bypassPermissions so the agent can actually invoke the MCP tools
      // non-interactively. Without it, Claude Code prompts for each tool
      // call and stdin is closed in print mode → all tool calls get
      // implicitly denied.
      '--permission-mode', 'bypassPermissions',
      '--mcp-config', mcpConfigJsonPath,
      '--model', model,
      '--max-budget-usd', MAX_BUDGET_USD,
      '--append-system-prompt', 'You are running inside an automated benchmark. Use the MCP tools available to you to complete the task. Be direct: call the tools, then state the result in one sentence.',
      prompt,
    ];
    const child = spawn('claude', args, { stdio: ['ignore', 'pipe', 'pipe'] });

    let stdout = '', stderr = '';
    const timer = setTimeout(() => { child.kill('SIGTERM'); }, PER_CALL_TIMEOUT_MS);
    child.stdout.on('data', (d) => stdout += d.toString());
    child.stderr.on('data', (d) => stderr += d.toString());
    child.on('close', (code) => {
      clearTimeout(timer);
      const wall_clock_ms = Number(process.hrtime.bigint() - start) / 1e6;
      let parsed = null, error = null;
      try {
        parsed = JSON.parse(stdout);
      } catch (e) {
        error = `JSON parse failed: ${e.message}; first 500 chars stdout: ${stdout.slice(0, 500)}`;
      }
      resolve({ wall_clock_ms, code, parsed, stdout, stderr, error });
    });
  });
}

function summarizeResult(result) {
  // result.parsed is an array of stream events. Walk them to count tool calls
  // and pull the final result + cost.
  if (!result.parsed) return { tool_calls: 0, tool_names: [], duration_api_ms: null, cost_usd: 0, num_turns: 0, final: null };
  const tool_names = [];
  let final = null, duration_api_ms = null, cost_usd = 0, num_turns = 0;
  for (const ev of result.parsed) {
    if (ev.type === 'assistant' && ev.message?.content) {
      for (const c of ev.message.content) {
        if (c.type === 'tool_use') tool_names.push(c.name);
      }
    }
    if (ev.type === 'result') {
      final = ev.result;
      duration_api_ms = ev.duration_api_ms;
      cost_usd = ev.total_cost_usd;
      num_turns = ev.num_turns;
    }
  }
  return { tool_calls: tool_names.length, tool_names, duration_api_ms, cost_usd, num_turns, final };
}

// ── Main loop ──────────────────────────────────────────────────────────────
async function main() {
  const tmpDir = mkdtempSync(join(tmpdir(), 'mcp-agent-bench-'));
  process.stderr.write(`Workspace: ${tmpDir}\n`);

  // Stage MCP config files (claude --mcp-config wants paths).
  for (const [name, def] of Object.entries(MCP_CONFIGS)) {
    writeFileSync(join(tmpDir, `${name}.json`), JSON.stringify(def.config, null, 2));
  }

  uploadSeedScript();
  process.stderr.write(`Seeded scripts/seed-bench-page.php to remote\n`);

  const scenarios = Object.entries(SCENARIOS).filter(([k]) => SCENARIOS_FILTER.length === 0 || SCENARIOS_FILTER.includes(k));
  const mcps      = Object.entries(MCP_CONFIGS)
    .filter(([, def]) => !def.skip)
    .filter(([k]) => MCPS_FILTER.length === 0 || MCPS_FILTER.includes(k));
  const models    = MODELS_FILTER.length ? MODELS_FILTER : ['sonnet', 'haiku'];

  const total = scenarios.length * mcps.length * models.length * TRIALS;
  process.stderr.write(`\nRunning ${total} invocations: ${scenarios.length} scenarios × ${mcps.length} MCPs × ${models.length} models × ${TRIALS} trials\n\n`);

  const results = [];
  let totalCost = 0;
  let i = 0;

  for (const [scenarioKey, scenario] of scenarios) {
    for (const [mcpKey, mcpDef] of mcps) {
      for (const model of models) {
        for (let trial = 0; trial < TRIALS; trial++) {
          i++;
          process.stderr.write(`[${i}/${total}] ${scenarioKey} | ${mcpDef.label} | ${model} | trial ${trial + 1}\n`);

          // 1. Re-seed
          let postId;
          try { postId = reseedPage(); } catch (e) { process.stderr.write(`  reseed failed: ${e.message}\n`); continue; }
          resetRateLimit(postId);

          // 2. Invoke claude
          const prompt = scenario.prompt({ POST_ID: postId });
          const r = await spawnClaude({
            mcpConfigJsonPath: join(tmpDir, `${mcpKey}.json`),
            model,
            prompt,
          });

          // 3. Parse + cost-track
          const summary = summarizeResult(r);
          totalCost += summary.cost_usd;

          // 4. Validate page state independently
          let validation = { ok: false, why: 'not validated' };
          try {
            const blocks = await readBlocks(postId);
            validation = scenario.validate(blocks);
          } catch (e) {
            validation = { ok: false, why: `validation read failed: ${e.message}` };
          }

          process.stderr.write(`  → ${(r.wall_clock_ms / 1000).toFixed(1)}s · ${summary.tool_calls} tool calls · $${summary.cost_usd.toFixed(4)} · validation: ${validation.ok ? 'PASS' : `FAIL (${validation.why})`}\n`);

          if (FAIL_FAST_BLOCK_MCP && mcpKey === 'block-mcp' && !validation.ok) {
            process.stderr.write(
              `\nFAIL_FAST_BLOCK_MCP is set — Block MCP failed validation on scenario "${scenarioKey}". Aborting before burning the rest of the matrix on the other MCPs.\n`
              + `Total spent so far: $${totalCost.toFixed(2)}. Diagnose the scenario / Block MCP, then rerun.\n`,
            );
            process.exit(2);
          }

          results.push({
            scenario: scenarioKey,
            mcp: mcpKey,
            model,
            trial,
            post_id: postId,
            wall_clock_ms: r.wall_clock_ms,
            duration_api_ms: summary.duration_api_ms,
            tool_calls: summary.tool_calls,
            tool_names: summary.tool_names,
            num_turns: summary.num_turns,
            cost_usd: summary.cost_usd,
            validated: validation.ok,
            validation_why: validation.why,
            cli_error: r.error,
            agent_response: summary.final,
          });
        }
      }
    }
  }

  // ── Aggregate ────────────────────────────────────────────────────────────
  console.log('\n=== AGENT-LOOP BENCHMARK ===\n');
  console.log(`Total invocations: ${results.length} | Total cost: $${totalCost.toFixed(2)}\n`);

  // Group by (scenario, mcp, model) and average.
  const groups = {};
  for (const r of results) {
    const key = `${r.scenario}|${r.mcp}|${r.model}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(r);
  }

  for (const [scenarioKey, scenario] of scenarios) {
    console.log(`### ${scenario.label}\n`);
    console.log(`| MCP | Model | Avg time | Tool calls | Pass rate |`);
    console.log(`|---|---|---|---|---|`);
    for (const [mcpKey, mcpDef] of mcps) {
      for (const model of models) {
        const key = `${scenarioKey}|${mcpKey}|${model}`;
        const trials = groups[key] || [];
        if (trials.length === 0) continue;
        const avgTime = trials.reduce((s, t) => s + t.wall_clock_ms, 0) / trials.length / 1000;
        const avgCalls = trials.reduce((s, t) => s + t.tool_calls, 0) / trials.length;
        const passes = trials.filter((t) => t.validated).length;
        console.log(`| ${mcpDef.label} | ${model} | ${avgTime.toFixed(1)} s | ${avgCalls.toFixed(1)} | ${passes}/${trials.length} |`);
      }
    }
    console.log();
  }

  // Persist raw results for later inspection.
  const outPath = join(tmpDir, 'results.json');
  writeFileSync(outPath, JSON.stringify(results, null, 2));
  console.log(`Raw results: ${outPath}`);
}

main().catch((e) => { console.error('FAIL:', e); process.exit(1); });
