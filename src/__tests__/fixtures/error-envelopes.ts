/**
 * Error envelope fixtures for every documented error code.
 *
 * Each entry maps a WP REST error code to a realistic data payload the
 * plugin might return in the `data` field of the error envelope.
 *
 * Shape: { code: string, status: number, data: Record<string,unknown> | null }
 *
 * Used primarily in the error-translator coverage matrix tests.
 */

export interface ErrorEnvelope {
  code: string;
  /** HTTP status the plugin returns for this code */
  httpStatus: number;
  /** Content of the `data` property in the WP error envelope */
  data: Record<string, unknown> | null;
}

export const ERROR_ENVELOPES: ErrorEnvelope[] = [
  // ── Auth & permissions (403) ────────────────────────────────────────────
  { code: 'rest_forbidden',            httpStatus: 403, data: null },
  { code: 'rest_cannot_edit',          httpStatus: 403, data: { post_id: 42 } },
  { code: 'rest_cannot_create',        httpStatus: 403, data: null },
  { code: 'rest_cannot_publish',       httpStatus: 403, data: null },
  { code: 'rest_cannot_upload',        httpStatus: 403, data: null },
  { code: 'rest_cannot_assign_author', httpStatus: 403, data: null },
  { code: 'uploads_disabled',          httpStatus: 403, data: null },

  // ── Auth (401) ──────────────────────────────────────────────────────────
  { code: 'rest_cookie_invalid_nonce',     httpStatus: 401, data: null },
  { code: 'rest_authentication_required',  httpStatus: 401, data: null },

  // ── Routing (404) ───────────────────────────────────────────────────────
  { code: 'rest_no_route', httpStatus: 404, data: null },

  // ── Not found (404) ────────────────────────────────────────────────────
  { code: 'post_not_found',      httpStatus: 404, data: { post_id: 42 } },
  { code: 'block_not_found',     httpStatus: 404, data: { post_id: 42 } },
  { code: 'ref_stale',           httpStatus: 404, data: { ref: 'blk_gone0001', post_id: 42 } },
  { code: 'pattern_not_found',   httpStatus: 404, data: null },
  { code: 'revision_not_found',  httpStatus: 404, data: null },
  { code: 'not_found',           httpStatus: 404, data: null },
  { code: 'not_found_with_post', httpStatus: 404, data: { post_id: 77 } },

  // ── Precondition / concurrency (409/412) ────────────────────────────────
  { code: 'stale_revision', httpStatus: 412, data: { current_revision: 88 } },
  { code: 'edit_conflict',  httpStatus: 409, data: null },

  // ── Validation (400) ────────────────────────────────────────────────────
  { code: 'legacy_block',              httpStatus: 400, data: { block: 'ugb/text', suggested_replacement: 'core/paragraph' } },
  { code: 'inner_html_required',       httpStatus: 400, data: { block: 'core/paragraph', source_bound_attributes: ['content'] } },
  { code: 'static_markup_stale_risk',  httpStatus: 400, data: null },
  { code: 'dual_storage_requires_both', httpStatus: 400, data: { block_name: 'yoast/faq-block' } },
  { code: 'bound_attribute',           httpStatus: 400, data: null },
  { code: 'batch_too_large',           httpStatus: 400, data: null },
  { code: 'batch_validation_failed',   httpStatus: 400, data: { errors: [{ index: 0, code: 'ref_stale' }] } },
  { code: 'empty_batch',               httpStatus: 400, data: null },
  { code: 'block_depth_exceeded',      httpStatus: 400, data: { max_depth: 32, actual_depth: 33 } },
  { code: 'invalid_path',              httpStatus: 400, data: { path: [0, 99] } },
  { code: 'invalid_ref',               httpStatus: 400, data: null },
  { code: 'invalid_index',             httpStatus: 400, data: { flat_index: 12 } },
  { code: 'ref_not_top_level',         httpStatus: 400, data: { ref: 'blk_nested' } },
  { code: 'invalid_op',                httpStatus: 400, data: null },
  { code: 'invalid_block',             httpStatus: 400, data: null },
  { code: 'missing_attributes',        httpStatus: 400, data: null },
  { code: 'invalid_post_type',         httpStatus: 400, data: null },
  { code: 'invalid_status',            httpStatus: 400, data: null },
  { code: 'mixed_trash_payload',       httpStatus: 400, data: null },
  { code: 'invalid_if_match',          httpStatus: 400, data: null },
  { code: 'no_inner_blocks',           httpStatus: 400, data: null },
  { code: 'multiple_inputs',           httpStatus: 400, data: null },
  { code: 'disallowed_mime',           httpStatus: 400, data: null },
  { code: 'file_too_large',            httpStatus: 400, data: null },
  { code: 'invalid_url',               httpStatus: 400, data: null },
  { code: 'empty_pattern',             httpStatus: 400, data: null },
  { code: 'trash_disabled',            httpStatus: 403, data: null },

  // ── Rate limit (429) ────────────────────────────────────────────────────
  { code: 'rate_limit_exceeded', httpStatus: 429, data: { post_id: 42 } },
  { code: 'rate_limit_locked',   httpStatus: 429, data: { post_id: 42 } },
  { code: 'scan_rate_limited',   httpStatus: 429, data: null },

  // ── Upstream (502) ──────────────────────────────────────────────────────
  { code: 'url_fetch_failed', httpStatus: 502, data: null },

  // ── Server error (500) ──────────────────────────────────────────────────
  { code: 'internal_error',       httpStatus: 500, data: null },
  { code: 'wp_insert_post_failed', httpStatus: 500, data: null },
  { code: 'sideload_failed',      httpStatus: 500, data: null },
  { code: 'trash_failed',         httpStatus: 500, data: null },
];

/**
 * Subset: codes that translateWpError() has an explicit translation for.
 * Keep in sync with the switch in src/error-translator.ts.
 */
export const TRANSLATED_CODES = new Set([
  'rest_no_route',
  'rest_forbidden',
  'rest_cannot_edit',
  'rest_cannot_create',
  'rest_cookie_invalid_nonce',
  'rest_authentication_required',
  'rest_post_invalid_id',
  'post_not_found',
  'not_found',
  'invalid_ref',
  'ref_stale',
  'invalid_path',
  'invalid_index',
  'legacy_block',
  'inner_html_required',
  'static_markup_stale_risk',
  'dual_storage_requires_both',
  'block_depth_exceeded',
  'edit_conflict',
  'stale_revision',
  'rate_limit_exceeded',
  'rate_limit_locked',
  'mixed_trash_payload',
  'invalid_post_type',
  'invalid_status',
  'trash_disabled',
  'invalid_url',
  'disallowed_mime',
]);
