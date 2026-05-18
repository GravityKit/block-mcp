/**
 * Runtime shape-checking helpers.
 *
 * Zero new dependencies — uses typeof + key-presence to validate objects
 * match the core interfaces from src/types.ts.
 *
 * These are assertion helpers for tests: they throw (via Vitest's expect)
 * when an object is missing required keys or has a key of the wrong primitive
 * type.
 *
 * Usage:
 *   assertSavedBlock(result.saved);
 *   assertBlock(blocks[0]);
 */
import { expect } from 'vitest';

// ── Generic key-presence check ───────────────────────────────────────────────

/**
 * Assert that `value` is a non-null object containing all listed keys.
 * Fails the test with a descriptive message when any key is absent.
 */
export function assertHasKeys<T extends object>(
  value: unknown,
  keys: (keyof T)[],
  label = 'object'
): asserts value is T {
  expect(value, `${label} must not be null/undefined`).toBeDefined();
  expect(typeof value, `${label} must be an object`).toBe('object');
  const obj = value as Record<string, unknown>;
  for (const key of keys as string[]) {
    expect(obj, `${label} must have key "${key}"`).toHaveProperty(key);
  }
}

// ── Interface-specific assertions ────────────────────────────────────────────

/**
 * Assert `value` conforms to the SavedBlock interface.
 * Checks: flat_index (number), block_name (string), attributes (object),
 * inner_html (string), is_dynamic (boolean).
 */
export function assertSavedBlock(value: unknown): void {
  assertHasKeys(value, ['flat_index', 'block_name', 'attributes', 'inner_html', 'is_dynamic'], 'SavedBlock');
  const b = value as Record<string, unknown>;
  expect(typeof b.flat_index, 'SavedBlock.flat_index must be number').toBe('number');
  expect(typeof b.block_name, 'SavedBlock.block_name must be string').toBe('string');
  expect(typeof b.attributes, 'SavedBlock.attributes must be object').toBe('object');
  expect(typeof b.inner_html, 'SavedBlock.inner_html must be string').toBe('string');
  expect(typeof b.is_dynamic, 'SavedBlock.is_dynamic must be boolean').toBe('boolean');
}

/**
 * Assert `value` conforms to the Block interface.
 * Checks: index (number), name (string), attributes (object).
 */
export function assertBlock(value: unknown): void {
  assertHasKeys(value, ['index', 'name', 'attributes'], 'Block');
  const b = value as Record<string, unknown>;
  expect(typeof b.index, 'Block.index must be number').toBe('number');
  expect(typeof b.name, 'Block.name must be string').toBe('string');
  expect(b.name as string).toMatch(/^\w[\w-]*\/[\w-]+$/, 'Block.name must be namespace/name');
  expect(typeof b.attributes, 'Block.attributes must be object').toBe('object');
}

/**
 * Assert `value` conforms to BlockUpdateResponse.
 */
export function assertBlockUpdateResponse(value: unknown): void {
  assertHasKeys(value, ['success', 'block', 'saved', 'before_revision_id', 'revision_id'], 'BlockUpdateResponse');
  const r = value as Record<string, unknown>;
  expect(r.success).toBe(true);
  assertSavedBlock(r.saved);
  expect(typeof r.before_revision_id, 'before_revision_id must be number').toBe('number');
  expect(typeof r.revision_id, 'revision_id must be number').toBe('number');
}

/**
 * Assert `value` conforms to BlockWriteResponse (insert / replace-all).
 */
export function assertBlockWriteResponse(value: unknown): void {
  assertHasKeys(value, ['success', 'inserted', 'warnings', 'before_revision_id', 'revision_id'], 'BlockWriteResponse');
  const r = value as Record<string, unknown>;
  expect(r.success).toBe(true);
  expect(Array.isArray(r.inserted), 'inserted must be an array').toBe(true);
  expect(Array.isArray(r.warnings), 'warnings must be an array').toBe(true);
}

/**
 * Assert `value` conforms to MutationResponse.
 */
export function assertMutationResponse(value: unknown): void {
  assertHasKeys(value, ['success', 'op', 'path', 'warnings', 'before_revision_id', 'revision_id'], 'MutationResponse');
  const r = value as Record<string, unknown>;
  expect(r.success).toBe(true);
  expect(typeof r.op).toBe('string');
  expect(Array.isArray(r.path)).toBe(true);
}

/**
 * Assert `value` is a valid PreferenceWarning.
 */
export function assertPreferenceWarning(value: unknown): void {
  assertHasKeys(value, ['block', 'message'], 'PreferenceWarning');
  const w = value as Record<string, unknown>;
  expect(typeof w.block).toBe('string');
  expect(typeof w.message).toBe('string');
}

/**
 * Assert `value` conforms to PostMutationResponse.
 */
export function assertPostMutationResponse(value: unknown): void {
  assertHasKeys(value, ['success', 'id', 'post_type', 'status', 'title', 'slug', 'permalink'], 'PostMutationResponse');
  const r = value as Record<string, unknown>;
  expect(r.success).toBe(true);
  expect(typeof r.id).toBe('number');
}

/**
 * Assert `value` conforms to YoastSEOMeta.
 */
export function assertYoastSEOMeta(value: unknown): void {
  assertHasKeys(value, ['post_id'], 'YoastSEOMeta');
  const r = value as Record<string, unknown>;
  expect(typeof r.post_id).toBe('number');
}
