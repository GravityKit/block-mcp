#!/usr/bin/env node
/**
 * Syntax-highlight Code Block Pro (and other) blocks using Shiki.
 *
 * Reads block lists via the block-mcp REST API; writes updates back via the
 * same API so every change lands as a proper WordPress revision.  WP-CLI (or
 * --post-ids) is only needed for *discovery* — finding which post IDs to scan.
 *
 * Usage:
 *   node scripts/highlight-code-blocks.mjs
 *   node scripts/highlight-code-blocks.mjs --post-id=12345
 *   node scripts/highlight-code-blocks.mjs --post-ids=1,2,3
 *   node scripts/highlight-code-blocks.mjs --post-ids-file=/tmp/ids.txt
 *   node scripts/highlight-code-blocks.mjs --dry-run
 *
 * Required env vars (same as the MCP server):
 *   WORDPRESS_URL            https://www.gravitykit.com
 *   WORDPRESS_USER           WordPress username
 *   WORDPRESS_APP_PASSWORD   Application Password
 *
 * Extension API — import this file and call before running:
 *   import { registerBlockHighlighter, addLanguageRule } from './highlight-code-blocks.mjs';
 *
 *   // Handle a custom block type
 *   registerBlockHighlighter('my-plugin/code', async (attrs, highlight) => {
 *     const html = await highlight(attrs.code, attrs.language);
 *     return { ...attrs, codeHTML: html };
 *   });
 *
 *   // Teach the language inferrer a new pattern
 *   addLanguageRule(code => /^\s*@tailwind\b/.test(code), 'css');
 */

import { createHighlighter, createCssVariablesTheme } from 'shiki';
import axios from 'axios';
import { readFileSync } from 'fs';
import { execSync } from 'child_process';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

// ── Extension registry ────────────────────────────────────────────────────────

/**
 * Map of block name → async handler(attrs, highlightFn) => updatedAttrs | null.
 * Return null to skip the block without error.
 */
const _blockHighlighters = new Map();

/**
 * Register a highlight handler for a block type.
 *
 * @param {string}   blockName  Fully-qualified block name, e.g. "my-plugin/code".
 * @param {Function} handler    async (attrs, highlight) => updatedAttrs | null
 *                              `highlight(code, language)` returns Shiki <pre> HTML.
 */
export function registerBlockHighlighter(blockName, handler) {
  _blockHighlighters.set(blockName, handler);
}

/**
 * Custom language inference rules checked before the built-in heuristics.
 * Add a rule to detect a language from source code content.
 *
 * @param {Function} testFn    (code: string) => boolean
 * @param {string}   language  Shiki language ID to use when testFn matches.
 */
export function addLanguageRule(testFn, language) {
  _languageRules.push({ testFn, language });
}

const _languageRules = [];

// ── Shiki setup ───────────────────────────────────────────────────────────────

// css-variables theme emits `color:var(--shiki-token-*)` on every token,
// matching what Code Block Pro's editor produces.  We post-process
// --shiki-foreground / --shiki-background → --shiki-color-text / --shiki-color-background
// because CBP (built on shiki 0.14) uses the older variable names.
const CSS_VARS_THEME = createCssVariablesTheme({ name: 'css-variables', variablePrefix: '--shiki-' });

console.log('Initializing Shiki…');
const highlighter = await createHighlighter({
  themes: [CSS_VARS_THEME],
  langs: [
    'php', 'javascript', 'typescript', 'css', 'html', 'json',
    'xml', 'sql', 'bash', 'shell', 'python', 'ruby', 'yaml',
    'markdown', 'ini', 'diff', 'dockerfile', 'nginx',
  ],
});

const SUPPORTED_LANGS = new Set(highlighter.getLoadedLanguages());

/**
 * Render code as syntax-highlighted <pre> HTML, compatible with Code Block Pro.
 * Falls back to plaintext when the language isn't supported.
 *
 * @param {string} code
 * @param {string} language  Shiki language ID or 'plaintext'.
 * @returns {string} <pre class="shiki css-variables" …>…</pre>
 */
function highlight(code, language) {
  const lang = SUPPORTED_LANGS.has(language) ? language : 'plaintext';
  let html = highlighter.codeToHtml(code, { lang, theme: 'css-variables' });
  // Normalise to the variable names CBP expects (shiki 0.14 naming).
  return html
    .replace(/var\(--shiki-foreground\)/g, 'var(--shiki-color-text)')
    .replace(/var\(--shiki-background\)/g, 'var(--shiki-color-background)');
}

