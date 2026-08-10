/**
 * Unit tests for the Code Block Pro enricher (enrichBlock / enrichBlocks).
 *
 * The enricher is the one piece of non-trivial logic in the enrichers module:
 * it generates codeHTML via shiki, infers languages, and updates innerHTML.
 * All tests run against real shiki (no mocking) because the output format
 * is stable and the runtime cost is acceptable for a unit suite.
 *
 * This file focuses on the CBP enricher. The registerBlockEnricher extension
 * point is tested here too (it's part of the same module surface).
 */

import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import {
  inferLanguage,
  enrichBlock,
  enrichBlocks,
  registerBlockEnricher,
  shikiHighlight,
  type BlockDef,
} from '../../../enrichers.js';

// Shiki's first getHighlighter() call loads every grammar + theme and can take
// several seconds — especially on a loaded CI box. getHighlighter() memoises a
// single module-level promise, so only the FIRST highlight pays that cost; the
// rest reuse it. Warm it once here, with a generous hook timeout, so no
// individual test inherits the cold-start latency and trips the default 5s
// per-test timeout (the cause of an intermittent "generates codeHTML" flake).
beforeAll(async () => {
  await shikiHighlight('// warm', 'javascript');
}, 30_000);

// ── inferLanguage ─────────────────────────────────────────────────────────────

describe('inferLanguage', () => {
  it.each([
    ['$var = "hello";',              'php'],
    ['function myFunc() {}',         'php'],
    ['.container { display: flex; }', 'css'],
    ['const x = 1;',                 'javascript'],
    ['let y = [];',                  'javascript'],
    ['#!/bin/bash\necho hi',         'bash'],
    ['{"key": "value"}',             'json'],
    ['hello world plain text',       'plaintext'],
  ])('infers %s → %s', (code, expected) => {
    expect(inferLanguage(code)).toBe(expected);
  });

  it('returns plaintext for empty string', () => {
    expect(inferLanguage('')).toBe('plaintext');
  });
});

// ── Non-CBP pass-through ──────────────────────────────────────────────────────

describe('enrichBlock — non-CBP pass-through', () => {
  it('returns original block reference unchanged for core/paragraph', async () => {
    const block: BlockDef = { name: 'core/paragraph', attributes: { content: 'Hello' } };
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });

  it('returns original block for any block without a registered enricher', async () => {
    const block: BlockDef = { name: 'custom/unknown-widget', attributes: {} };
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });

  it('returns original block when CBP block has no code attribute', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { language: 'php' },
    };
    const result = await enrichBlock(block);
    expect(result).toEqual(block);
  });
});

// ── CBP enrichment — happy path ───────────────────────────────────────────────

