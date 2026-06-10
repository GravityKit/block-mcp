/**
 * Tool-argument validation for the MCP dispatch.
 *
 * The MCP SDK does not validate CallTool arguments against a tool's
 * `inputSchema`, so a misnamed parameter (e.g. `after` instead of
 * `after_top_level`) was silently dropped and the handler ran with its
 * defaults — turning a positioning mistake into a wrong-but-"successful" write.
 *
 * This rejects unknown top-level keys loudly — naming the valid ones with a
 * "did you mean" suggestion — and flags missing required keys, before the
 * handler runs.
 */

interface InputSchema {
  properties?: Record<string, unknown>;
  required?: string[];
  additionalProperties?: unknown;
}

/**
 * Throw a descriptive Error if `args` carries keys the tool doesn't declare, or
 * omits a required key. No-op when the tool has no schema, or when the schema
 * explicitly opts into additional properties.
 */
export function validateToolArgs(
  toolName: string,
  inputSchema: InputSchema | undefined,
  args: Record<string, unknown>,
): void {
  const properties = inputSchema?.properties;
  if (!properties) {
    return; // No declared shape — nothing to validate against.
  }

  const known = Object.keys(properties);
  const provided = Object.keys(args ?? {});

  // Respect an explicit opt-in to extra props (a map-shaped schema sets
  // `additionalProperties` to a schema object rather than leaving it absent).
  const allowsExtra =
    inputSchema?.additionalProperties !== undefined && inputSchema.additionalProperties !== false;

  if (!allowsExtra) {
    const unknown = provided.filter((k) => !known.includes(k));
    if (unknown.length > 0) {
      const parts = unknown.map((k) => {
        const near = closestKey(k, known);
        return near ? `'${k}' (did you mean '${near}'?)` : `'${k}'`;
      });
      throw new Error(
        `Unknown parameter(s) for ${toolName}: ${parts.join(', ')}. ` +
          `Valid parameters: ${known.join(', ')}.`,
      );
    }
  }

  const required = inputSchema?.required ?? [];
  const missing = required.filter((r) => !(r in (args ?? {})));
  if (missing.length > 0) {
    throw new Error(`Missing required parameter(s) for ${toolName}: ${missing.join(', ')}.`);
  }
}

/**
 * Best-effort suggestion for a mistyped key. Prefers a known key that shares a
 * prefix/substring with the input — the common agent mistake is a shortened
 * name like `after` for `after_top_level` — then falls back to edit distance.
 */
function closestKey(input: string, candidates: string[]): string | null {
  const lc = input.toLowerCase();
  const affixed = candidates
    .filter((c) => {
      const cl = c.toLowerCase();
      return cl.startsWith(lc) || lc.startsWith(cl) || cl.includes(lc) || lc.includes(cl);
    })
    .sort((a, b) => a.length - b.length);
  if (affixed.length > 0) {
    return affixed[0];
  }

  let best: string | null = null;
  let bestDist = Infinity;
  for (const c of candidates) {
    const d = levenshtein(lc, c.toLowerCase());
    if (d < bestDist) {
      bestDist = d;
      best = c;
    }
  }
  return best !== null && bestDist <= Math.max(2, Math.ceil(input.length / 2)) ? best : null;
}

function levenshtein(a: string, b: string): number {
  const m = a.length;
  const n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  let prev = Array.from({ length: n + 1 }, (_, i) => i);
  let curr = new Array<number>(n + 1);
  for (let i = 1; i <= m; i++) {
    curr[0] = i;
    for (let j = 1; j <= n; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      curr[j] = Math.min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cost);
    }
    [prev, curr] = [curr, prev];
  }
  return prev[n];
}
