/**
 * Integration harness for runConnect() — the connect subcommand's orchestrator.
 *
 * connect.test.ts and connect.security.test.ts exercise the pure helpers
 * (parsing, URL building, config writing, exchangeCode) in isolation. This file
 * drives the whole flow through runConnect: it starts the REAL loopback server,
 * simulates the browser hitting the callback after approval, lets the code be
 * exchanged for the credential (global fetch stubbed), and asserts the client
 * config is written. Nothing leaves the machine — fetch is stubbed and the
 * "browser" is the injected openBrowserFn firing an HTTP request at 127.0.0.1.
 *
 * Only no-process.exit paths are covered here: the happy path and the
 * invalid-callback-is-ignored path (CONN4). The error paths call process.exit(1)
 * — which would tear down the test runner — and are covered by the unit tests on
 * handleCallback / exchangeCode instead.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import * as os from 'node:os';
import * as fs from 'node:fs';
import * as path from 'node:path';
import * as http from 'node:http';
import { runConnect } from '../src/connect.js';

const ORIGINAL_HOME = process.env.HOME;

/** Redirect homedir-derived config paths (cursor) at a fresh temp dir. */
function mkTmpHome(): string {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'gk-runconnect-'));
  process.env.HOME = home;
  return home;
}

/**
 * Fire a GET at the loopback callback the way the browser would after approval.
 * Resolves once the response is consumed so the socket doesn't linger.
 */
function hitCallback(callback: string, code: string, state: string): void {
  const u = new URL(callback);
  u.searchParams.set('code', code);
  u.searchParams.set('state', state);
  http.get(u.toString(), (res) => res.resume()).on('error', () => {});
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  if (ORIGINAL_HOME === undefined) {
    delete process.env.HOME;
  } else {
    process.env.HOME = ORIGINAL_HOME;
  }
});

/**
 * Stub global fetch so the credential exchange returns a fixed credential
 * without touching the network. Returns the captured request for assertions.
 */
function stubExchangeFetch(password: string): { posted: { url: string; body: string } | null } {
  const captured: { posted: { url: string; body: string } | null } = { posted: null };
  const fakeFetch = (async (url: string, init: { body: string }) => {
    captured.posted = { url, body: init.body };
    return {
      status: 200,
      json: async () => ({
        success: true,
        data: { site: 'https://example.com', user: 'block-mcp', password },
      }),
    };
  }) as unknown as typeof fetch;
  vi.stubGlobal('fetch', fakeFetch);
  return captured;
}

describe('runConnect end-to-end (loopback → exchange → config write)', () => {
  it('drives a full happy path and writes the exchanged credential to the cursor config', async () => {
    const home = mkTmpHome();
    const exchange = stubExchangeFetch('exchanged-secret');
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});

    // The injected "browser": parse the authorize URL it is handed, then hit the
    // loopback callback with a valid code + the connect-issued state.
    const openBrowserFn = (authorizeUrl: string): void => {
      const u = new URL(authorizeUrl);
      const callback = u.searchParams.get('callback');
      const state = u.searchParams.get('state');
      expect(callback).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/callback$/);
      expect(state).toBeTruthy();
      hitCallback(callback as string, 'deadbeefcafef00d1122334455667788', state as string);
    };

    await runConnect(
      ['--site', 'https://example.com', '--client', 'cursor', '--name', 'block-mcp'],
      { openBrowserFn, timeoutMs: 5_000 }
    );

    // The exchange POST targeted the out-of-band REST endpoint with the callback code…
    expect(exchange.posted).not.toBeNull();
    expect(exchange.posted!.url).toContain('rest_route=/gk-block-api/v1/connect/exchange');
    expect(exchange.posted!.url).not.toContain('admin-post.php');
    expect(exchange.posted!.body).toContain('"code":"deadbeefcafef00d1122334455667788"');

    // …and the credential from the response body was written to ~/.cursor/mcp.json.
    const file = path.join(home, '.cursor', 'mcp.json');
    expect(fs.existsSync(file)).toBe(true);
    const cfg = JSON.parse(fs.readFileSync(file, 'utf8'));
    const entry = cfg.mcpServers['block-mcp'];
    expect(entry).toBeTruthy();
    expect(entry.env.WORDPRESS_APP_PASSWORD).toBe('exchanged-secret');
    expect(entry.env.WORDPRESS_URL).toBe('https://example.com');
  });

  it('[CONN4] ignores an invalid-state callback and still completes on the valid one', async () => {
    const home = mkTmpHome();
    stubExchangeFetch('second-try-secret');
    vi.spyOn(console, 'log').mockImplementation(() => {});
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    const openBrowserFn = (authorizeUrl: string): void => {
      const u = new URL(authorizeUrl);
      const callback = u.searchParams.get('callback') as string;
      const state = u.searchParams.get('state') as string;
      // A forged / wrong-state callback must NOT settle the pending connect…
      hitCallback(callback, 'attacker-code', 'WRONG-STATE');
      // …the genuine, state-matching callback (a beat later) still completes it.
      setTimeout(() => hitCallback(callback, 'goodcode12345678', state), 25);
    };

    await runConnect(
      ['--site', 'https://example.com', '--client', 'cursor', '--name', 'block-mcp'],
      { openBrowserFn, timeoutMs: 5_000 }
    );

    // The bad callback was logged as ignored, not fatal.
    const errs = errSpy.mock.calls.map((c) => c.join(' ')).join('\n');
    expect(errs).toContain('Ignoring an invalid callback');

    // The valid callback drove the exchange and the config write.
    const cfg = JSON.parse(fs.readFileSync(path.join(home, '.cursor', 'mcp.json'), 'utf8'));
    expect(cfg.mcpServers['block-mcp'].env.WORDPRESS_APP_PASSWORD).toBe('second-try-secret');
  });
});