describe('enrichBlock — Code Block Pro', () => {
  it('generates codeHTML attribute', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const x = 1;', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.codeHTML).toBeDefined();
    expect(result.attributes?.codeHTML as string).toContain('<pre class="shiki');
  });

  it('sets highestLineNumber to line count', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'line1\nline2\nline3', language: 'plaintext' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.highestLineNumber).toBe(3);
  });

  it('single-line code has highestLineNumber = 1', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'echo "hi";', language: 'php' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.highestLineNumber).toBe(1);
  });

  /**
   * Fresh CBP blocks created via the API (e.g. edit_block_tree replace-block)
   * arrive with no innerHTML. Pre-fix, the enricher only updated codeHTML and
   * left innerHTML empty, which made the block render as a blank gap on the
   * front-end. The enricher must build a minimal wrapper so the block is
   * actually visible after save.
   */
  it('builds wrapper innerHTML when block has none', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(typeof result.innerHTML).toBe('string');
    expect(result.innerHTML).toContain('wp-block-kevinbatdorf-code-block-pro');
    expect(result.innerHTML).toContain('<pre class="shiki');
  });

  it('inlines wrapper style from font / colour attributes', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontFamily: 'Code-Pro-JetBrains-Mono',
        fontSize: '1rem',
        lineHeight: '1.25rem',
        bgColor: '#0F2B62',
        textColor: '#d8dee9ff',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain('font-family:Code-Pro-JetBrains-Mono');
    expect(result.innerHTML).toContain('font-size:1rem');
    expect(result.innerHTML).toContain('background-color:#0F2B62');
    expect(result.innerHTML).toContain('color:#d8dee9ff');
  });

  /**
   * A custom fontFamily value (a CBP font-name like `Code-Pro-JetBrains-Mono`)
   * is not a loaded webfont, so a bare `font-family:Code-Pro-JetBrains-Mono`
   * makes browsers fall back to the default serif. The real CBP editor bakes a
   * full monospace stack; the enricher must append the same generic fallback so
   * a custom name still renders as monospace.
   */
  it('appends a monospace fallback stack to a custom fontFamily', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontFamily: 'Code-Pro-JetBrains-Mono',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain(
      'font-family:Code-Pro-JetBrains-Mono,ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
    );
  });

  /**
   * Idempotency: a fontFamily that already ends in a generic family keyword
   * (`Menlo,monospace`) is emitted UNCHANGED — no appended stack, no doubled
   * `monospace`. Re-running the enricher must never keep growing the value.
   */
  it('leaves a fontFamily that already ends in a generic family unchanged', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontFamily: 'Menlo,monospace',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain('font-family:Menlo,monospace');
    // No appended stack, and monospace is not doubled.
    expect(result.innerHTML).not.toContain('Menlo,monospace,ui-monospace');
    expect(result.innerHTML).not.toContain('monospace,monospace');
  });

  /**
   * A value that is itself a generic family (`ui-monospace`) already provides a
   * monospace fallback, so it is left unchanged.
   */
  it('leaves a bare generic-family fontFamily unchanged', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontFamily: 'ui-monospace',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain('font-family:ui-monospace');
    expect(result.innerHTML).not.toContain('ui-monospace,ui-monospace');
    expect(result.innerHTML).not.toContain('ui-monospace,SFMono-Regular');
  });

  it('includes copy-textarea when copyButton is enabled', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript', copyButton: true },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toMatch(/<textarea[^>]*>const a = 1;<\/textarea>/);
  });

  it('omits copy-textarea when copyButton is false', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript', copyButton: false },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).not.toContain('<textarea');
  });

  /**
   * Regression: a literal `</textarea>` in the source code would otherwise
   * close the copy-button <textarea> early and corrupt the wrapper. The
   * enricher must HTML-encode `<` (and `&`, `>`) in the textarea content so
   * the closing tag in the code is rendered as inert text, not parsed as a
   * tag boundary.
   */
  it('escapes closing textarea in code payload', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: "const x = '</textarea>';", language: 'javascript', copyButton: true },
    };
    const result = await enrichBlock(block);
    // Isolate just the copy-button <textarea> contents — the codeHTML <pre>
    // above it legitimately escapes its own markup separately.
    const match = result.innerHTML!.match(/<textarea[^>]*>([\s\S]*?)<\/textarea>/);
    expect(match).not.toBeNull();
    const textareaContent = match![1];
    expect(textareaContent).not.toContain('</textarea>');
    expect(textareaContent).toContain('&lt;/textarea&gt;');
  });

  /**
   * Regression: when the enricher replaces an existing <pre class="shiki"> in
   * innerHTML, the highlighted codeHTML was passed as the String.replace
   * REPLACEMENT string, so a `$`-sequence in the source ($$ -> $, $& -> the
   * whole matched <pre>) was interpreted as a replacement pattern and corrupted
   * the rendered code. The replacement must be literal.
   */
  it('preserves $-sequences in code when replacing existing innerHTML', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'A $$MONEY$$ B $& C', language: 'plaintext', copyButton: false },
      innerHTML: '<div class="wp-block-kevinbatdorf-code-block-pro"><pre class="shiki css-variables"><code>OLD</code></pre></div>',
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain('$$MONEY$$');
    // $& would otherwise splice the whole old <pre> back in; the old content
    // must be gone and the literal $& (shiki escapes the ampersand) preserved.
    expect(result.innerHTML).not.toContain('OLD');
    expect(result.innerHTML).toContain('$&#x26;');
  });

  /**
   * Wrapper-style values come straight from caller-supplied attributes. A
   * value containing `"` (whether malicious or just a quoted font-name like
   * `"Helvetica Neue"`) used to break out of the style="" attribute and
   * either corrupt the markup or inject active content. The enricher now
   * HTML-encodes attribute values before interpolation.
   */
  it('escapes double-quotes in style attribute values', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontFamily: 'Arial" onerror="alert',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).not.toContain('onerror="alert');
    expect(result.innerHTML).toContain('&quot;');
  });

  it('escapes special characters in className', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        className: 'safe" onclick="alert(1)',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).not.toContain('onclick="alert(1)');
    // The literal double-quote inside the className value must be encoded
    // rather than rendered raw, regardless of where the escape happens.
    expect(result.innerHTML).toMatch(/class="wp-block-kevinbatdorf-code-block-pro[^"]*"/);
  });

  it('escapes ampersands and angle brackets in style values', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        bgColor: '#fff & <script>',
      },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).not.toContain('<script>');
    expect(result.innerHTML).toContain('&amp;');
    expect(result.innerHTML).toContain('&lt;script&gt;');
  });

  it('keeps explicit php language without inference', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '$x = 1;', language: 'php' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('php');
  });
});

