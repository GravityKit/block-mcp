/**
 * Security regression tests for the `connect` subcommand.
 *
 * Unlike connect.test.ts (offline, pure helpers), these tests exercise the
 * real file-I/O and side-effecting paths to pin security controls so they
 * cannot be silently removed. Each block names the finding it covers.
 *
 * POSIX-only assertions (file modes) are skipped on win32, where the
 * underlying chmod is a no-op.
 */

import { describe, it, expect, afterEach } from 'vitest';
import * as os from 'node:os';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { writeClaudeDesktopConfig, writeCursorConfig } from '../src/connect.js';
import type { Credentials } from '../src/connect.js';

const CREDS: Credentials = {
  site: 'https://example.com',
  user: 'admin',
  password: 'super secret pw 1234',
};

const isWin = process.platform === 'win32';

const ORIGINAL_HOME = process.env.HOME;

/**
 * Point os.homedir() at a fresh temp dir for the duration of one test.
 * On POSIX, os.homedir() reads $HOME, so overriding it redirects every
 * homedir-derived config path without mocking the os module.
 */
function mkTmpHome(): string {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'gk-connect-sec-'));
  process.env.HOME = home;
  return home;
}

afterEach(() => {
  if (ORIGINAL_HOME === undefined) {
    delete process.env.HOME;
  } else {
    process.env.HOME = ORIGINAL_HOME;
  }
});

// ── [CLI-F1] Client config files must not be world-readable ────────────────────
//
// The written config embeds the WordPress Application Password (site-wide
// credential) in cleartext. On a shared host any other local user could read
// it if it landed at the default 0644. The writer must create the file 0600
// under a 0700 parent, AND tighten a pre-existing loose-perm file via chmod
// (writeFileSync's mode only applies at creation). Pins both halves so the
// confirmed shared-host at-rest exposure (audit Chain B) can't regress.

describe('[CLI-F1] config files written owner-only', () => {
  it.skipIf(isWin)('writeClaudeDesktopConfig creates the file mode 0600', () => {
    const home = mkTmpHome();

    writeClaudeDesktopConfig(CREDS, 'linux');

    const file = path.join(home, '.config', 'Claude', 'claude_desktop_config.json');
    const mode = fs.statSync(file).mode & 0o777;
    expect(mode).toBe(0o600);
  });

  it.skipIf(isWin)('writeClaudeDesktopConfig parent dir has no group/other access', () => {
    const home = mkTmpHome();

    writeClaudeDesktopConfig(CREDS, 'linux');

    const dir = path.join(home, '.config', 'Claude');
    const mode = fs.statSync(dir).mode & 0o077;
    expect(mode).toBe(0);
  });

  it.skipIf(isWin)('tightens a pre-existing world-readable config to 0600', () => {
    const home = mkTmpHome();

    // Simulate a config left behind by another tool at the default loose mode.
    const dir = path.join(home, '.cursor');
    fs.mkdirSync(dir, { recursive: true });
    const file = path.join(dir, 'mcp.json');
    fs.writeFileSync(file, '{"mcpServers":{}}\n', { encoding: 'utf8', mode: 0o644 });
    fs.chmodSync(file, 0o644);
    expect(fs.statSync(file).mode & 0o777).toBe(0o644); // precondition

    writeCursorConfig(CREDS);

    expect(fs.statSync(file).mode & 0o777).toBe(0o600);
  });

  it.skipIf(isWin)('writeCursorConfig creates the file mode 0600', () => {
    const home = mkTmpHome();

    writeCursorConfig(CREDS);

    const file = path.join(home, '.cursor', 'mcp.json');
    expect(fs.statSync(file).mode & 0o777).toBe(0o600);
  });
});
