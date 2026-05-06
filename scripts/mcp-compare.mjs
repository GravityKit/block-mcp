#!/usr/bin/env node
/**
 * Live comparison: Block MCP vs the standard wp/v2 REST API (the surface that
 * REST-API-wrapping MCPs like InstaWP/mcp-wp expose). Same WordPress site,
 * same auth, same network, same target page — what differs is the API shape.
 *
 * Phases:
 *   1. Read one page  — 5 trials each, time + response size
 *   2. Update one heading-level — single edit, time + bytes uploaded
 *   3. Chain of 5 edits (1 read + 5 writes) — time + bytes uploaded
 *
 * Required env:
 *   WP_BASE      Site URL (e.g. https://example.com)
 *   WP_USER      WordPress username with edit_posts/edit_pages capability
 *   WP_PASS      Application Password for that user
 *   POST_ID      Post/page ID to benchmark against (must own the post or
 *                have edit_others_* caps; uses ?context=edit so the WP REST
 *                workflow gets raw block markup, not lossy rendered HTML)
 *
 * Optional env:
 *   TRIALS                 Number of full bench passes to average (default 1)
 *   RATE_LIMIT_RESET_CMD   Shell command to clear the per-post write rate limit
 *                          transient between trials. Without this, TRIALS > 1
 *                          will hit the plugin's 10 writes/min/post throttle
 *                          (each trial spends ~12 writes).
 *
 * Usage:
 *   WP_BASE=https://example.com WP_USER=admin WP_PASS=xxx POST_ID=123 \
 *     TRIALS=5 node scripts/mcp-compare.mjs
 */
import axios from 'axios';
import https from 'node:https';
import { execSync } from 'node:child_process';

const BASE = process.env.WP_BASE;
const POST_ID = parseInt(process.env.POST_ID || '0', 10);
const USER = process.env.WP_USER;
const PASS = process.env.WP_PASS;
const TRIALS = Math.max(1, parseInt(process.env.TRIALS || '1', 10));
const RATE_RESET = process.env.RATE_LIMIT_RESET_CMD;

if (!BASE || !USER || !PASS || !POST_ID) {
  console.error('Required env: WP_BASE, WP_USER, WP_PASS, POST_ID');
  process.exit(1);
}

const auth = Buffer.from(`${USER}:${PASS}`).toString('base64');
const baseHeaders = {
  Authorization: `Basic ${auth}`,
  Connection: 'keep-alive',
  'Content-Type': 'application/json',
};
const httpsAgent = new https.Agent({ rejectUnauthorized: false, keepAlive: true });
const wp = axios.create({ baseURL: `${BASE}/wp-json/wp/v2`,           timeout: 60000, httpsAgent, headers: baseHeaders });
const gk = axios.create({ baseURL: `${BASE}/wp-json/gk-block-api/v1`, timeout: 60000, httpsAgent, headers: baseHeaders });

const ns = () => process.hrtime.bigint();
const ms = (start) => Number(ns() - start) / 1e6;
const fmt = (n) => `${n.toFixed(0).padStart(4)} ms`;
const kb = (b) => `${(b / 1024).toFixed(1)} KB`;

function summarize(samples) {
  const sorted = [...samples].sort((a, b) => a - b);
  const mean = sorted.reduce((a, b) => a + b, 0) / sorted.length;
  return { mean, p50: sorted[Math.floor(sorted.length * 0.5)] };
}

async function timed(fn) {
  const t0 = ns();
  const out = await fn();
  return { ms: ms(t0), out };
}

// Walk the block tree and collect refs for the first N top-level core/heading blocks.
function collectHeadingRefs(blocksResp, n) {
  const out = [];
  function walk(arr) {
    for (const b of arr) {
      if (out.length >= n) return;
      if (b.name === 'core/heading' && b.path && b.path.length === 1 && b.ref) out.push(b.ref);
      if (b.innerBlocks) walk(b.innerBlocks);
    }
  }
  walk(blocksResp.blocks || []);
  return out;
}

function resetRateLimit() {
  if (!RATE_RESET) return;
  try { execSync(RATE_RESET, { stdio: 'ignore' }); } catch {}
}

