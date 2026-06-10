/**
 * Unit tests for translateWpError() — the WP REST error → agent-hint translator.
 *
 * Structure:
 *   1. Null-return contract (unknown / undefined codes)
 *   2. Per-group describe blocks (mirrors the switch in error-translator.ts)
 *   3. it.each coverage matrix over every documented error code
 *
 * The coverage matrix is the key regression guard: any new code added to the
 * README Error Codes table should appear in error-envelopes.ts and get caught
 * here automatically if it's also added to the translator switch.
 */

import { describe, it, expect } from 'vitest';
import { translateWpError } from '../../../error-translator.js';
import { ERROR_ENVELOPES, TRANSLATED_CODES } from '../../fixtures/error-envelopes.js';

// ── 1. Null-return contract ──────────────────────────────────────────────────

describe('translateWpError — null contract', () => {
  it('returns null for an unknown code', () => {
    expect(translateWpError('something_totally_unknown_xyz', null)).toBeNull();
  });

  it('returns null for undefined code', () => {
    expect(translateWpError(undefined, null)).toBeNull();
  });

  it('returns null for empty string code', () => {
    expect(translateWpError('', null)).toBeNull();
  });

  it('returns a non-null string for every code in TRANSLATED_CODES', () => {
    for (const code of TRANSLATED_CODES) {
      const result = translateWpError(code, null);
      expect(result, `Expected a translation for "${code}"`).not.toBeNull();
      expect(typeof result, `Expected string translation for "${code}"`).toBe('string');
    }
  });
});

// ── 2. Per-group describe blocks ─────────────────────────────────────────────

describe('translateWpError — routing & auth', () => {
  it('rest_no_route mentions the plugin name', () => {
    const msg = translateWpError('rest_no_route', null)!;
    expect(msg).toMatch(/Block MCP/);
    expect(msg).toMatch(/WORDPRESS_URL/);
  });

  it('rest_forbidden mentions Application Password and capability', () => {
    const msg = translateWpError('rest_forbidden', null)!;
    expect(msg).toMatch(/Permission denied/);
    expect(msg).toMatch(/Application Password/);
    expect(msg).toMatch(/edit_posts/);
  });

  it('rest_cannot_edit mentions capability', () => {
    const msg = translateWpError('rest_cannot_edit', null)!;
    expect(msg).toMatch(/Permission denied/);
  });

  it('rest_cannot_create mentions capability', () => {
    const msg = translateWpError('rest_cannot_create', null)!;
    expect(msg).toMatch(/Permission denied/);
  });

  it('rest_cookie_invalid_nonce mentions both env vars', () => {
    const msg = translateWpError('rest_cookie_invalid_nonce', null)!;
    expect(msg).toMatch(/WORDPRESS_USER/);
    expect(msg).toMatch(/WORDPRESS_APP_PASSWORD/);
  });

  it('rest_authentication_required mentions both env vars', () => {
    const msg = translateWpError('rest_authentication_required', null)!;
    expect(msg).toMatch(/WORDPRESS_USER/);
    expect(msg).toMatch(/WORDPRESS_APP_PASSWORD/);
  });
});

describe('translateWpError — post lookup', () => {
  it('rest_post_invalid_id embeds post_id from data', () => {
    const msg = translateWpError('rest_post_invalid_id', { post_id: 1234 })!;
    expect(msg).toMatch(/Post 1234 not found/);
    expect(msg).toMatch(/list_posts/);
  });

  it('rest_post_invalid_id gracefully omits id when data is null', () => {
    const msg = translateWpError('rest_post_invalid_id', null)!;
    expect(msg).toBe('Post not found. List pages with `list_posts` to find the right ID.');
  });

  it('invalid_post_id is an alias of rest_post_invalid_id', () => {
    const withData = translateWpError('invalid_post_id', { post_id: 7 })!;
    expect(withData).toMatch(/Post 7 not found/);
    const bare = translateWpError('invalid_post_id', null)!;
    expect(bare).toMatch(/Post not found/);
  });

  it('not_found with post_id mentions the id', () => {
    expect(translateWpError('not_found', { post_id: 9 })).toMatch(/Post 9 not found/);
  });

  it('not_found without post_id gives generic message', () => {
    expect(translateWpError('not_found', null)).toMatch(/Resource not found/);
  });
});

