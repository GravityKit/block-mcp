#!/usr/bin/env node
/**
 * Single-trial diagnostic for any wp/v2-wrapping MCP. Spawns one `claude`
 * invocation with full tool-trace logging so you can see EXACTLY what the
 * agent called, what arguments it passed, and what came back.
 *
 * Useful for verifying a new MCP is wired correctly, or for proving (as we
 * did with InstaWP/mcp-wp) that a corruption isn't a config bug — it's the
 * actual round-trip behavior of the underlying API surface.
 *
 * Usage:
 *   POST_ID=<page-id> WP_BASE=<url> WP_USER=<u> WP_PASS=<app-password> \
 *     node scripts/dev/single-mcp-trial.mjs
 *
 * To target a different MCP, edit the `cfg` block below — set the command,
 * args, and env vars that MCP needs.
 */
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const POST_ID = parseInt(process.env.POST_ID, 10);
const tmp = mkdtempSync(join(tmpdir(), 'wp-mcp-trial-'));
const cfg = {
  mcpServers: {
    'wp-mcp': {
      command: 'npx',
      args: ['-y', '@instawp/mcp-wp'],
      env: {
        WORDPRESS_API_URL: process.env.WP_BASE,
        WORDPRESS_USERNAME: process.env.WP_USER,
        WORDPRESS_PASSWORD: process.env.WP_PASS,
      },
    },
  },
};
const cfgPath = join(tmp, 'wp-mcp.json');
writeFileSync(cfgPath, JSON.stringify(cfg, null, 2));

const prompt = `On WordPress page ${POST_ID}, find the H2 heading "Code samples" and change it to an H3. Use the available MCP to do it. Don't paraphrase — keep the heading text exactly the same, just change the level.`;

const child = spawn('claude', [
  '--bare',
  '--print',
  '--output-format', 'json',
  '--no-session-persistence',
  '--permission-mode', 'bypassPermissions',
  '--mcp-config', cfgPath,
  '--model', 'sonnet',
  '--max-budget-usd', '0.50',
  '--append-system-prompt', 'You are running inside an automated benchmark. Use the MCP tools available to you to complete the task. Be direct.',
  prompt,
], { stdio: ['ignore', 'pipe', 'pipe'] });

let stdout = '', stderr = '';
child.stdout.on('data', (d) => stdout += d.toString());
child.stderr.on('data', (d) => stderr += d.toString());
child.on('close', () => {
  console.log('STDERR:', stderr.slice(0, 1000));
  console.log('---');
  let parsed;
  try { parsed = JSON.parse(stdout); } catch { console.log('PARSE FAIL'); return; }
  for (const ev of parsed) {
    if (ev.type === 'assistant' && ev.message?.content) {
      for (const c of ev.message.content) {
        if (c.type === 'tool_use') {
          console.log(`TOOL_USE: ${c.name}`);
          // Show the input arguments — truncate big content fields.
          const args = JSON.parse(JSON.stringify(c.input));
          if (args.content && args.content.length > 200) args.content = args.content.slice(0, 200) + ` ... [+${args.content.length - 200} chars]`;
          console.log(`  args: ${JSON.stringify(args, null, 2).slice(0, 800)}`);
        } else if (c.type === 'text') {
          console.log(`ASSISTANT TEXT: ${c.text.slice(0, 300)}`);
        }
      }
    }
    if (ev.type === 'user' && ev.message?.content) {
      for (const c of ev.message.content) {
        if (c.type === 'tool_result') {
          const out = typeof c.content === 'string' ? c.content : JSON.stringify(c.content);
          console.log(`TOOL_RESULT (${out.length} chars): ${out.slice(0, 200)}${out.length > 200 ? '...' : ''}`);
        }
      }
    }
    if (ev.type === 'result') {
      console.log(`RESULT: ${ev.result?.slice(0, 400)}`);
    }
  }
});
