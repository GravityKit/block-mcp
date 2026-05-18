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

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  inferLanguage,
  enrichBlock,
  enrichBlocks,
  registerBlockEnricher,
  type BlockDef,
} from '../../../enrichers.js';

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

  it('leaves innerHTML undefined when block has no innerHTML', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toBeUndefined();
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
  it('infers css when language is plaintext and code looks like CSS', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }', language: 'plaintext' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('css');
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
  it('returns original block reference when codeHTML already matches', async () => {
    const code = 'const x = 1;';
    const firstPass = await enrichBlock({
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
    });
    const codeHTML = firstPass.attributes?.codeHTML as string;

    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript', codeHTML },
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
