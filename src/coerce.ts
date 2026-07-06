/**
 * Input coercion helpers shared across the MCP tool handlers.
 *
 * MCP clients and untyped JSON transports vary in how they encode a post ID:
 * some send the JSON number `123`, others the string `"123"`. The tool surface
 * must behave identically for both, or the same post is editable through one
 * tool and rejected by another in the same session.
 */

/**
 * Coerce a caller-supplied post ID to a positive integer.
 *
 * Accepts a JSON number that is a positive integer, or a string of digits
 * (`"123"`). Returns `undefined` when the value is absent (`undefined`/`null`),
 * so callers with an alternate selector (url/slug) can allow that. Throws for a
 * present-but-invalid value (float, negative, non-numeric string), with the
 * `label:` prefix the calling tool uses in its other errors.
 *
 * @param value - Raw post_id from the tool arguments.
 * @param label - Tool name used to prefix the thrown error message.
 * @returns The positive integer, or undefined when the value is absent.
 */
export function coercePostId(value: unknown, label: string): number | undefined {
  if (value === undefined || value === null) {
    return undefined;
  }
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
    return value;
  }
  if (typeof value === 'string' && /^[0-9]+$/.test(value)) {
    const parsed = parseInt(value, 10);
    // Re-apply the positive check so a zero-valued string ("0", "00") is
    // rejected exactly like the number 0, not silently accepted as 0.
    if (parsed > 0) {
      return parsed;
    }
  }
  throw new Error(`${label}: post_id must be a positive integer`);
}
