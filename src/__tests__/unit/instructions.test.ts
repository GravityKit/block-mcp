/**
 * Unit tests for src/instructions.ts (BLOCK-19).
 *
 * Covers the three pure helpers (sanitizeAddendum, combineInstructions,
 * BASELINE) plus the network-bound fetchAddendum / getInstructions
 * helpers with axios mocked. No live HTTP — these all run offline.
 *
 * Invariants pinned here:
 *   - Sanitize strips C0 + DEL + Bidi/zero-width without touching tab/LF/CR.
 *   - Sanitize truncates at MAX_ADDENDUM_LENGTH (defense in depth).
 *   - Sanitize coerces non-string input to '' (no exceptions thrown).
 *   - Combine returns baseline unchanged when addendum empty.
 *   - Combine joins with `\n\n` when addendum non-empty.
 *   - fetchAddendum honors BLOCK_MCP_INSTRUCTIONS_OFF=1 and skips the call.
 *   - fetchAddendum falls back to '' on network failure (no throw).
 *   - fetchAddendum sanitizes the remote payload (defense in depth).
 *   - getInstructions wraps fetch + combine for the public entry point.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';

vi.mock('axios');

import {
  BASELINE,
  MAX_ADDENDUM_LENGTH,
  combineInstructions,
  fetchAddendum,
  getInstructions,
  sanitizeAddendum,
} from '../../instructions.js';

const ORIG_ENV = { ...process.env };

beforeEach(() => {
  // Each test starts with a clean env to keep BLOCK_MCP_INSTRUCTIONS_OFF
  // assertions isolated. Vitest doesn't reset process.env between tests
  // on its own.
  process.env = { ...ORIG_ENV };
  vi.clearAllMocks();
});

afterEach(() => {
  process.env = { ...ORIG_ENV };
});

// ── BASELINE ─────────────────────────────────────────────────────────────────

describe('BASELINE', () => {
  /**
   * The baseline string is the contract the SDK and every client depend
   * on. Pinning its length guards against accidental edits that strip
   * crucial guidance — e.g. a refactor that drops the "saved.inner_html
   * is the canonical post-save snapshot" rule would degrade every
   * client. If this assertion fails, you either intentionally rewrote
   * the baseline (update the bound) or accidentally truncated it.
   */
  it('is a non-trivial, non-empty string', () => {
    expect(typeof BASELINE).toBe('string');
    expect(BASELINE.length).toBeGreaterThan(200);
  });

  it('mentions saved.inner_html guidance', () => {
    expect(BASELINE).toContain('saved.inner_html');
  });

  it('mentions URL → post_id resolution rule', () => {
    expect(BASELINE).toContain('post_id is resolved server-side');
  });
});

// ── sanitizeAddendum ─────────────────────────────────────────────────────────

