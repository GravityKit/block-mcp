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

// Fine-grained Shiki: importing from 'shiki' inlines the full bundle (~200
// grammars + the oniguruma WASM, ~11 MB). We only highlight the curated set
// below, so we pull Shiki's core, the WASM-free JavaScript regex engine, and
// just those grammars statically — bundling only what we use keeps dist/ small
// and self-contained (the same bundle ships in the .mcpb, which has no node_modules).
import { createHighlighterCore, createCssVariablesTheme } from 'shiki/core';
import type { HighlighterCore } from 'shiki/core';
import { createJavaScriptRegexEngine } from '@shikijs/engine-javascript';
import lang_php from '@shikijs/langs/php';
import lang_javascript from '@shikijs/langs/javascript';
import lang_typescript from '@shikijs/langs/typescript';
import lang_css from '@shikijs/langs/css';
import lang_html from '@shikijs/langs/html';
import lang_json from '@shikijs/langs/json';
import lang_xml from '@shikijs/langs/xml';
import lang_sql from '@shikijs/langs/sql';
import lang_bash from '@shikijs/langs/bash';
import lang_shell from '@shikijs/langs/shell';
import lang_python from '@shikijs/langs/python';
import lang_ruby from '@shikijs/langs/ruby';
import lang_yaml from '@shikijs/langs/yaml';
import lang_markdown from '@shikijs/langs/markdown';
import lang_ini from '@shikijs/langs/ini';
import lang_diff from '@shikijs/langs/diff';
import lang_dockerfile from '@shikijs/langs/dockerfile';
import lang_nginx from '@shikijs/langs/nginx';

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

// Concurrency cap for enrichBlocks. Most enrichers (notably Shiki
// highlighting) are CPU-bound; an unbounded Promise.all on a code-heavy
// post can fan out 30+ simultaneous highlight calls and stall the event
// loop. 8 is a reasonable default; tunable via BLOCK_MCP_ENRICH_CONCURRENCY
// for sites that want different trade-offs (1 = serial; 32+ = old behavior).
const ENRICH_CONCURRENCY: number = (() => {
  const raw = parseInt(process.env.BLOCK_MCP_ENRICH_CONCURRENCY ?? '', 10);
  return Number.isFinite(raw) && raw >= 1 ? raw : 8;
})();

/**
 * Run an async mapper over an array with a concurrency cap. Preserves input
 * order in the output. No external dep — we ship one place that needs this.
 */
async function mapWithLimit<T, R>(
  items: T[],
  limit: number,
  mapper: (item: T, index: number) => Promise<R>,
): Promise<R[]> {
  if (limit >= items.length || limit <= 0) {
    return Promise.all(items.map((item, i) => mapper(item, i)));
  }
  const out: R[] = new Array(items.length);
  let next = 0;
  async function worker() {
    while (true) {
      const i = next++;
      if (i >= items.length) return;
      out[i] = await mapper(items[i], i);
    }
  }
  const workers = Array.from({ length: limit }, () => worker());
  await Promise.all(workers);
  return out;
}

/** Enrich an array of blocks (and their descendants) with bounded concurrency. */
export async function enrichBlocks(blocks: BlockDef[]): Promise<BlockDef[]> {
  return mapWithLimit(blocks, ENRICH_CONCURRENCY, enrichBlock);
}

// ── Shiki singleton ───────────────────────────────────────────────────────────

// The curated Shiki grammars bundled into the highlighter — only these are
// inlined (see the static imports at the top of the file). Loading a grammar
// also pulls in its embedded sub-grammars (e.g. php → html/css/js/sql), so the
// effective coverage is wider than the list. Any language not covered here
// falls back to plaintext in shikiHighlight(). Adding a language is a two-line
// change: `import lang_x from '@shikijs/langs/x'` above, then add it here.
const SHIKI_LANGS = [
  lang_php,
  lang_javascript,
  lang_typescript,
  lang_css,
  lang_html,
  lang_json,
  lang_xml,
  lang_sql,
  lang_bash,
  lang_shell,
  lang_python,
  lang_ruby,
  lang_yaml,
  lang_markdown,
  lang_ini,
  lang_diff,
  lang_dockerfile,
  lang_nginx,
];