// ── CBP enrichment — language inference ──────────────────────────────────────

describe('enrichBlock — language inference', () => {
  /**
   * Explicit `language: 'plaintext'` is the caller saying "render this as plain
   * text, no syntax highlighting." Pre-fix the enricher treated 'plaintext' as
   * "no preference, infer" — so a chat prompt containing "from … from …" was
   * detected as SQL and rendered with mis-coloured English words. The contract
   * now is: explicit 'plaintext'/'text'/'plain'/'txt'/'none' is respected;
   * inference only runs when the attribute is missing, empty, or 'auto'.
   */
  it('respects explicit plaintext language without inference', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }', language: 'plaintext' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('plaintext');
  });

  it.each(['text', 'plain', 'txt', 'none'])(
    'respects explicit %s as plaintext alias',
    async (alias) => {
      const block: BlockDef = {
        name: 'kevinbatdorf/code-block-pro',
        attributes: { code: '.hero { color: red; }', language: alias },
      };
      const result = await enrichBlock(block);
      expect(result.attributes?.language).toBe('plaintext');
    },
  );

  it('infers css when language attribute is missing', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('css');
  });

  it('infers css when language is "auto"', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }', language: 'auto' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('css');
  });

  /**
   * Regression: chat prompts pasted into a CBP block via the API used to detect
   * as SQL because inferLanguage's SQL heuristic fires on the word "from" — and
   * "from" appears twice in the canonical "Set up Block MCP from … from me"
   * prompt. With explicit plaintext respected, the prompt renders correctly.
   */
  it('does not mis-detect English prose as SQL when caller passes plaintext', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'Set up Block MCP from https://example.com on my computer. Walk me through anything you need from me — WordPress site URL, username, and Application Password.',
        language: 'plaintext',
      },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('plaintext');
    // The generated codeHTML must not contain SQL keyword tokens for the
    // English words that previously got mis-coloured.
    expect(result.attributes?.codeHTML).not.toContain('shiki-token-keyword');
  });

  it('does not override non-plaintext language with inference', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('javascript');
  });
});

// ── CBP enrichment — innerHTML update ────────────────────────────────────────

describe('enrichBlock — innerHTML update', () => {
  it('replaces the <pre class="shiki"> portion in existing innerHTML', async () => {
    const code = 'const a = 1;';
    const oldPre = '<pre class="shiki old-theme"><code>old</code></pre>';
    const innerHTML = `<div class="cbp-wrap">${oldPre}<textarea>${code}</textarea></div>`;
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
      innerHTML,
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toBeDefined();
    expect(result.innerHTML).toContain('<pre class="shiki');
    expect(result.innerHTML).not.toContain('old-theme');
  });

  it('updates <textarea> content with current code', async () => {
    const code = 'const updated = true;';
    const innerHTML = `<div><pre class="shiki old"><code></code></pre><textarea>old code</textarea></div>`;
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
      innerHTML,
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain(`<textarea>${code}</textarea>`);
    expect(result.innerHTML).not.toContain('old code');
  });
});

// ── CBP enrichment — no-op (codeHTML already current) ────────────────────────

describe('enrichBlock — no-op when already enriched', () => {
  /**
   * A fully-enriched CBP block has both `codeHTML` (attribute) and `innerHTML`
   * (the rendered widget). Passing such a block through the enricher again
   * must be a no-op — same object reference returned. If only one side is
   * populated, the enricher rebuilds the missing piece so first-pass blocks
   * created via the API (no innerHTML) still render correctly.
   */
  it('returns original block reference when codeHTML and innerHTML are already current', async () => {
    const code = 'const x = 1;';
    const firstPass = await enrichBlock({
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
    });
    const codeHTML = firstPass.attributes?.codeHTML as string;
    const innerHTML = firstPass.innerHTML as string;

    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript', codeHTML },
      innerHTML,
    };
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });
});

// ── enrichBlocks ──────────────────────────────────────────────────────────────