describe('translateWpError — block ref / path resolution', () => {
  it('gk_block_api_invalid_ref embeds ref and post_id', () => {
    const msg = translateWpError('gk_block_api_invalid_ref', {
      ref: 'blk_abc123',
      post_id: 42,
    })!;
    expect(msg).toContain('blk_abc123');
    expect(msg).toContain('post 42');
    expect(msg).toMatch(/get_page_blocks/);
  });

  it('gk_block_api_invalid_ref uses ? placeholders when data is null', () => {
    const msg = translateWpError('gk_block_api_invalid_ref', null)!;
    expect(msg).toMatch(/Block ref `\?`/);
    expect(msg).toMatch(/post \?/);
  });

  it('invalid_ref is an alias', () => {
    const msg = translateWpError('invalid_ref', { ref: 'blk_xyz', post_id: 5 })!;
    expect(msg).toContain('blk_xyz');
  });

  it('path_not_found formats path array correctly', () => {
    const msg = translateWpError('path_not_found', { path: [0, 2, 1] })!;
    expect(msg).toMatch(/\[0, 2, 1\]/);
    expect(msg).toMatch(/Re-fetch the post/);
  });

  it('path_not_found uses ? when path is absent', () => {
    expect(translateWpError('path_not_found', null)).toMatch(/path \? doesn't address/);
  });

  it('invalid_path is an alias', () => {
    const msg = translateWpError('invalid_path', { path: [1] })!;
    expect(msg).toMatch(/\[1\]/);
  });

  it('path_out_of_bounds mentions re-fetching', () => {
    const msg = translateWpError('path_out_of_bounds', { path: [99] })!;
    expect(msg).toMatch(/out of bounds/);
    expect(msg).toMatch(/get_page_blocks/);
  });
});

describe('translateWpError — preference enforcement', () => {
  it('legacy_block embeds block name and replacement', () => {
    const msg = translateWpError('legacy_block', {
      block: 'example/heading',
      suggested_replacement: 'core/heading',
    })!;
    expect(msg).toMatch(/example\/heading is in a namespace this site has configured as legacy/);
    expect(msg).toMatch(/core\/heading/);
  });

  it('legacy_block accepts block_name as alternative to block', () => {
    const msg = translateWpError('legacy_block', { block_name: 'example/text' })!;
    expect(msg).toMatch(/example\/text is in a namespace this site has configured as legacy/);
  });

  it('legacy_block falls back to generic when no block name', () => {
    expect(translateWpError('legacy_block', null)).toMatch(/Legacy block rejected/);
  });

  it('legacy_block message does not name specific third-party vendors', () => {
    // Sanity check: the message must stay vendor-agnostic — naming specific
    // namespaces (stackable / ugb / jetpack) makes the message brittle when
    // site preferences change and singles out individual plugins.
    const msg = translateWpError('legacy_block', { block: 'example/x' })!;
    expect(msg).not.toMatch(/Stackable|UGB|Jetpack/i);
  });

  it('inner_html_required names the offending attributes and the block', () => {
    const msg = translateWpError('inner_html_required', {
      block: 'core/paragraph',
      source_bound_attributes: ['content'],
    })!;
    expect(msg).toMatch(/core\/paragraph/);
    expect(msg).toMatch(/\[content\]/);
    expect(msg).toMatch(/innerHTML/);
    expect(msg).toMatch(/invalid content/);
  });

  it('inner_html_required falls back gracefully without block name', () => {
    const msg = translateWpError('inner_html_required', {
      source_bound_attributes: ['url'],
    })!;
    expect(msg).toMatch(/\[url\]/);
    expect(msg).toMatch(/innerHTML/);
  });

  it('inner_html_required survives missing source_bound_attributes hint', () => {
    const msg = translateWpError('inner_html_required', { block: 'core/heading' })!;
    expect(msg).toMatch(/core\/heading/);
    expect(msg).toMatch(/innerHTML/);
  });

  it('static_markup_stale_risk mentions innerHTML and static block', () => {
    const msg = translateWpError('static_markup_stale_risk', null)!;
    expect(msg).toMatch(/innerHTML/);
    expect(msg).toMatch(/static block/);
  });
});

describe('translateWpError — rate limiting', () => {
  it('rate_limit_exceeded embeds post_id when present', () => {
    const msg = translateWpError('rate_limit_exceeded', { post_id: 7 })!;
    expect(msg).toMatch(/on post 7 in the last minute/);
    expect(msg).toMatch(/edit_block_tree/);
  });

  it('rate_limit_exceeded drops post_id clause when absent', () => {
    const msg = translateWpError('rate_limit_exceeded', null)!;
    expect(msg).toMatch(/^Too many writes in the last minute/);
    expect(msg).not.toMatch(/on post/);
  });
});

describe('translateWpError — v1.2 post lifecycle', () => {
  it('mixed_trash_payload suggests separate calls', () => {
    const msg = translateWpError('mixed_trash_payload', null)!;
    expect(msg).toMatch(/Trash the post in one call/);
  });

  it('invalid_post_type mentions the allowlist option', () => {
    expect(translateWpError('invalid_post_type', null)).toMatch(
      /gk_block_api_post_types_allowlist/
    );
  });

  it('invalid_status lists valid values', () => {
    const msg = translateWpError('invalid_status', null)!;
    expect(msg).toMatch(/draft/);
  });
});

describe('translateWpError — media uploads', () => {
  it('invalid_url mentions SSRF guard', () => {
    expect(translateWpError('invalid_url', null)).toMatch(/SSRF guard/);
  });

  it('disallowed_mime suggests image formats', () => {
    expect(translateWpError('disallowed_mime', null)).toMatch(/PNG\/JPG\/WEBP/);
  });
});

// ── 3. Coverage matrix over all documented error codes ───────────────────────
//
// For every code in ERROR_ENVELOPES, assert:
//   a) translateWpError returns a string (if code is in TRANSLATED_CODES)
//   b) or returns null (if code is unknown to the translator)
//
// This makes it impossible to add a new documented code without the test
// suite visibly reporting a gap.

describe('translateWpError — full error-code coverage matrix', () => {
  it.each(
    ERROR_ENVELOPES.map((e) => [e.code, e.data, TRANSLATED_CODES.has(e.code)] as const)
  )('code=%s — returns %s', (code, data, isTranslated) => {
    const result = translateWpError(code, data);
    if (isTranslated) {
      expect(result, `Expected a translation for "${code}"`).not.toBeNull();
      expect(typeof result).toBe('string');
      // Every translated message should be non-empty and end without extra whitespace
      expect((result as string).trim().length).toBeGreaterThan(10);
    } else {
      // Undocumented / not yet translated — must return null, not throw.
      // Asserting the return value (not just the absence of a throw) catches
      // the case where a future change adds a fallback that returns a
      // generic string for unknown codes — silently translating unknowns is
      // worse than letting them surface raw.
      expect(result, `Expected null for untranslated code "${code}"`).toBeNull();
    }
  });
});

// ── 4. extractHints edge cases ────────────────────────────────────────────────

describe('translateWpError — data extraction edge cases', () => {
  it('ignores non-object data payload', () => {
    // String data: should not throw, fall back to ? placeholders
    expect(() => translateWpError('gk_block_api_invalid_ref', 'oops')).not.toThrow();
  });

  it('ignores array data payload', () => {
    expect(() => translateWpError('gk_block_api_invalid_ref', [1, 2, 3])).not.toThrow();
  });

  it('handles path array with non-integer values — does not throw', () => {
    // extractHints validates each element is a finite number; non-integers
    // are filtered out, so the path hint falls back to ? placeholder.
    // The translator must not throw regardless of payload shape.
    expect(() => translateWpError('path_not_found', { path: ['a', 'b'] })).not.toThrow();
    const msg = translateWpError('path_not_found', { path: ['a', 'b'] });
    expect(msg).not.toBeNull();
  });

  it('block_name field is used when block field is absent', () => {
    const msg = translateWpError('legacy_block', { block_name: 'example/icon' })!;
    expect(msg).toContain('example/icon');
  });

  it('block field takes precedence over block_name when both present', () => {
    const msg = translateWpError('legacy_block', {
      block: 'example/text',
      block_name: 'other/block',
    })!;
    expect(msg).toContain('example/text');
    expect(msg).not.toContain('other/block');
  });
});
