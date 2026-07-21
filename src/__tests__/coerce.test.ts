import { describe, it, expect } from 'vitest';
import { coercePostId } from '../coerce.js';

/**
 * Unit tests for the shared coercePostId() helper.
 *
 * Every post_id tool (get_post_info, update_post, yoast_*) inherits this
 * helper, so its "positive integer" contract must hold uniformly — a numeric
 * string is accepted identically to the number, and a zero/negative/non-numeric
 * value is rejected identically. The zero-string cases pin the fix for the gap
 * where "0"/"00" parsed to 0 while the number 0 threw.
 */
describe('coercePostId', () => {
  it('returns undefined for absent values', () => {
    expect(coercePostId(undefined, 't')).toBeUndefined();
    expect(coercePostId(null, 't')).toBeUndefined();
  });

  it('accepts a positive integer number', () => {
    expect(coercePostId(123, 't')).toBe(123);
  });

  it('coerces a positive numeric string', () => {
    expect(coercePostId('123', 't')).toBe(123);
    expect(coercePostId('042', 't')).toBe(42);
  });

  it('rejects the number 0 and negatives', () => {
    expect(() => coercePostId(0, 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId(-1, 't')).toThrow('post_id must be a positive integer');
  });

  it('rejects zero-valued strings identically to the number 0', () => {
    expect(() => coercePostId('0', 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('00', 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('000', 't')).toThrow('post_id must be a positive integer');
  });

  it('rejects floats and non-numeric strings', () => {
    expect(() => coercePostId(1.5, 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('12.5', 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('abc', 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('12abc', 't')).toThrow('post_id must be a positive integer');
  });

  it('rejects integers above Number.MAX_SAFE_INTEGER', () => {
    // Beyond 2^53-1 the number can no longer represent the exact id, so a
    // parsed/rounded value would target the wrong post.
    expect(() => coercePostId(Number.MAX_SAFE_INTEGER + 1, 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('9007199254740993', 't')).toThrow('post_id must be a positive integer');
    expect(() => coercePostId('99999999999999999999', 't')).toThrow('post_id must be a positive integer');
  });

  it('prefixes the thrown error with the caller label', () => {
    expect(() => coercePostId('x', 'update_post')).toThrow('update_post: post_id must be a positive integer');
  });
});