describe('enrichBlocks', () => {
  it('returns empty array for empty input', async () => {
    expect(await enrichBlocks([])).toEqual([]);
  });

  it('enriches CBP blocks and passes non-CBP blocks through unchanged', async () => {
    const blocks: BlockDef[] = [
      { name: 'core/paragraph', attributes: { content: 'Hello' } },
      { name: 'kevinbatdorf/code-block-pro', attributes: { code: 'const x = 1;', language: 'javascript' } },
    ];
    const results = await enrichBlocks(blocks);
    expect(results).toHaveLength(2);
    expect(results[0]).toBe(blocks[0]);
    expect(results[1].attributes?.codeHTML).toBeDefined();
  });

  it('processes all CBP blocks in the array', async () => {
    const blocks: BlockDef[] = [
      { name: 'kevinbatdorf/code-block-pro', attributes: { code: 'const a = 1;', language: 'javascript' } },
      { name: 'kevinbatdorf/code-block-pro', attributes: { code: '$x = true;', language: 'php' } },
    ];
    const results = await enrichBlocks(blocks);
    expect(results[0].attributes?.codeHTML).toBeDefined();
    expect(results[1].attributes?.codeHTML).toBeDefined();
  });
});

// ── registerBlockEnricher ─────────────────────────────────────────────────────

describe('registerBlockEnricher', () => {
  beforeEach(() => vi.clearAllMocks());

  it('runs a custom enricher for its registered block name', async () => {
    const customFn = vi.fn(async (block: BlockDef) => ({
      ...block,
      attributes: { ...block.attributes, custom: true },
    }));
    registerBlockEnricher('test/custom-enricher-reg', customFn);

    const block: BlockDef = { name: 'test/custom-enricher-reg', attributes: { foo: 'bar' } };
    await enrichBlock(block);
    expect(customFn).toHaveBeenCalledWith(block);
  });

  it('applies the custom enricher result', async () => {
    registerBlockEnricher('test/custom-enricher-apply', async (block: BlockDef) => ({
      ...block,
      attributes: { ...block.attributes, computed: 'yes' },
    }));

    const block: BlockDef = { name: 'test/custom-enricher-apply', attributes: { original: true } };
    const result = await enrichBlock(block);
    expect(result.attributes?.computed).toBe('yes');
    expect(result.attributes?.original).toBe(true);
  });

  it('returns null (no-op) from enricher passes original block through', async () => {
    registerBlockEnricher('test/noop-enricher', async () => null);

    const block: BlockDef = { name: 'test/noop-enricher', attributes: {} };
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });
});

// ── Wrapper font-family sync (existing innerHTML) ─────────────────────────────

/**
 * The in-place branch rewrites the <pre> and the copy <textarea>, and must keep
 * the wrapper in sync as well: a fontFamily attribute change has to reach the
 * rendered markup, and a font stack with no generic family renders code in the
 * browser's default serif.
 */
