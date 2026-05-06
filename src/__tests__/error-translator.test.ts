/**
 * Tests for the WP REST error → agent-friendly hint translator.
 *
 * The contract: known codes get an actionable, LLM-readable message
 * (with embedded context like post_id / ref / path); unknown codes
 * return null so callers fall back to the original message.
 */

import { describe, it, expect } from 'vitest';
import { translateWpError } from '../error-translator.js';

describe('translateWpError', () => {
  it('returns null for an unknown code', () => {
    expect(translateWpError('something_we_have_never_seen', null)).toBeNull();
  });

  it('returns null for an undefined code', () => {
    expect(translateWpError(undefined, null)).toBeNull();
  });

  describe('routing & auth', () => {
    it('explains rest_no_route', () => {
      const msg = translateWpError('rest_no_route', null);
      expect(msg).toMatch(/gk-block-api plugin is active/);
    });

    it('explains rest_forbidden', () => {
      const msg = translateWpError('rest_forbidden', null);
      expect(msg).toMatch(/Permission denied/);
      expect(msg).toMatch(/Application Password/);
    });

    it('explains rest_authentication_required', () => {
      expect(translateWpError('rest_authentication_required', null)).toMatch(
        /WORDPRESS_USER and WORDPRESS_APP_PASSWORD/
      );
    });
  });

  describe('post lookup', () => {
    it('embeds the post_id from wpData when present', () => {
      const msg = translateWpError('rest_post_invalid_id', { post_id: 1234 });
      expect(msg).toMatch(/Post 1234 not found/);
      expect(msg).toMatch(/list_posts/);
    });

    it('omits the id gracefully when wpData lacks one', () => {
      const msg = translateWpError('rest_post_invalid_id', null);
      expect(msg).toBe('Post not found. List pages with `list_posts` to find the right ID.');
    });

    it('handles `not_found` differently when post_id is present vs absent', () => {
      expect(translateWpError('not_found', { post_id: 9 })).toMatch(/Post 9 not found/);
      expect(translateWpError('not_found', null)).toMatch(/Resource not found/);
    });
  });

  describe('ref / path resolution', () => {
    it('embeds ref + post_id for invalid_ref', () => {
      const msg = translateWpError('gk_block_api_invalid_ref', {
        ref: 'gk_ref_abc123',
        post_id: 42,
      });
      expect(msg).toMatch(/gk_ref_abc123/);
      expect(msg).toMatch(/post 42/);
      expect(msg).toMatch(/get_page_blocks/);
    });

    it('falls back to "?" placeholders when ref/post_id are missing', () => {
      const msg = translateWpError('gk_block_api_invalid_ref', null);
      expect(msg).toMatch(/Block ref `\?`/);
    });

    it('formats path arrays for path_not_found', () => {
      const msg = translateWpError('path_not_found', { path: [0, 2, 1] });
      expect(msg).toMatch(/\[0, 2, 1\]/);
      expect(msg).toMatch(/Re-fetch the post/);
    });

    it('falls back to ? for missing path', () => {
      expect(translateWpError('path_not_found', null)).toMatch(/path \? doesn't address/);
    });
  });

  describe('preference enforcement', () => {
    it('embeds block name and replacement for legacy_block', () => {
      const msg = translateWpError('legacy_block', {
        block: 'stackable/heading',
        suggested_replacement: 'core/heading',
      });
      expect(msg).toMatch(/stackable\/heading is a legacy block/);
      expect(msg).toMatch(/core\/heading/);
    });

    it('accepts `block_name` as an alternative to `block`', () => {
      const msg = translateWpError('legacy_block', { block_name: 'ugb/text' });
      expect(msg).toMatch(/ugb\/text is a legacy block/);
    });

    it('falls back to a generic message when block name is unknown', () => {
      const msg = translateWpError('legacy_block', null);
      expect(msg).toMatch(/Legacy block rejected/);
    });

    it('explains static_markup_stale_risk', () => {
      const msg = translateWpError('static_markup_stale_risk', null);
      expect(msg).toMatch(/Pass .*innerHTML/);
      expect(msg).toMatch(/static block/);
    });
  });

  describe('rate limiting', () => {
    it('includes post_id when present', () => {
      const msg = translateWpError('rate_limit_exceeded', { post_id: 7 });
      expect(msg).toMatch(/on post 7 in the last minute/);
      expect(msg).toMatch(/edit_block_tree/);
    });

    it('drops the post_id phrasing when absent', () => {
      const msg = translateWpError('rate_limit_exceeded', null);
      expect(msg).toMatch(/^Too many writes in the last minute/);
    });
  });

  describe('post lifecycle (v1.2)', () => {
    it('explains mixed_trash_payload', () => {
      const msg = translateWpError('mixed_trash_payload', null);
      expect(msg).toMatch(/Trash the post in one call/);
    });

    it('explains invalid_post_type', () => {
      expect(translateWpError('invalid_post_type', null)).toMatch(
        /gk_block_api_post_types_allowlist/
      );
    });
  });

  describe('media uploads', () => {
    it('explains invalid_url with SSRF context', () => {
      expect(translateWpError('invalid_url', null)).toMatch(/SSRF guard/);
    });

    it('explains disallowed_mime', () => {
      expect(translateWpError('disallowed_mime', null)).toMatch(/PNG\/JPG\/WEBP/);
    });
  });
});
