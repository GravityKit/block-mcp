import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  inferLanguage,
  enrichBlock,
  enrichBlocks,
  registerBlockEnricher,
  type BlockDef,
} from '../enrichers.js';

// ── inferLanguage ─────────────────────────────────────────────────────────────

describe('inferLanguage', () => {
  it('returns php for code containing $var =', () => {
    expect(inferLanguage('$greeting = "hello";')).toBe('php');
  });

  it('returns php for code with a PHP function declaration', () => {
    expect(inferLanguage('function myFunc() { return true; }')).toBe('php');
  });

  it('returns css for .class { prop: val; }', () => {
    expect(inferLanguage('.container { display: flex; }')).toBe('css');
  });

  it('returns javascript for code starting with const', () => {
    expect(inferLanguage('const x = 1;')).toBe('javascript');
  });

  it('returns javascript for code starting with const', () => {
    expect(inferLanguage('const fn = 42;')).toBe('javascript');
    expect(inferLanguage('const arr = [1, 2, 3];')).toBe('javascript');
  });

  it('returns bash for #!/bin/bash shebang', () => {
    expect(inferLanguage('#!/bin/bash\necho "hello"')).toBe('bash');
  });

  it('returns json for {"key": "value"}', () => {
    expect(inferLanguage('{"key": "value"}')).toBe('json');
  });

  it('returns plaintext for unrecognized content', () => {
    expect(inferLanguage('hello world this is just text')).toBe('plaintext');
  });
});

// ── enrichBlock ───────────────────────────────────────────────────────────────

describe('enrichBlock', () => {
  it('passes a non-CBP block with no registered enricher through unchanged', async () => {
    const block: BlockDef = { name: 'core/paragraph', attributes: { content: 'Hello' } };
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });

  it('returns the block unchanged when CBP block has no code attribute', async () => {
    const block: BlockDef = { name: 'kevinbatdorf/code-block-pro', attributes: { language: 'php' } };
    const result = await enrichBlock(block);
    // enricher returns null (no-op) → enrichBlock returns original block
    expect(result).toEqual(block);
  });

  it('generates codeHTML in attributes for a CBP block', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const x = 1;', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.codeHTML).toBeDefined();
    expect(result.attributes?.codeHTML as string).toContain('<pre class="shiki css-variables"');
  });

  it('sets highestLineNumber to the line count of code', async () => {
    const code = 'line1\nline2\nline3';
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'plaintext' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.highestLineNumber).toBe(3);
  });

  it('infers css language when language is plaintext and code looks like CSS', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '.hero { color: red; }', language: 'plaintext' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('css');
  });

  it('keeps explicit php language without inference', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: '$x = 1;', language: 'php' },
    };
    const result = await enrichBlock(block);
    expect(result.attributes?.language).toBe('php');
  });

  it('leaves innerHTML undefined when block has no innerHTML', async () => {
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code: 'const a = 1;', language: 'javascript' },
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toBeUndefined();
  });

  it('replaces <pre class="shiki portion in innerHTML when innerHTML is provided', async () => {
    const code = 'const a = 1;';
    const originalPre = '<pre class="shiki old-theme"><code>old</code></pre>';
    const innerHTML = `<div class="cbp-wrap">${originalPre}<textarea>${code}</textarea></div>`;
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
      innerHTML,
    };
    const result = await enrichBlock(block);
    expect(result.innerHTML).toBeDefined();
    expect(result.innerHTML).toContain('<pre class="shiki css-variables"');
    expect(result.innerHTML).not.toContain('old-theme');
  });

  it('updates <textarea> content with raw code when innerHTML is provided', async () => {
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

  it('returns null (no-op) when codeHTML already matches and language is unchanged', async () => {
    // First enrich to get the real codeHTML
    const code = 'const x = 1;';
    const firstPass = await enrichBlock({
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript' },
    });
    const codeHTML = firstPass.attributes?.codeHTML as string;

    // Second pass with the same codeHTML already present — should be a no-op
    const block: BlockDef = {
      name: 'kevinbatdorf/code-block-pro',
      attributes: { code, language: 'javascript', codeHTML },
    };
    // The enricher returns null, so enrichBlock returns the original block reference
    const result = await enrichBlock(block);
    expect(result).toBe(block);
  });
});

// ── enrichBlocks ──────────────────────────────────────────────────────────────

describe('enrichBlocks', () => {
  it('returns an empty array for empty input', async () => {
    expect(await enrichBlocks([])).toEqual([]);
  });

  it('enriches CBP blocks and passes non-CBP blocks through', async () => {
    const blocks: BlockDef[] = [
      { name: 'core/paragraph', attributes: { content: 'Hello' } },
      { name: 'kevinbatdorf/code-block-pro', attributes: { code: 'const x = 1;', language: 'javascript' } },
    ];
    const results = await enrichBlocks(blocks);
    expect(results).toHaveLength(2);
    // non-CBP passes through unchanged
    expect(results[0]).toBe(blocks[0]);
    // CBP gets codeHTML
    expect(results[1].attributes?.codeHTML).toBeDefined();
    expect(results[1].attributes?.codeHTML as string).toContain('<pre class="shiki css-variables"');
  });
});

// ── registerBlockEnricher ─────────────────────────────────────────────────────

describe('registerBlockEnricher', () => {
  beforeEach(() => vi.clearAllMocks());

  it('runs a custom enricher for its registered block name', async () => {
    const customFn = vi.fn(async (block: BlockDef) => ({
      ...block,
      attributes: { ...block.attributes, enriched: true },
    }));
    registerBlockEnricher('test/custom-block', customFn);

    const block: BlockDef = { name: 'test/custom-block', attributes: { foo: 'bar' } };
    await enrichBlock(block);
    expect(customFn).toHaveBeenCalledWith(block);
  });

  it('applies the custom enricher result to block attributes', async () => {
    registerBlockEnricher('test/applies-result', async (block: BlockDef) => ({
      ...block,
      attributes: { ...block.attributes, computed: 'yes' },
    }));

    const block: BlockDef = { name: 'test/applies-result', attributes: { original: true } };
    const result = await enrichBlock(block);
    expect(result.attributes?.computed).toBe('yes');
    expect(result.attributes?.original).toBe(true);
  });
});
