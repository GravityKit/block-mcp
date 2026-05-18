/**
 * Assertion helpers for tool-layer test assertions.
 *
 * Provide lean wrappers that express domain intent rather than raw vitest
 * matcher chains, making test bodies more readable.
 */
import { expect } from 'vitest';
import type { MockClient } from './mock-client.js';

// ── Tool result helpers ──────────────────────────────────────────────────────

/**
 * Assert a tool result signals success and has the expected top-level keys.
 */
export function assertToolSuccess(
  result: unknown,
  requiredKeys: string[] = ['success']
): void {
  expect(result).toBeDefined();
  expect(typeof result).toBe('object');
  const r = result as Record<string, unknown>;
  for (const key of requiredKeys) {
    expect(r, `result must have key "${key}"`).toHaveProperty(key);
  }
  if ('success' in r) {
    expect(r.success).toBe(true);
  }
}

/**
 * Assert a tool result contains formatted_warnings with at least one entry
 * that matches the expected substring.
 */
export function assertHasFormattedWarning(result: unknown, containing: string): void {
  const r = result as Record<string, unknown>;
  expect(r.formatted_warnings, 'formatted_warnings must be defined').toBeDefined();
  const warnings = r.formatted_warnings as string[];
  expect(Array.isArray(warnings)).toBe(true);
  expect(warnings.length).toBeGreaterThan(0);
  const found = warnings.some((w) => w.includes(containing));
  expect(found, `No warning contains "${containing}". Got: ${JSON.stringify(warnings)}`).toBe(true);
}

/**
 * Assert a tool result has NO formatted_warnings (clean path).
 */
export function assertNoFormattedWarnings(result: unknown): void {
  const r = result as Record<string, unknown>;
  expect(r.formatted_warnings).toBeUndefined();
}

// ── Client call helpers ──────────────────────────────────────────────────────

/**
 * Assert a named client method was called exactly once with the given args.
 * Passes args to toHaveBeenCalledWith — supports asymmetric matchers.
 */
export function assertClientCalled(
  client: MockClient,
  method: keyof MockClient,
  ...expectedArgs: unknown[]
): void {
  const fn = client[method] as ReturnType<typeof import('vitest').vi.fn>;
  expect(fn).toHaveBeenCalledTimes(1);
  if (expectedArgs.length > 0) {
    expect(fn).toHaveBeenCalledWith(...expectedArgs);
  }
}

/**
 * Assert a named client method was NOT called.
 */
export function assertClientNotCalled(client: MockClient, method: keyof MockClient): void {
  const fn = client[method] as ReturnType<typeof import('vitest').vi.fn>;
  expect(fn).not.toHaveBeenCalled();
}

// ── Revision pair helper ──────────────────────────────────────────────────────

/**
 * Assert result has both revision IDs with before < after (sanity check).
 */
export function assertRevisionPair(result: unknown): void {
  const r = result as Record<string, unknown>;
  expect(typeof r.before_revision_id, 'before_revision_id must be number').toBe('number');
  expect(typeof r.revision_id, 'revision_id must be number').toBe('number');
  expect(r.revision_id as number).toBeGreaterThan(r.before_revision_id as number);
}
