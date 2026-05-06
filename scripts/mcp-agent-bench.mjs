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
};

// ── Helpers ────────────────────────────────────────────────────────────────
function runWP(cmd) {
  if (LOCAL_WP_PATH) {
    return execSync(`wp --path="${LOCAL_WP_PATH}" ${cmd}`, { encoding: 'utf8' });
  }
  const ssh = `sshpass -p "${WP_LIVE_SSH_PASSWORD}" ssh -o StrictHostKeyChecking=no -p ${WP_LIVE_PORT} ${WP_LIVE_USER}@${WP_LIVE_HOST}`;
  return execSync(`${ssh} "cd ${WP_PATH} && wp ${cmd.replace(/"/g, '\\"')}"`, { encoding: 'utf8' });
}

function reseedPage() {
  const seedPath = LOCAL_WP_PATH ? join(import.meta.dirname, 'seed-bench-page.php') : '/tmp/seed-bench-page.php';
  const out = runWP(`eval-file ${seedPath} 2>&1 | grep -v Deprecated | tail -1`).trim();
  const id = parseInt(out, 10);
  if (!id) throw new Error(`reseed failed; got: ${out}`);
  return id;
}

function uploadSeedScript() {
  if (LOCAL_WP_PATH) return; // local — file is in scripts/ already, no upload needed
  const scp = `sshpass -p "${WP_LIVE_SSH_PASSWORD}" scp -o StrictHostKeyChecking=no -P ${WP_LIVE_PORT}`;
  execSync(`${scp} ${join(import.meta.dirname, 'seed-bench-page.php')} ${WP_LIVE_USER}@${WP_LIVE_HOST}:/tmp/seed-bench-page.php`, { stdio: 'ignore' });
}

function resetRateLimit(postId) {
  try { runWP(`transient delete gk_block_api_rate_${postId}`); } catch {}
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
