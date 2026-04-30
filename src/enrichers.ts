/**
 * Block enrichers — transform block attributes before they reach the REST API.
 *
 * An enricher receives a block definition and returns an updated one. The
 * registry is keyed by fully-qualified block name so each block type can have
 * its own enrichment logic.
 *
 * Built-in: kevinbatdorf/code-block-pro — derives codeHTML from code + language
 * so callers never have to hand-write syntax-highlighted HTML.
 *
 * Extension:
 *   import { registerBlockEnricher } from './enrichers.js';
 *   registerBlockEnricher('my-plugin/snippet', async (block) => { ... });
 */

import { createHighlighter, createCssVariablesTheme } from 'shiki';
import type { HighlighterGeneric, BundledLanguage, BundledTheme } from 'shiki';

// ── Types ─────────────────────────────────────────────────────────────────────

export type BlockDef = {
  name: string;
  attributes?: Record<string, unknown>;
  innerBlocks?: BlockDef[];
  innerHTML?: string;
};

/** Return the updated block, or null to leave it unchanged. */
export type EnricherFn = (block: BlockDef) => Promise<BlockDef | null>;

// ── Registry ──────────────────────────────────────────────────────────────────

const _registry = new Map<string, EnricherFn>();

export function registerBlockEnricher(blockName: string, fn: EnricherFn): void {
  _registry.set(blockName, fn);
}

/** Apply the registered enricher for the block's type, recursing into innerBlocks. */
export async function enrichBlock(block: BlockDef): Promise<BlockDef> {
  const fn = _registry.get(block.name);
  const enriched = fn ? (await fn(block)) ?? block : block;
  if (!enriched.innerBlocks?.length) return enriched;
  return { ...enriched, innerBlocks: await enrichBlocks(enriched.innerBlocks) };
}

/** Enrich an array of blocks (and their descendants) in parallel. */
export async function enrichBlocks(blocks: BlockDef[]): Promise<BlockDef[]> {
  return Promise.all(blocks.map(enrichBlock));
}

// ── Shiki singleton ───────────────────────────────────────────────────────────

const SUPPORTED_LANGS = [
  'php', 'javascript', 'typescript', 'css', 'html', 'json', 'xml',
  'sql', 'bash', 'shell', 'python', 'ruby', 'yaml', 'markdown',
  'ini', 'diff', 'dockerfile', 'nginx',
] as const;

let _hl: HighlighterGeneric<BundledLanguage, BundledTheme> | null = null;
let _hlLangs: Set<string> | null = null;

async function getHighlighter() {
  if (_hl) return { hl: _hl, langs: _hlLangs! };
  const theme = createCssVariablesTheme({ name: 'css-variables', variablePrefix: '--shiki-' });
  _hl = await createHighlighter({ themes: [theme], langs: [...SUPPORTED_LANGS] }) as HighlighterGeneric<BundledLanguage, BundledTheme>;
  _hlLangs = new Set(_hl.getLoadedLanguages());
  return { hl: _hl, langs: _hlLangs };
}

/**
 * Render code as a syntax-highlighted <pre> block using the css-variables
 * Shiki theme, with variable names normalised to match Code Block Pro's
 * expected --shiki-color-text / --shiki-color-background convention.
 *
 * @param themeName  Optional theme name to use as the <pre> class instead of
 *                   "css-variables". CBP custom themes (gravitykit-dark, etc.)
 *                   are registered as css-variables themes in the browser — the
 *                   class name must match so block validation passes.
 */
export async function shikiHighlight(code: string, language: string, themeName?: string): Promise<string> {
  const { hl, langs } = await getHighlighter();
  const lang = langs.has(language) ? language : 'plaintext';
  let html = hl.codeToHtml(code, { lang, theme: 'css-variables' })
    .replace(/var\(--shiki-foreground\)/g, 'var(--shiki-color-text)')
    .replace(/var\(--shiki-background\)/g, 'var(--shiki-color-background)');
  if (themeName && themeName !== 'css-variables') {
    html = html.replace(/(<pre[^>]*class="shiki) css-variables(")/, `$1 ${themeName}$2`);
  }
  return html;
}

// ── Language inference ────────────────────────────────────────────────────────

const _langRules: Array<{ testFn: (code: string) => boolean; language: string }> = [];

/** Add a custom language inference rule, checked before built-in heuristics. */
export function addLanguageRule(testFn: (code: string) => boolean, language: string): void {
  _langRules.push({ testFn, language });
}

export function inferLanguage(code: string): string {
  const head = code.slice(0, 4000);

  for (const { testFn, language } of _langRules) {
    if (testFn(head)) return language;
  }

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
  if (/[.#@][\w-]+\s*\{|:\s*[^;{}\n]+;|@media\s|@import\s/.test(head)) return 'css';
  if (/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN)\b/i.test(head) && /\b(FROM|WHERE)\b/i.test(head)) return 'sql';
  if (/^\s*(const|let|var|function|import|export|=>)\b/.test(head) || /console\.log\s*\(/.test(head)) return 'javascript';
  if (/^\s*\{[\s\S]*?"[\w-]+"\s*:/.test(head)) return 'json';
  if (/^\s*#!?\/bin\/(?:bash|sh)|^\s*\$\s+\w/.test(head)) return 'bash';

  return 'plaintext';
}

// ── Built-in: kevinbatdorf/code-block-pro ────────────────────────────────────

registerBlockEnricher('kevinbatdorf/code-block-pro', async (block) => {
  const attrs = block.attributes ?? {};
  const code = attrs.code as string | undefined;
  if (!code) return null;

  const rawLang = ((attrs.language as string) || 'plaintext').toLowerCase();
  const { langs } = await getHighlighter();

  const lang = (rawLang === 'plaintext' || rawLang === 'text' || rawLang === '')
    ? inferLanguage(code)
    : rawLang;
  const effectiveLang = langs.has(lang) ? lang : 'plaintext';

  const themeName = (attrs.theme as string | undefined) || undefined;
  const codeHTML = await shikiHighlight(code, effectiveLang, themeName);
  const highestLineNumber = code.split('\n').length;
  if (codeHTML === attrs.codeHTML && lang === rawLang) return null;

  const updatedAttrs = { ...attrs, language: lang, codeHTML, highestLineNumber };

  // CBP is dual-storage: codeHTML is embedded verbatim inside innerHTML. When
  // innerHTML is provided, replace the <pre class="shiki"> portion in-place and
  // update the copy-button <textarea> so both match the new code.
  let updatedInnerHTML = block.innerHTML;
  if (updatedInnerHTML) {
    updatedInnerHTML = updatedInnerHTML.replace(
      /<pre class="shiki[\s\S]*?<\/pre>/,
      codeHTML
    );
    updatedInnerHTML = updatedInnerHTML.replace(
      /(<textarea[^>]*>)([\s\S]*?)(<\/textarea>)/,
      (_m, open, _old, close) => `${open}${code}${close}`
    );
  }

  return {
    ...block,
    attributes: updatedAttrs,
    ...(updatedInnerHTML !== block.innerHTML ? { innerHTML: updatedInnerHTML } : {}),
  };
});