describe('sanitizeAddendum', () => {
  it('returns empty string for non-string input', () => {
    expect(sanitizeAddendum(undefined)).toBe('');
    expect(sanitizeAddendum(null)).toBe('');
    expect(sanitizeAddendum(42)).toBe('');
    expect(sanitizeAddendum({ a: 1 })).toBe('');
    expect(sanitizeAddendum([])).toBe('');
  });

  it('passes plain ASCII through unchanged', () => {
    expect(sanitizeAddendum('Use callouts for tips.')).toBe('Use callouts for tips.');
  });

  it('preserves newlines, tabs, and indented bullets', () => {
    const v = '- Top\n\t- Nested\n- Bottom';
    expect(sanitizeAddendum(v)).toBe(v);
  });

  it('strips ASCII C0 control characters except tab/LF/CR', () => {
    const v = 'A\x00B\x07C\x1BD\x08E';
    expect(sanitizeAddendum(v)).toBe('ABCDE');
  });

  it('strips DEL character (0x7F)', () => {
    expect(sanitizeAddendum('foo\x7Fbar')).toBe('foobar');
  });

  it('strips Bidi override codepoints', () => {
    // U+202E RIGHT-TO-LEFT OVERRIDE — classic spoofing vector. The
    // expected output is the visible text only, with the override gone.
    const v = 'allow‮gnirts‬';
    const cleaned = sanitizeAddendum(v);
    expect(cleaned).not.toContain('‮');
    expect(cleaned).not.toContain('‬');
    expect(cleaned).toContain('allow');
  });

  it('strips zero-width characters', () => {
    // ZWSP (U+200B), ZWNJ (U+200C), ZWJ (U+200D), BOM (U+FEFF).
    const v = 'visi​ble‌‍﻿ text';
    expect(sanitizeAddendum(v)).toBe('visible text');
  });

  it('normalizes CRLF and CR to LF', () => {
    expect(sanitizeAddendum('a\r\nb\rc')).toBe('a\nb\nc');
  });

  it('trims outer whitespace', () => {
    expect(sanitizeAddendum('\n\n  inner content  \n')).toBe('inner content');
  });

  it('truncates to MAX_ADDENDUM_LENGTH', () => {
    const long = 'A'.repeat(MAX_ADDENDUM_LENGTH + 500);
    expect(sanitizeAddendum(long)).toHaveLength(MAX_ADDENDUM_LENGTH);
  });

  it('accepts an input exactly at the cap', () => {
    const exact = 'B'.repeat(MAX_ADDENDUM_LENGTH);
    expect(sanitizeAddendum(exact)).toHaveLength(MAX_ADDENDUM_LENGTH);
  });

  it('returns empty for empty string', () => {
    expect(sanitizeAddendum('')).toBe('');
  });
});

// ── combineInstructions ──────────────────────────────────────────────────────

describe('combineInstructions', () => {
  it('returns baseline unchanged when addendum is empty', () => {
    expect(combineInstructions(BASELINE, '')).toBe(BASELINE);
  });

  it('returns baseline unchanged when addendum is whitespace only', () => {
    expect(combineInstructions(BASELINE, '   \n\n  \t  ')).toBe(BASELINE);
  });

  it('joins baseline and addendum with a blank line', () => {
    const result = combineInstructions('BASE', 'ADD');
    expect(result).toBe('BASE\n\nADD');
  });

  it('trims the addendum before joining', () => {
    const result = combineInstructions('BASE', '  ADD  ');
    expect(result).toBe('BASE\n\nADD');
  });

  it('preserves multi-line addenda verbatim', () => {
    const addendum = '- Rule one.\n- Rule two.';
    const result = combineInstructions('BASE', addendum);
    expect(result).toBe(`BASE\n\n${addendum}`);
  });
});

// ── fetchAddendum (axios mocked) ─────────────────────────────────────────────

