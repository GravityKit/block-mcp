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

import { describe, it, expect, afterEach, vi } from 'vitest';
import * as os from 'node:os';
import * as fs from 'node:fs';
import * as path from 'node:path';
import {
  writeClaudeDesktopConfig,
  writeCursorConfig,
  parseConnectArgs,
  printConfig,
  normalizeSite,
  browserOpenCommand,
  handleCallback,
  exchangeCode,
} from '../src/connect.js';
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

// ── [CLI-F2] App password must not print to stdout by default ──────────────────
//
// The bare `connect --site …` invocation defaulted to print mode, dumping the
// cleartext password to stdout (shell scrollback, tmux/script buffers, CI
// logs). The secret must be redacted unless the user explicitly opts in via
// `--reveal` or `--client print`. Pins the default-redaction and both opt-ins.

describe('[CLI-F2] app password redacted from stdout by default', () => {
  const SITE = 'https://example.com';

  it('default invocation does not opt into revealing the secret', () => {
    expect(parseConnectArgs(['--site', SITE]).reveal).toBe(false);
  });

  it('--reveal opts into revealing the secret', () => {
    expect(parseConnectArgs(['--site', SITE, '--reveal']).reveal).toBe(true);
  });

  it('explicit --client print opts into revealing the secret', () => {
    expect(parseConnectArgs(['--site', SITE, '--client', 'print']).reveal).toBe(true);
  });

  it('printConfig redacts the password when reveal is false', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    try {
      printConfig(CREDS, false);
      const out = log.mock.calls.map((c) => c.join(' ')).join('\n');
      expect(out).not.toContain(CREDS.password);
    } finally {
      log.mockRestore();
    }
  });

  it('printConfig prints the password when reveal is true', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    try {
      printConfig(CREDS, true);
      const out = log.mock.calls.map((c) => c.join(' ')).join('\n');
      expect(out).toContain(CREDS.password);
    } finally {
      log.mockRestore();
    }
  });
});

// ── [CLI-F3] --site validation + Windows browser-open without cmd.exe ───────────
//
// normalizeSite previously checked only the http(s):// prefix, so a crafted
// --site like "https://x/&calc.exe" reached cmd.exe's `start` on Windows, which
// re-parses & | ^ < > " as metacharacters → argument/command injection. Fix is
// two-pronged: reject shell metacharacters in --site, and open the browser on
// Windows via rundll32 (no cmd.exe re-parsing) instead of `cmd /c start`.

describe('[CLI-F3] --site rejects shell metacharacters', () => {
  it('rejects an ampersand (cmd command separator)', () => {
    expect(() => normalizeSite('https://x/&calc.exe')).toThrow();
  });

  it('rejects a pipe', () => {
    expect(() => normalizeSite('https://example.com/|whoami')).toThrow();
  });

  it('rejects whitespace', () => {
    expect(() => normalizeSite('https://example.com/ foo')).toThrow();
  });

  it('rejects a double quote', () => {
    expect(() => normalizeSite('https://example.com/"x')).toThrow();
  });

  it('still accepts a clean https site URL', () => {
    expect(normalizeSite('https://example.com')).toBe('https://example.com');
  });

  it('still accepts a clean site URL with a path', () => {
    expect(normalizeSite('https://example.com/wp')).toBe('https://example.com/wp');
  });
});

describe('[CLI-F3] Windows browser-open avoids cmd.exe', () => {
  it('does not invoke cmd.exe on win32', () => {
    expect(browserOpenCommand('https://example.com', 'win32').cmd).not.toBe('cmd');
  });

  it('does not pass the URL through `start` / `/c` on win32', () => {
    const { args } = browserOpenCommand('https://example.com', 'win32');
    expect(args).not.toContain('start');
    expect(args).not.toContain('/c');
  });

  it('uses rundll32 FileProtocolHandler on win32', () => {
    const { cmd, args } = browserOpenCommand('https://example.com', 'win32');
    expect(cmd).toBe('rundll32');
    expect(args[0]).toBe('url.dll,FileProtocolHandler');
    expect(args).toContain('https://example.com');
  });

  it('uses open on darwin', () => {
    expect(browserOpenCommand('https://example.com', 'darwin')).toEqual({
      cmd: 'open',
      args: ['https://example.com'],
    });
  });

  it('uses xdg-open on linux', () => {
    expect(browserOpenCommand('https://example.com', 'linux')).toEqual({
      cmd: 'xdg-open',
      args: ['https://example.com'],
    });
  });
});