// ── Language inference ────────────────────────────────────────────────────────

/**
 * Guess a language when the block attribute is 'plaintext' or empty.
 * User-registered rules run first; built-ins are conservative fallbacks.
 */
function inferLanguage(code) {
  const head = code.slice(0, 4000);

  // User-registered rules take priority.
  for (const { testFn, language } of _languageRules) {
    if (testFn(head)) return language;
  }

  // Built-in heuristics — ordered most-specific first.
  const phpSignals = [
    /<\?php\b/, /\?>/,
    /\bnamespace\s+[\w\\]+\s*;/, /\buse\s+[\w\\]+(?:\s+as\s+\w+)?\s*;/,
    /\b(?:public|private|protected)\s+(?:static\s+)?function\s+\w+/,
    /\bstatic\s+function\s+\w+/,
    /\bfunction\s+\w+\s*\([^)]*\)\s*\{/,
    /\b(?:do_action|apply_filters|add_action|add_filter|register_(?:post_type|taxonomy|setting|rest_route|block_type)|add_shortcode|wp_(?:enqueue|register)_(?:script|style)|get_(?:option|post_meta|user_meta)|update_(?:option|post_meta|user_meta))\s*\(/,
    /->\w+\s*\(/, /\(\s*(?:int|string|bool|float|array|object|self|static)\s*\)\s*\$\w+/,
    /\$\w+\s*=/, /\$\w+\s*->/, /\barray\s*\(/, /=>\s*['"]?\w/,
  ];
  if (phpSignals.reduce((n, re) => n + (re.test(head) ? 1 : 0), 0) >= 1) return 'php';
  if (/^<!DOCTYPE\s|<html[\s>]|<body[\s>]|<div\s[^>]*>|<p>[\s\S]*<\/p>/i.test(head)) return 'html';
  if (/<script\b|<style\b/i.test(head)) return 'html';
  if (/^\s*[.#@][\w-]+\s*\{|:\s*[-\w#]+\s*;|@media\s|@import\s/.test(head)) return 'css';
  if (/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN)\b/i.test(head) && /\b(FROM|WHERE)\b/i.test(head)) return 'sql';
  if (/^\s*(const|let|var|function|import|export|=>)\b/.test(head) || /console\.log\s*\(/.test(head)) return 'javascript';
  if (/^\s*\{[\s\S]*?"[\w-]+"\s*:/.test(head)) return 'json';
  if (/^\s*#!?\/bin\/(?:bash|sh)|^\s*\$\s+\w/.test(head)) return 'bash';

  return 'plaintext';
}

// ── Built-in: Code Block Pro handler ─────────────────────────────────────────

registerBlockHighlighter('kevinbatdorf/code-block-pro', async (attrs, highlightFn) => {
  const code = attrs.code;
  if (!code) return null;

  const raw = (attrs.language || 'plaintext').toLowerCase();
  const lang = raw === 'plaintext' || raw === 'text' || raw === ''
    ? inferLanguage(code)
    : raw;

  const codeHTML = highlightFn(code, SUPPORTED_LANGS.has(lang) ? lang : 'plaintext');

  if (codeHTML === attrs.codeHTML) return null; // no change

  return { language: lang, codeHTML };
});

// ── REST API client ───────────────────────────────────────────────────────────

const WORDPRESS_URL = process.env.WORDPRESS_URL || process.env.GK_SITE_URL;
const WORDPRESS_USER = process.env.WORDPRESS_USER || process.env.GK_BLOCK_API_USER;
const WORDPRESS_APP_PASSWORD = process.env.WORDPRESS_APP_PASSWORD || process.env.GK_BLOCK_API_APP_PASSWORD;

if (!WORDPRESS_URL || !WORDPRESS_USER || !WORDPRESS_APP_PASSWORD) {
  console.error('Missing required env vars: WORDPRESS_URL, WORDPRESS_USER, WORDPRESS_APP_PASSWORD');
  process.exit(1);
}

const api = axios.create({
  baseURL: `${WORDPRESS_URL.replace(/\/$/, '')}/wp-json/gk-block-api/v1`,
  auth: { username: WORDPRESS_USER, password: WORDPRESS_APP_PASSWORD },
  timeout: 30_000,
});

async function getBlocks(postId) {
  const { data } = await api.get(`/posts/${postId}/blocks`);
  return data.blocks ?? [];
}

async function updateBlock(postId, flatIndex, attributes) {
  await api.patch(`/posts/${postId}/blocks/${flatIndex}`, { attributes });
}

// ── WP-CLI (discovery only) ───────────────────────────────────────────────────

const __dirname = dirname(fileURLToPath(import.meta.url));
const gkcloneDir = process.env.GKCLONE_DIR ?? join(__dirname, '..', '..', 'Tooling', 'gkclone');
const wpCliSsh   = process.env.WP_CLI_SSH ?? '';

function wpExec(cmd, { input } = {}) {
  const full = wpCliSsh
    ? `${wpCliSsh} ${cmd}`
    : `npx wp-env run cli -- wp ${cmd}`;
  return execSync(full, {
    encoding: 'utf-8',
    maxBuffer: 50 * 1024 * 1024,
    cwd: wpCliSsh ? undefined : gkcloneDir,
    input,
    stdio: input !== undefined ? ['pipe', 'pipe', 'pipe'] : undefined,
    shell: wpCliSsh ? '/bin/bash' : undefined,
  }).split('\n')
    .filter(l => !l.startsWith('ℹ') && !l.startsWith('✔') && !l.startsWith('PHP Warning') && !l.startsWith('PHP Notice'))
    .join('\n')
    .trim();
}

function discoverPostIds(blockNames) {
  const likeList = blockNames.map(n => `post_content LIKE '%${n}%'`).join(' OR ');
  const php = `
global $wpdb;
$ids = $wpdb->get_col(
  "SELECT ID FROM {$wpdb->posts}
   WHERE (${likeList})
   AND post_type NOT IN ('revision','auto-draft','attachment','inherit')
   AND post_status IN ('publish','draft')"
);
echo implode(",", $ids);
`;
  const raw = wpExec('eval-file -', { input: php });
  return raw ? raw.split(',').filter(Boolean) : [];
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

const argv      = process.argv.slice(2);
const dryRun    = argv.includes('--dry-run');
const postIdArg = argv.find(a => a.startsWith('--post-id='));
const postIdsArg    = argv.find(a => a.startsWith('--post-ids='));
const postIdsFile   = argv.find(a => a.startsWith('--post-ids-file='));

let postIds;
if (postIdArg) {
  postIds = [postIdArg.split('=')[1]];
} else if (postIdsArg) {
  postIds = postIdsArg.split('=')[1].split(',').map(s => s.trim()).filter(Boolean);
} else if (postIdsFile) {
  postIds = readFileSync(postIdsFile.split('=')[1], 'utf8').split('\n').map(s => s.trim()).filter(Boolean);
} else {
  console.log('Discovering posts via WP-CLI…');
  const blockNames = [..._blockHighlighters.keys()];
  postIds = discoverPostIds(blockNames);
}

console.log(`Posts to process: ${postIds.length}`);

// ── Main loop ─────────────────────────────────────────────────────────────────

let updated = 0, skipped = 0, errors = 0;

for (const postId of postIds) {
  try {
    const blocks = await getBlocks(postId);
    let postChanges = 0;

    for (const block of blocks) {
      const handler = _blockHighlighters.get(block.name);
      if (!handler) continue;

      const updatedAttrs = await handler(block.attributes ?? {}, highlight);
      if (!updatedAttrs) continue;

      if (dryRun) {
        console.log(`  [DRY RUN] Post ${postId} block ${block.index} (${block.name}): language=${updatedAttrs.language ?? block.attributes?.language}`);
        postChanges++;
        continue;
      }

      await updateBlock(postId, block.index, updatedAttrs);
      console.log(`  Post ${postId} block ${block.index} (${block.name}): highlighted as ${updatedAttrs.language ?? '?'}`);
      postChanges++;
    }

    if (postChanges === 0) { skipped++; } else { updated++; }
  } catch (err) {
    console.error(`  Error on post ${postId}: ${err.message}`);
    errors++;
  }
}

console.log(`\nDone. Updated: ${updated}, Skipped: ${skipped}, Errors: ${errors}`);
highlighter.dispose();