// Singleton promise (not bare references): on a page with N code blocks,
// enrichBlocks() fires N concurrent calls into Promise.all. The original
// `if (_hl) return { hl, langs: _hlLangs! }` had a race where caller A
// finished setting `_hl` but had not yet set `_hlLangs` when caller B
// passed the guard, blowing up the non-null assertion downstream.
// A single in-flight promise dedupes the work and serialises field reads.
let _hlPromise: Promise<{
  hl: HighlighterCore;
  langs: Set<string>;
}> | null = null;

async function getHighlighter() {
  if (_hlPromise) return _hlPromise;
  _hlPromise = (async () => {
    const theme = createCssVariablesTheme({ name: 'css-variables', variablePrefix: '--shiki-' });
    const hl = await createHighlighterCore({
      themes: [theme],
      langs: SHIKI_LANGS,
      engine: createJavaScriptRegexEngine({ forgiving: true }),
    });
    return { hl, langs: new Set(hl.getLoadedLanguages()) };
  })();
  return _hlPromise;
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
    // Replacer function so a `$` in themeName is not read as a replacement token.
    html = html.replace(/(<pre[^>]*class="shiki) css-variables(")/, (_m, p1, p2) => `${p1} ${themeName}${p2}`);
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

  // The `=>\s*['"]?\w/` signal was here previously and matched JS arrow
  // functions returning strings (e.g. `(x) => "hello"`), causing JS code
  // to be misclassified as PHP. Each remaining signal is individually
  // meaningful — `$var =` and `$var->` aren't legitimate JS patterns —
  // so the threshold stays at 1.
  const phpSignals = [
    /<\?php\b/, /\?>/,
    /\bnamespace\s+[\w\\]+\s*;/, /\buse\s+[\w\\]+(?:\s+as\s+\w+)?\s*;/,
    /\b(?:public|private|protected)\s+(?:static\s+)?function\s+\w+/,
    /\bstatic\s+function\s+\w+/,
    /\bfunction\s+\w+\s*\([^)]*\)\s*\{/,
    /\b(?:do_action|apply_filters|add_action|add_filter|register_(?:post_type|taxonomy|setting|rest_route|block_type)|add_shortcode|wp_(?:enqueue|register)_(?:script|style)|get_(?:option|post_meta|user_meta)|update_(?:option|post_meta|user_meta))\s*\(/,
    /->\w+\s*\(/, /\(\s*(?:int|string|bool|float|array|object|self|static)\s*\)\s*\$\w+/,
    /\$\w+\s*=/, /\$\w+\s*->/, /\barray\s*\(/,
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

/**
 * Encode a string for safe use inside an HTML attribute value (double-quoted
 * context: `class="…"`, `style="…"`). Covers the five characters that change
 * meaning inside quoted attributes — `&`, `<`, `>`, `"`, `'` — by mapping each
 * to its named or numeric entity.
 *
 * Callers MUST quote the resulting value with `"` (not single quotes) — the
 * apostrophe escape uses `&#39;` rather than `&apos;` for older-browser
 * compatibility, and the rest assume the surrounding quote is `"`.
 */
function escapeAttr(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Guarantee a CSS generic-family fallback on a font-family value.
 *
 * A custom CBP font-name like `Code-Pro-JetBrains-Mono` is not a loaded
 * webfont, so `font-family:Code-Pro-JetBrains-Mono` alone makes browsers fall
 * back to the default serif. The real CBP editor bakes a full monospace stack;
 * mirror it here. A value that already ends in a generic family has a usable
 * fallback and is returned unchanged, which also makes re-runs idempotent.
 *
 * The generic family must be a whole comma-separated entry. A substring test
 * reads `Source Serif 4` or `custom-monospace-font` as generic and skips the
 * fallback those names most need.
 */
const GENERIC_FONT_FAMILIES = new Set([
  'monospace',
  'ui-monospace',
  'sans-serif',
  'serif',
  'system-ui',
  'cursive',
  'fantasy',
]);

function ensureMonospaceFallback(fontFamily: string): string {
  const entries = fontFamily
    .split(',')
    .map((entry) => entry.trim().replace(/^["']|["']$/g, '').toLowerCase());
  const hasGenericFamily = entries.some((entry) => GENERIC_FONT_FAMILIES.has(entry));
  if (hasGenericFamily) return fontFamily;
  return `${fontFamily},ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace`;
}

/**
 * Whether a caller-supplied font-family can be interpolated into a style
 * attribute.
 *
 * `escapeAttr` stops a value from breaking out of the attribute, but a
 * font-family is spliced in among other declarations, so an unescaped `;`, `{`
 * or `}` would still append CSS of the caller's choosing. None of those, nor
 * the parens a `url()` needs, appear in a real font-family value.
 */
function isUsableFontFamily(fontFamily: unknown): fontFamily is string {
  if (typeof fontFamily !== 'string') return false;
  const trimmed = fontFamily.trim();
  if (trimmed === '') return false;
  return !/[;{}<>()\\]/.test(trimmed);
}

/**
 * Hold the CBP wrapper's font-family to the block's attributes, with a generic
 * family always present.
 *
 * The in-place branch below rewrites only the <pre> and the copy <textarea>, so
 * without this the wrapper keeps whatever font-family it was serialized with:
 * a fontFamily attribute change never reaches the rendered markup, and a stack
 * carrying no generic family (a bare `Code-Pro-JetBrains-Mono`) falls back to
 * the browser default serif whenever that webfont is unavailable.
 *
 * Only the font-family declaration is rewritten. CBP's save() output packs CSS
 * custom properties (--cbp-*, --shiki-*) into the same style attribute, so
 * rebuilding that attribute wholesale would discard them.
 */
function syncWrapperFontFamily(innerHTML: string, fontFamily: unknown): string {
  const openTagPattern = /<div class="wp-block-kevinbatdorf-code-block-pro[^>]*>/;
  const match = innerHTML.match(openTagPattern);
  if (!match) return innerHTML;

  let tag = match[0];
  const declared = tag.match(/font-family:([^;"]*)/);

  // The attribute wins when set. Otherwise reuse what the wrapper already
  // declares — that value is HTML-encoded in the markup and must not be
  // encoded a second time, or a quoted font name becomes `&amp;quot;`.
  const attrFont = isUsableFontFamily(fontFamily) ? fontFamily : null;
  const nextFont = attrFont !== null
    ? escapeAttr(ensureMonospaceFallback(attrFont))
    : (declared ? ensureMonospaceFallback(declared[1]) : null);
  if (nextFont === null) return innerHTML;

  // Replacer functions throughout: a font stack is arbitrary text and a
  // `$&` / `$'` sequence in it would otherwise be read as a replacement pattern.
  if (declared) {
    tag = tag.replace(/font-family:[^;"]*/, () => `font-family:${nextFont}`);
  } else if (/\sstyle="/.test(tag)) {
    tag = tag.replace(/\sstyle="/, () => ` style="font-family:${nextFont};`);
  } else {
    tag = tag.replace(/>$/, () => ` style="font-family:${nextFont}">`);
  }

  // CBP's front-end script reads this attribute to decide which webfont to load.
  tag = tag.replace(
    /data-code-block-pro-font-family="[^"]*"/,
    () => `data-code-block-pro-font-family="${nextFont}"`,
  );

  return innerHTML.replace(openTagPattern, () => tag);
}

registerBlockEnricher('kevinbatdorf/code-block-pro', async (block) => {
  const attrs = block.attributes ?? {};
  const code = attrs.code as string | undefined;
  if (!code) return null;

  // Three caller intents on `language`:
  //   • Missing / '' / 'auto'      → run inferLanguage()
  //   • 'plaintext' (and aliases)  → render as plaintext, NO inference
  //   • Any other string           → use it verbatim (caller knows the language)
  //
  // Earlier the enricher collapsed missing + 'plaintext' into "infer", which
  // surprised callers passing 'plaintext' for prose. A chat prompt with the
  // word "from" appearing twice tripped the SQL signal in inferLanguage() and
  // rendered as syntax-highlighted SQL.
  const rawLangAttr = typeof attrs.language === 'string' ? (attrs.language as string).trim() : '';
  const rawLang = rawLangAttr.toLowerCase();
  const PLAINTEXT_ALIASES = new Set(['plaintext', 'text', 'plain', 'txt', 'none']);
  const shouldInfer = rawLang === '' || rawLang === 'auto';
  const lang = shouldInfer
    ? inferLanguage(code)
    : (PLAINTEXT_ALIASES.has(rawLang) ? 'plaintext' : rawLang);

  const { langs } = await getHighlighter();
  const effectiveLang = langs.has(lang) ? lang : 'plaintext';

  const themeName = (attrs.theme as string | undefined) || undefined;
  const codeHTML = await shikiHighlight(code, effectiveLang, themeName);
  const highestLineNumber = code.split('\n').length;
  const incomingInnerHTML = block.innerHTML ?? '';
  const updatedAttrs = { ...attrs, language: lang, codeHTML, highestLineNumber };

  // Bail out only when nothing meaningful has changed AND innerHTML is already
  // populated. An empty incomingInnerHTML always falls through so the wrapper
  // gets built below, even if codeHTML matches a previously-stored attribute.
  //
  // A fontFamily-only edit arrives with identical codeHTML and language, so the
  // wrapper sync must be attempted before giving up — otherwise the attribute
  // saves and the rendered markup keeps the old font indefinitely.
  if (codeHTML === attrs.codeHTML && lang === rawLang && incomingInnerHTML !== '') {
    const syncedInnerHTML = syncWrapperFontFamily(incomingInnerHTML, attrs.fontFamily);
    if (syncedInnerHTML === incomingInnerHTML) return null;
    return { ...block, attributes: updatedAttrs, innerHTML: syncedInnerHTML };
  }

  // Encode `&`, `<`, `>` before injecting raw source code into the
  // copy-button <textarea>'s text content. A literal `</textarea>` in the
  // source would otherwise close the element early and corrupt innerHTML.
  const encodedCode = code
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  // CBP is dual-storage: codeHTML lives both as an attribute (for the editor)
  // and inside innerHTML (the rendered widget served on the front-end).
  //   • innerHTML already exists → replace the <pre class="shiki"> portion
  //     in-place and re-sync the copy-button <textarea> contents.
  //   • innerHTML is empty       → build a minimal wrapper from scratch so the
  //     block actually renders. Without this branch, a CBP block inserted via
  //     the API saves the codeHTML attribute but emits no visible HTML — the
  //     post renders a blank gap where the code should be.
  let updatedInnerHTML: string;
  if (incomingInnerHTML !== '') {
    // Replacer function, not a string: codeHTML is arbitrary content and a
    // `$`-sequence in it ($$, $&, $`) would otherwise be interpreted as a
    // String.replace pattern and corrupt the rendered code.
    updatedInnerHTML = incomingInnerHTML.replace(
      /<pre class="shiki[\s\S]*?<\/pre>/,
      () => codeHTML,
    );
    updatedInnerHTML = updatedInnerHTML.replace(
      /(<textarea[^>]*>)([\s\S]*?)(<\/textarea>)/,
      (_m, open, _old, close) => `${open}${encodedCode}${close}`,
    );
    updatedInnerHTML = syncWrapperFontFamily(updatedInnerHTML, attrs.fontFamily);
  } else {
    // Mirror CBP's save() inline style attribute. Without these the wrapper
    // falls back to theme defaults and the code uses the surrounding font /
    // colour, breaking visual parity with editor-created blocks.
    //
    // Every value is HTML-encoded before interpolation. Caller-supplied
    // attributes can contain quotes, angle brackets, or ampersands either by
    // accident (a font-name like `"Helvetica Neue"`) or by intent (an
    // attacker-controlled className that breaks out of the attribute with
    // `foo" onclick="…`). The encoder collapses all five
    // attribute-significant characters to entities.
    const styleParts: string[] = [];
    if (isUsableFontFamily(attrs.fontFamily)) styleParts.push(`font-family:${escapeAttr(ensureMonospaceFallback(attrs.fontFamily))}`);
    if (typeof attrs.fontSize === 'string') styleParts.push(`font-size:${escapeAttr(attrs.fontSize)}`);
    if (typeof attrs.lineHeight === 'string') styleParts.push(`line-height:${escapeAttr(attrs.lineHeight)}`);
    if (typeof attrs.bgColor === 'string') styleParts.push(`background-color:${escapeAttr(attrs.bgColor)}`);
    if (typeof attrs.textColor === 'string') styleParts.push(`color:${escapeAttr(attrs.textColor)}`);
    const styleAttr = styleParts.length ? ` style="${styleParts.join(';')}"` : '';
    const classNameExtra = typeof attrs.className === 'string' && (attrs.className as string).trim() !== ''
      ? ` ${escapeAttr((attrs.className as string).trim())}`
      : '';
    const copyTextarea = attrs.copyButton
      ? `<textarea style="display:none" aria-hidden="true">${encodedCode}</textarea>`
      : '';
    updatedInnerHTML = `<div class="wp-block-kevinbatdorf-code-block-pro${classNameExtra}"${styleAttr}>${codeHTML}${copyTextarea}</div>`;
  }

  return {
    ...block,
    attributes: updatedAttrs,
    innerHTML: updatedInnerHTML,
  };
});