async function runTrial() {
  // Phase 1: 5 reads each
  const wpReads = [], gkReads = [];
  let wpReadBytes = 0, gkReadBytes = 0, totalBlocks = 0;
  for (let i = 0; i < 5; i++) {
    const a = await timed(() => wp.get(`/pages/${POST_ID}?context=edit`));
    wpReads.push(a.ms);
    wpReadBytes = JSON.stringify(a.out.data).length;
    const b = await timed(() => gk.get(`/posts/${POST_ID}/blocks`));
    gkReads.push(b.ms);
    gkReadBytes = JSON.stringify(b.out.data).length;
    totalBlocks = b.out.data.summary.total_blocks;
  }

  const blocksResp = (await gk.get(`/posts/${POST_ID}/blocks`)).data;
  const refs = collectHeadingRefs(blocksResp, 5);
  if (refs.length < 5) throw new Error(`Need 5+ top-level headings; got ${refs.length}`);

  // Phase 2: single edit
  const wpFull = (await wp.get(`/pages/${POST_ID}?context=edit`)).data;
  const wpContent = wpFull.content.raw;
  if (!wpContent) throw new Error('No raw content (auth lacks edit cap?)');
  const newContent = wpContent.replace(
    /<h2 class="wp-block-heading">Code samples<\/h2>/,
    '<h3 class="wp-block-heading">Code samples</h3>'
  );
  const wpEditBytes = JSON.stringify({ content: newContent }).length;
  const wpSingleEdit = await timed(() => wp.post(`/pages/${POST_ID}`, { content: newContent }));

  const gkEditBody = { attributes: { level: 3 } };
  const gkEditBytes = JSON.stringify(gkEditBody).length;
  const gkSingleEdit = await timed(() =>
    gk.patch(`/posts/${POST_ID}/blocks/by-ref/${encodeURIComponent(refs[3])}`, gkEditBody)
  );

  resetRateLimit();

  // Phase 3: chain of 5 edits
  const t0wp = ns();
  let mut = (await wp.get(`/pages/${POST_ID}?context=edit`)).data.content.raw;
  let wpChainBytesUploaded = 0;
  for (let i = 0; i < 5; i++) {
    mut = mut.replace(/<h2 class="wp-block-heading">Conclusion<\/h2>/, '<h3 class="wp-block-heading">Conclusion</h3>');
    const body = JSON.stringify({ content: mut });
    wpChainBytesUploaded += body.length;
    await wp.post(`/pages/${POST_ID}`, { content: mut });
  }
  const wpChainMs = ms(t0wp);

  resetRateLimit();

  const t0gk = ns();
  await gk.get(`/posts/${POST_ID}/blocks`);
  let gkChainBytesUploaded = 0;
  for (let i = 0; i < 5; i++) {
    const body = JSON.stringify({ attributes: { level: (i % 4) + 2 } });
    gkChainBytesUploaded += body.length;
    await gk.patch(`/posts/${POST_ID}/blocks/by-ref/${encodeURIComponent(refs[i])}`, JSON.parse(body));
  }
  const gkChainMs = ms(t0gk);

  return {
    totalBlocks,
    wpReadMean: summarize(wpReads).mean, gkReadMean: summarize(gkReads).mean,
    wpReadBytes, gkReadBytes,
    wpSingle: wpSingleEdit.ms, gkSingle: gkSingleEdit.ms,
    wpEditBytes, gkEditBytes,
    wpChainMs, gkChainMs,
    wpChainBytesUploaded, gkChainBytesUploaded,
  };
}

async function main() {
  console.log(`\n=== Block MCP vs wp/v2 REST API live comparison ===`);
  console.log(`target: ${BASE}, page ${POST_ID}, trials: ${TRIALS}\n`);

  // Warm-up
  for (let i = 0; i < 3; i++) {
    await wp.get(`/pages/${POST_ID}?context=edit`).catch(() => {});
    await gk.get(`/posts/${POST_ID}/blocks`).catch(() => {});
  }

  const trials = [];
  for (let t = 0; t < TRIALS; t++) {
    if (t > 0) resetRateLimit();
    process.stderr.write(`Trial ${t + 1}/${TRIALS}...\n`);
    trials.push(await runTrial());
  }

  // Average across trials.
  const avg = (key) => trials.reduce((s, t) => s + t[key], 0) / trials.length;
  const stdev = (key) => {
    const m = avg(key);
    const v = trials.reduce((s, t) => s + (t[key] - m) ** 2, 0) / trials.length;
    return Math.sqrt(v);
  };
  const totalBlocks = trials[0].totalBlocks;
  const fmtMS = (k) => TRIALS > 1 ? `${avg(k).toFixed(0).padStart(4)} ± ${stdev(k).toFixed(0)} ms` : fmt(avg(k));

  console.log(`\nPHASE 1: Read one page (5 reads × ${TRIALS} trials = ${5 * TRIALS} samples)\n`);
  console.log(`                              time              response`);
  console.log(`  wp/v2 (?context=edit):     ${fmtMS('wpReadMean').padEnd(18)}  ${kb(avg('wpReadBytes')).padStart(8)}   raw HTML + Yoast schema + ACF + links`);
  console.log(`  Block MCP:                 ${fmtMS('gkReadMean').padEnd(18)}  ${kb(avg('gkReadBytes')).padStart(8)}   ${totalBlocks} structured blocks with refs`);

  console.log(`\nPHASE 2: One heading-level change (${TRIALS} trial${TRIALS > 1 ? 's' : ''})\n`);
  console.log(`                              time              uploaded`);
  console.log(`  wp/v2 workflow:            ${fmtMS('wpSingle').padEnd(18)}  ${kb(avg('wpEditBytes')).padStart(8)}   (whole post body re-sent)`);
  console.log(`  Block MCP workflow:        ${fmtMS('gkSingle').padEnd(18)}  ${kb(avg('gkEditBytes')).padStart(8)}   (single-block PATCH)`);

  console.log(`\nPHASE 3: Chain of 5 edits, 1 read + 5 writes (${TRIALS} trial${TRIALS > 1 ? 's' : ''})\n`);
  console.log(`                              total time        total uploaded`);
  console.log(`  wp/v2 workflow:            ${fmtMS('wpChainMs').padEnd(18)}  ${kb(avg('wpChainBytesUploaded')).padStart(8)}`);
  console.log(`  Block MCP workflow:        ${fmtMS('gkChainMs').padEnd(18)}  ${kb(avg('gkChainBytesUploaded')).padStart(8)}`);
  console.log(`  bytes uploaded: ${(avg('wpChainBytesUploaded') / avg('gkChainBytesUploaded')).toFixed(0)}× less with Block MCP`);
  console.log();
}

main().catch((e) => {
  console.error('FAIL:', e?.response?.status, JSON.stringify(e?.response?.data) || e.message);
  process.exit(1);
});
