/**
 * Unit tests for the HTTP client's retry-eligibility policy (isRetryable).
 *
 * Offline — builds minimal AxiosError-shaped objects and exercises the pure
 * decision function directly. No network, no axios instance.
 *
 * Key invariant (regression): DELETE is NOT retry-safe. `delete_block` deletes
 * by flat index, so replaying a delete whose response was lost removes the
 * *next* block (indices shift after the first delete). A 502/timeout on DELETE
 * must therefore NOT be retried.
 */

import { describe, it, expect } from 'vitest';
import type { AxiosError } from 'axios';
import { isRetryable } from '../src/client.js';

/** Build a minimal AxiosError for a response-status failure on `method`. */
function httpError(method: string, status: number): AxiosError {
  return {
    config: { method },
    response: { status },
    isAxiosError: true,
  } as unknown as AxiosError;
}

/** Build a minimal AxiosError for a network-level failure (no response) on `method`. */
function networkError(method: string, code: string): AxiosError {
  return {
    config: { method },
    code,
    isAxiosError: true,
  } as unknown as AxiosError;
}

describe('isRetryable', () => {
  describe('DELETE is never retried (delete_block is index-based, not idempotent)', () => {
    it('does not retry DELETE on 502/503/504', () => {
      expect(isRetryable(httpError('delete', 502))).toBe(false);
      expect(isRetryable(httpError('delete', 503))).toBe(false);
      expect(isRetryable(httpError('delete', 504))).toBe(false);
    });

    it('does not retry DELETE on network timeouts/resets', () => {
      expect(isRetryable(networkError('delete', 'ETIMEDOUT'))).toBe(false);
      expect(isRetryable(networkError('delete', 'ECONNRESET'))).toBe(false);
      expect(isRetryable(networkError('delete', 'ENETUNREACH'))).toBe(false);
    });

    it('still retries DELETE on 429 (server returns 429 before doing any work)', () => {
      expect(isRetryable(httpError('delete', 429))).toBe(true);
    });
  });

  describe('truly safe verbs are still retried', () => {
    it('retries GET on 503 and on timeout', () => {
      expect(isRetryable(httpError('get', 503))).toBe(true);
      expect(isRetryable(networkError('get', 'ETIMEDOUT'))).toBe(true);
    });

    it('retries HEAD/OPTIONS on gateway errors', () => {
      expect(isRetryable(httpError('head', 502))).toBe(true);
      expect(isRetryable(httpError('options', 504))).toBe(true);
    });
  });

  describe('writes are not retried on ambiguous failures', () => {
    it('does not retry POST/PATCH/PUT on 502 or timeout', () => {
      expect(isRetryable(httpError('post', 502))).toBe(false);
      expect(isRetryable(httpError('patch', 503))).toBe(false);
      expect(isRetryable(networkError('put', 'ETIMEDOUT'))).toBe(false);
    });

    it('retries any method on 429 (rate limited before work)', () => {
      expect(isRetryable(httpError('post', 429))).toBe(true);
      expect(isRetryable(httpError('patch', 429))).toBe(true);
    });

    it('never retries ECONNREFUSED (wrong URL / WP down)', () => {
      expect(isRetryable(networkError('get', 'ECONNREFUSED'))).toBe(false);
    });
  });
});