describe('fetchAddendum', () => {
  it('returns empty string when BLOCK_MCP_INSTRUCTIONS_OFF=1 (no HTTP call)', async () => {
    process.env.BLOCK_MCP_INSTRUCTIONS_OFF = '1';
    const result = await fetchAddendum('https://example.com');
    expect(result).toBe('');
    expect(vi.mocked(axios.get)).not.toHaveBeenCalled();
  });

  it('treats any value other than 1 in BLOCK_MCP_INSTRUCTIONS_OFF as off-not-set', async () => {
    process.env.BLOCK_MCP_INSTRUCTIONS_OFF = '0';
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { addendum: 'hi' } });
    const result = await fetchAddendum('https://example.com');
    expect(result).toBe('hi');
  });

  it('issues GET to /wp-json/gk-block-api/v1/instructions', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { addendum: 'use info callout' } });
    await fetchAddendum('https://example.com');
    expect(vi.mocked(axios.get)).toHaveBeenCalledOnce();
    const [url] = vi.mocked(axios.get).mock.calls[0]!;
    expect(url).toBe('https://example.com/wp-json/gk-block-api/v1/instructions');
  });

  it('normalizes trailing slashes in the base URL', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { addendum: 'x' } });
    await fetchAddendum('https://example.com///');
    const [url] = vi.mocked(axios.get).mock.calls[0]!;
    expect(url).toBe('https://example.com/wp-json/gk-block-api/v1/instructions');
  });

  it('passes a short timeout and JSON Accept header', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { addendum: 'x' } });
    await fetchAddendum('https://example.com');
    const [, config] = vi.mocked(axios.get).mock.calls[0]!;
    expect(config).toBeDefined();
    expect(config!.timeout).toBeGreaterThan(0);
    expect(config!.timeout).toBeLessThanOrEqual(10_000);
    expect(config!.headers).toMatchObject({ Accept: 'application/json' });
  });

  it('returns the sanitized addendum on success', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: { addendum: 'A\x00B' },
    });
    const result = await fetchAddendum('https://example.com');
    expect(result).toBe('AB');
  });

  it('truncates an overly long remote response to MAX_ADDENDUM_LENGTH', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: { addendum: 'X'.repeat(MAX_ADDENDUM_LENGTH + 1000) },
    });
    const result = await fetchAddendum('https://example.com');
    expect(result).toHaveLength(MAX_ADDENDUM_LENGTH);
  });

  it('strips Bidi overrides served by a compromised WP install', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: { addendum: 'evil‮block' },
    });
    const result = await fetchAddendum('https://example.com');
    expect(result).not.toContain('‮');
  });

  it('returns empty when the response is missing the addendum field', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { length: 0 } });
    expect(await fetchAddendum('https://example.com')).toBe('');
  });

  it('returns empty when the response body is not an object', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: 'malformed' });
    expect(await fetchAddendum('https://example.com')).toBe('');
  });

  it('returns empty when axios rejects (network error)', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce(new Error('ECONNREFUSED'));
    // Suppress the stderr noise from the rejection log line.
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(await fetchAddendum('https://example.com')).toBe('');
    spy.mockRestore();
  });

  it('returns empty when the server replies non-2xx', async () => {
    // axios with validateStatus throws on non-2xx by default; emulate that.
    const err = Object.assign(new Error('Request failed with status code 500'), {
      isAxiosError: true,
      response: { status: 500, data: '' },
    });
    vi.mocked(axios.get).mockRejectedValueOnce(err);
    vi.mocked(axios.isAxiosError).mockReturnValue(true);
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(await fetchAddendum('https://example.com')).toBe('');
    spy.mockRestore();
  });

  it('never throws — every failure path returns empty', async () => {
    vi.mocked(axios.get).mockImplementation(() => {
      throw new Error('synchronous boom');
    });
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    await expect(fetchAddendum('https://example.com')).resolves.toBe('');
    spy.mockRestore();
  });
});

// ── getInstructions (the public entry point) ─────────────────────────────────

describe('getInstructions', () => {
  it('returns baseline alone when the site is unreachable', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce(new Error('ENOTFOUND'));
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const result = await getInstructions('https://example.com');
    expect(result).toBe(BASELINE);
    spy.mockRestore();
  });

  it('returns baseline alone when BLOCK_MCP_INSTRUCTIONS_OFF=1', async () => {
    process.env.BLOCK_MCP_INSTRUCTIONS_OFF = '1';
    const result = await getInstructions('https://example.com');
    expect(result).toBe(BASELINE);
    expect(vi.mocked(axios.get)).not.toHaveBeenCalled();
  });

  it('returns baseline + addendum joined with a blank line', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: { addendum: 'CUSTOM RULE: prefer is-style-callout-info.' },
    });
    const result = await getInstructions('https://example.com');
    expect(result.startsWith(BASELINE)).toBe(true);
    expect(result.endsWith('CUSTOM RULE: prefer is-style-callout-info.')).toBe(true);
    expect(result).toContain('\n\nCUSTOM RULE');
  });

  it('returns baseline alone when the site returns empty addendum', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { addendum: '' } });
    expect(await getInstructions('https://example.com')).toBe(BASELINE);
  });
});
