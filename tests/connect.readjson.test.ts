/**
 * Tests for readJsonFile error handling.
 *
 * Pins the contract that a malformed JSON config is NOT silently treated as
 * empty (which would clobber every other MCP server the user had configured).
 * Only a missing file (ENOENT) falls back to the default; any other failure —
 * an unreadable file or a parse error — propagates so the caller surfaces it.
 */
import { describe, it, expect, afterEach } from 'vitest';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { readJsonFile } from '../src/connect.js';
import type { McpConfig } from '../src/connect.js';

const DEFAULT: McpConfig = { mcpServers: {} };

let scratch: string[] = [];

afterEach(() => {
  for (const p of scratch) {
    try {
      fs.rmSync(p, { recursive: true, force: true });
    } catch {
      /* best-effort */
    }
  }
  scratch = [];
});

function tmpFile(contents: string): string {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'block-mcp-readjson-'));
  scratch.push(dir);
  const file = path.join(dir, 'mcp.json');
  fs.writeFileSync(file, contents, 'utf8');
  return file;
}

describe('readJsonFile', () => {
  it('returns the default when the file is missing (ENOENT)', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'block-mcp-readjson-'));
    scratch.push(dir);
    const missing = path.join(dir, 'does-not-exist.json');
    expect(readJsonFile(missing, DEFAULT)).toEqual(DEFAULT);
  });

  it('parses a valid existing config', () => {
    const file = tmpFile(JSON.stringify({ mcpServers: { other: { command: 'x', args: [], env: {} } } }));
    const cfg = readJsonFile(file, DEFAULT);
    expect(cfg.mcpServers.other).toBeDefined();
  });

  it('throws (does not silently return default) on malformed JSON so the file is not clobbered', () => {
    // A user's hand-edited config with a trailing comma / truncation.
    const file = tmpFile('{ "mcpServers": { "other": } NOT JSON');
    expect(() => readJsonFile(file, DEFAULT)).toThrow(/could not parse/i);
  });
});