describe('CBP enricher — wrapper font-family sync', () => {
  const WRAPPER_OPEN =
    '<div class="wp-block-kevinbatdorf-code-block-pro" style="font-family:Code-Pro-JetBrains-Mono;font-size:1rem">';

  function blockWithWrapper(overrides: Record<string, unknown> = {}): BlockDef {
    return {
      name: 'kevinbatdorf/code-block-pro',
      attributes: {
        code: 'const a = 1;',
        language: 'javascript',
        fontSize: '1rem',
        ...overrides,
      },
      innerHTML:
        `${WRAPPER_OPEN}<pre class="shiki gravitykit-dark"><code>stale</code></pre>` +
        '<textarea style="display:none" aria-hidden="true">stale</textarea></div>',
    };
  }

  it('rewrites a stale wrapper font-family from the current attribute', async () => {
    const result = await enrichBlock(
      blockWithWrapper({ fontFamily: 'Menlo,monospace' }),
    );
    expect(result.innerHTML).toContain('font-family:Menlo,monospace;font-size:1rem');
    expect(result.innerHTML).not.toContain('font-family:Code-Pro-JetBrains-Mono;');
  });

  /**
   * A generic family counts only as a whole comma-separated entry. Matching it
   * as a substring reads a custom name that merely contains one — `Source
   * Serif 4`, `custom-monospace-font` — as already-safe and withholds the
   * fallback stack those names most need.
   */
  it('adds the fallback to custom names that merely contain a generic family', async () => {
    const serif = await enrichBlock(blockWithWrapper({ fontFamily: 'Source Serif 4' }));
    expect(serif.innerHTML).toContain(
      'font-family:Source Serif 4,ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
    );

    const mono = await enrichBlock(blockWithWrapper({ fontFamily: 'custom-monospace-font' }));
    expect(mono.innerHTML).toContain(
      'font-family:custom-monospace-font,ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
    );
  });

  /**
   * A real generic entry is still recognized, including when quoted or padded,
   * so the helper stays idempotent across re-runs.
   */
  it('leaves a stack that already ends in a generic family unchanged', async () => {
    const result = await enrichBlock(
      blockWithWrapper({ fontFamily: '"Fira Code", monospace' }),
    );
    expect(result.innerHTML).toContain('font-family:&quot;Fira Code&quot;, monospace;');
    expect(result.innerHTML).not.toContain('SFMono-Regular');
  });

  /**
   * A font-family is spliced in among other declarations, so a value carrying
   * CSS structure would append declarations of the caller's choosing. Blank and
   * structurally-invalid values are dropped rather than emitted.
   */
  it('drops a blank or CSS-bearing font-family instead of emitting it', async () => {
    const blank = await enrichBlock(blockWithWrapper({ fontFamily: '   ' }));
    expect(blank.innerHTML).not.toContain('font-family:;');
    expect(blank.innerHTML).not.toContain('font-family: ;');

    const hostile = await enrichBlock(
      blockWithWrapper({ fontFamily: 'Menlo;background:url(x)' }),
    );
    expect(hostile.innerHTML).not.toContain('background:url(x)');
  });

  /**
   * With no fontFamily attribute to go on, the value already in the markup is
   * still repaired — that bare name is exactly the serif-fallback bug.
   */
  it('adds a generic fallback to a bare family already in the wrapper', async () => {
    const result = await enrichBlock(blockWithWrapper());
    expect(result.innerHTML).toContain(
      'font-family:Code-Pro-JetBrains-Mono,ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace',
    );
  });

  /**
   * CBP's own save() packs CSS custom properties into the same style attribute.
   * Rebuilding the attribute wholesale would drop them and take line numbers and
   * theme colours with it, so only the one declaration may be touched.
   */
  it('preserves other declarations and custom properties in the style attribute', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript', fontFamily: 'Menlo,monospace' },
      innerHTML:
        '<div class="wp-block-kevinbatdorf-code-block-pro cbp-has-line-numbers" ' +
        'data-code-block-pro-font-family="Code-Pro-JetBrains-Mono" ' +
        'style="font-family:Code-Pro-JetBrains-Mono;--cbp-line-number-color:#d8dee9ff;--shiki-token-comment:#8899aa">' +
        '<pre class="shiki gravitykit-dark"><code>stale</code></pre></div>',
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toContain('--cbp-line-number-color:#d8dee9ff');
    expect(result.innerHTML).toContain('--shiki-token-comment:#8899aa');
    expect(result.innerHTML).toContain('cbp-has-line-numbers');
    // The webfont-loading attribute tracks the same value.
    expect(result.innerHTML).toContain('data-code-block-pro-font-family="Menlo,monospace"');
  });

  /**
   * A fontFamily-only edit reaches the enricher with identical codeHTML and
   * language and so hits the early bail-out. It must still produce an update,
   * or the attribute saves while the rendered markup keeps the old font.
   *
   * CBP's front-end script picks the webfont to load from the wrapper's
   * data attribute, so that has to track the same value as the style
   * declaration — a wrapper carrying one must not be left on the old font.
   */
  it('still updates when only fontFamily changed', async () => {
    const seeded: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript', fontFamily: 'Menlo,monospace' },
      innerHTML:
        '<div class="wp-block-kevinbatdorf-code-block-pro" ' +
        'data-code-block-pro-font-family="Code-Pro-JetBrains-Mono" ' +
        'style="font-family:Code-Pro-JetBrains-Mono;font-size:1rem">' +
        '<pre class="shiki gravitykit-dark"><code>stale</code></pre></div>',
    };
    const first = await enrichBlock(seeded);

    const restyled: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { ...first.attributes, fontFamily: 'Consolas,monospace' },
      innerHTML: first.innerHTML,
    };
    const second = await enrichBlock(restyled);

    expect(second.innerHTML).toContain('font-family:Consolas,monospace');
    expect(second.innerHTML).not.toContain('font-family:Menlo,monospace');
    expect(second.innerHTML).toContain('data-code-block-pro-font-family="Consolas,monospace"');
    expect(second.innerHTML).not.toContain('data-code-block-pro-font-family="Menlo,monospace"');
  });

  it('leaves markup untouched when the font already matches', async () => {
    const first = await enrichBlock(blockWithWrapper({ fontFamily: 'Menlo,monospace' }));

    const unchanged: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { ...first.attributes },
      innerHTML: first.innerHTML,
    };
    const second = await enrichBlock(unchanged);
    expect(second).toBe(unchanged);
  });
});