// ── [WP-F3] Credential is exchanged out-of-band, never carried in the URL ───────
//
// The minted password is a site-wide, non-expiring credential. Previously it
// was delivered to the loopback callback in the redirect URL query string,
// landing in browser history and exposed via Referer. The callback now carries
// only a single-use code; the connector POSTs that code to the exchange
// endpoint and receives the credential in the response body. Pins that the
// callback surfaces no credential and that exchangeCode reads it from the body.

describe('[WP-F3] credential delivered via exchange, not the callback URL', () => {
  const STATE = 'state-token-xyz';
  const CODE = 'deadbeefcafef00d1122334455667788';

  it('the loopback callback carries no password — only the code', () => {
    const url = `/callback?code=${CODE}&state=${STATE}&password=should-be-ignored`;
    const result = handleCallback(url, STATE);
    expect(result.ok).toBe(true);
    // Even if a password param were present, handleCallback must not surface it.
    expect(JSON.stringify(result)).not.toContain('should-be-ignored');
    expect(result.ok && result.code).toBe(CODE);
  });

  it('exchangeCode POSTs the code to the REST exchange route and returns the body credential', async () => {
    let posted: { url: string; body: string } | null = null;
    const fakeFetch = (async (url: string, init: { body: string }) => {
      posted = { url, body: init.body };
      return {
        status: 200,
        headers: new Headers(),
        json: async () => ({
          success: true,
          data: { site: 'https://example.com', user: 'block-mcp', password: 'exchanged-secret' },
        }),
      };
    }) as unknown as typeof fetch;

    const creds = await exchangeCode('https://example.com', CODE, fakeFetch);

    expect(creds.password).toBe('exchanged-secret');
    // REST transport via the permalink-independent ?rest_route form — admin-post.php
    // is 30x'd by canonical/SSL/Redirection rules.
    expect(posted!.url).toContain('rest_route=/gk-block-api/v1/connect/exchange');
    expect(posted!.url).not.toContain('admin-post.php');
    expect(posted!.body).toContain(`"code":"${CODE}"`);
  });

  it('exchangeCode throws when the site rejects the code', async () => {
    const fakeFetch = (async () => ({
      status: 400,
      headers: new Headers(),
      json: async () => ({ success: false, data: { message: 'Invalid or expired code.' } }),
    })) as unknown as typeof fetch;

    await expect(exchangeCode('https://example.com', 'bad-code', fakeFetch)).rejects.toThrow();
  });

  it('[CONN7] exchangeCode follows a same-origin redirect, refuses cross-origin, and bounds with an abort signal', async () => {
    // (a) redirect mode is 'manual' and a timeout-backed AbortSignal bounds a hung site.
    let init: { redirect?: string; signal?: unknown } | undefined;
    const okFetch = (async (_url: string, opts: { redirect?: string; signal?: unknown }) => {
      init = opts;
      return { status: 200, headers: new Headers(), json: async () => ({ success: true, data: { site: 's', user: 'u', password: 'p' } }) };
    }) as unknown as typeof fetch;
    await exchangeCode('https://example.com', CODE, okFetch);
    expect(init!.redirect).toBe('manual');
    expect(init!.signal instanceof AbortSignal).toBe(true);

    // (b) a SAME-ORIGIN redirect (e.g. http->https / trailing slash) is followed by
    // re-POSTing the body, so the exchange still completes.
    const urls: string[] = [];
    const sameOriginFetch = (async (url: string) => {
      urls.push(url);
      if (urls.length === 1) {
        return {
          status: 301,
          headers: new Headers({ location: 'https://example.com/wp-json/gk-block-api/v1/connect/exchange/' }),
          json: async () => ({}),
        };
      }
      return { status: 200, headers: new Headers(), json: async () => ({ success: true, data: { site: 's', user: 'u', password: 'p' } }) };
    }) as unknown as typeof fetch;
    const creds = await exchangeCode('https://example.com', CODE, sameOriginFetch);
    expect(creds.password).toBe('p');
    expect(urls.length).toBe(2); // the same-origin redirect was followed (re-POSTed).

    // (c) a CROSS-ORIGIN redirect is REFUSED — the credential must never be POSTed off-site.
    const crossOriginFetch = (async () => ({
      status: 302,
      headers: new Headers({ location: 'https://evil.example.net/steal' }),
      json: async () => ({}),
    })) as unknown as typeof fetch;
    await expect(exchangeCode('https://example.com', CODE, crossOriginFetch)).rejects.toThrow(/different origin/);
  });
});
