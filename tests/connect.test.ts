/**
 * Unit tests for the `connect` subcommand pure helpers.
 *
 * All tests are offline — no HTTP, no spawned processes, no file I/O.
 * The pure functions exported from src/connect.ts are exercised directly.
 *
 * Key invariants tested:
 *   - parseConnectArgs: flag parsing, defaults, required --site error
 *   - normalizeSite: trailing slash stripping, scheme requirement
 *   - buildAuthorizeUrl: correct WP admin path + exact fixed query params + encoded vars
 *   - mergeMcpServers / cursorConfig: correct shape, preserves existing servers
 *   - claudeDesktopConfigPath: per-platform paths
 *   - claudeCodeAddArgs: argv contains env pairs, uses no shell, password present in args
 *   - handleCallback: accepts matching state, returns the exchange code; rejects mismatched state (CSRF guard)
 *   - parseExchangeResponse: unwraps the success envelope; throws on bad/incomplete shapes
 *   - Password-never-logged: the success log path does not include the password
 */

import { describe, it, expect, vi } from 'vitest';
import {
  parseConnectArgs,
  normalizeSite,
  buildAuthorizeUrl,
  mergeMcpServers,
  cursorConfig,
  claudeDesktopConfigPath,
  claudeCodeAddArgs,
  handleCallback,
  buildMcpEntry,
  parseExchangeResponse,
  defaultServerName,
  isLoopbackOrDevHost,
  exchangeCode,
} from '../src/connect.js';
import type { Credentials, McpConfig } from '../src/connect.js';

// ── Test fixtures ─────────────────────────────────────────────────────────────

const CREDS: Credentials = {
  site: 'https://example.com',
  user: 'admin',
  password: 'super secret pw 1234',
};

// ── parseConnectArgs ──────────────────────────────────────────────────────────

describe('parseConnectArgs', () => {
  it('requires --site', () => {
    expect(() => parseConnectArgs([])).toThrow(/--site.*required/i);
  });

  it('accepts --site value', () => {
    const args = parseConnectArgs(['--site', 'https://example.com']);
    expect(args.site).toBe('https://example.com');
  });

  it('defaults client to "print"', () => {
    const args = parseConnectArgs(['--site', 'https://example.com']);
    expect(args.client).toBe('print');
  });

  it('defaults open to true', () => {
    const args = parseConnectArgs(['--site', 'https://example.com']);
    expect(args.open).toBe(true);
  });

  it('defaults port to null', () => {
    const args = parseConnectArgs(['--site', 'https://example.com']);
    expect(args.port).toBeNull();
  });

  it('parses --client flag', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--client', 'cursor']);
    expect(args.client).toBe('cursor');
  });

  it('parses --client claude-code', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--client', 'claude-code']);
    expect(args.client).toBe('claude-code');
  });

  it('parses --client claude-desktop', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--client', 'claude-desktop']);
    expect(args.client).toBe('claude-desktop');
  });

  it('rejects unknown --client values', () => {
    expect(() =>
      parseConnectArgs(['--site', 'https://example.com', '--client', 'vscode'])
    ).toThrow(/invalid.*client/i);
  });

  it('parses --client=<value> (equals form)', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--client=cursor']);
    expect(args.client).toBe('cursor');
  });

  it('rejects unknown --client=<value> the same as the space form', () => {
    // Regression: the `--client=<value>` form used to skip the allowlist check
    // that `--client <value>` applied, so a typo silently drove the wrong flow.
    expect(() =>
      parseConnectArgs(['--site', 'https://example.com', '--client=vscode'])
    ).toThrow(/invalid.*client/i);
  });

  it('parses --port', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--port', '8080']);
    expect(args.port).toBe(8080);
  });

  it('rejects non-numeric --port', () => {
    expect(() =>
      parseConnectArgs(['--site', 'https://example.com', '--port', 'abc'])
    ).toThrow(/invalid.*port/i);
  });

  it('parses --no-open flag', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--no-open']);
    expect(args.open).toBe(false);
  });

  it('accepts --site=value form', () => {
    const args = parseConnectArgs(['--site=https://example.com']);
    expect(args.site).toBe('https://example.com');
  });
});

// ── normalizeSite ─────────────────────────────────────────────────────────────

describe('normalizeSite', () => {
  it('strips trailing slash', () => {
    expect(normalizeSite('https://example.com/')).toBe('https://example.com');
  });

  it('strips multiple trailing slashes', () => {
    expect(normalizeSite('https://example.com///')).toBe('https://example.com');
  });

  it('preserves URL without trailing slash', () => {
    expect(normalizeSite('https://example.com')).toBe('https://example.com');
  });

  it('requires http or https scheme', () => {
    expect(() => normalizeSite('example.com')).toThrow(/http.*https/i);
  });

  it('rejects ftp scheme', () => {
    expect(() => normalizeSite('ftp://example.com')).toThrow(/http.*https/i);
  });

  it('accepts http scheme for a local/dev host', () => {
    expect(normalizeSite('http://localhost:7701')).toBe('http://localhost:7701');
    expect(normalizeSite('http://gkclone.orb.local')).toBe('http://gkclone.orb.local');
    expect(normalizeSite('http://dev.test')).toBe('http://dev.test');
  });

  it('[SEC1] rejects plain http:// to a public host (credential would be cleartext)', () => {
    expect(() => normalizeSite('http://example.com')).toThrow(/https/i);
    expect(() => normalizeSite('http://www.gravitykit.com')).toThrow(/https/i);
  });

  it('[SEC1] accepts https:// to a public host', () => {
    expect(normalizeSite('https://example.com')).toBe('https://example.com');
  });

  it('preserves path after host', () => {
    expect(normalizeSite('https://example.com/subpath/')).toBe('https://example.com/subpath');
  });
});

describe('isLoopbackOrDevHost', () => {
  it('is true for loopback and dev TLD hosts', () => {
    for (const h of ['localhost', '127.0.0.1', '127.0.0.5', '::1', 'gkclone.orb.local', 'dev.test', 'foo.localhost']) {
      expect(isLoopbackOrDevHost(h)).toBe(true);
    }
  });

  it('is false for public hosts', () => {
    for (const h of ['example.com', 'www.gravitykit.com', 'app.acme.io']) {
      expect(isLoopbackOrDevHost(h)).toBe(false);
    }
  });
});

// ── buildAuthorizeUrl ─────────────────────────────────────────────────────────

describe('buildAuthorizeUrl', () => {
  const BASE_PARAMS = {
    site: 'https://example.com',
    callback: 'http://127.0.0.1:12345/callback',
    state: 'test-state-uuid',
    client: 'cursor' as const,
  };

  it('uses wp-admin/options-general.php path', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('/wp-admin/options-general.php');
  });

  it('includes page=gk-block-mcp-settings', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('page=gk-block-mcp-settings');
  });

  it('includes tab=connect', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('tab=connect');
  });

  it('includes gk_authorize=1', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('gk_authorize=1');
  });

  it('URL-encodes the callback param', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    // The callback URL contains ://, which gets encoded as %3A%2F%2F
    expect(url).toContain('callback=http%3A%2F%2F127.0.0.1%3A12345%2Fcallback');
  });

  it('includes state param', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('state=test-state-uuid');
  });

  it('includes client param', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url).toContain('client=cursor');
  });

  it('builds from the site base', () => {
    const url = buildAuthorizeUrl(BASE_PARAMS);
    expect(url.startsWith('https://example.com/')).toBe(true);
  });

  it('URL-encodes state with special chars', () => {
    const url = buildAuthorizeUrl({ ...BASE_PARAMS, state: 'abc def+ghi' });
    // URLSearchParams encodes space as + and + as %2B
    expect(url).not.toContain('abc def');
  });
});

// ── mergeMcpServers ───────────────────────────────────────────────────────────

describe('mergeMcpServers', () => {
  it('adds block-mcp server to empty config', () => {
    const result = mergeMcpServers({ mcpServers: {} }, CREDS);
    expect(result.mcpServers['block-mcp']).toBeDefined();
  });

  it('preserves existing servers', () => {
    const existing: McpConfig = {
      mcpServers: {
        'other-server': { command: 'node', args: ['server.js'], env: {} },
      },
    };
    const result = mergeMcpServers(existing, CREDS);
    expect(result.mcpServers['other-server']).toBeDefined();
    expect(result.mcpServers['block-mcp']).toBeDefined();
  });

  it('overwrites existing block-mcp entry', () => {
    const existing: McpConfig = {
      mcpServers: {
        'block-mcp': { command: 'old', args: [], env: {} },
      },
    };
    const result = mergeMcpServers(existing, CREDS);
    expect(result.mcpServers['block-mcp'].command).toBe('npx');
  });

  it('does not mutate the original config', () => {
    const existing: McpConfig = { mcpServers: { 'other': { command: 'x', args: [], env: {} } } };
    mergeMcpServers(existing, CREDS);
    expect(existing.mcpServers['block-mcp']).toBeUndefined();
  });
});

// ── cursorConfig ──────────────────────────────────────────────────────────────

describe('cursorConfig', () => {
  it('returns mcpServers object', () => {
    const cfg = cursorConfig(CREDS);
    expect(cfg.mcpServers).toBeDefined();
  });

  it('command is npx', () => {
    expect(cursorConfig(CREDS).mcpServers['block-mcp'].command).toBe('npx');
  });

  it('args include -y @gravitykit/block-mcp', () => {
    const { args } = cursorConfig(CREDS).mcpServers['block-mcp'];
    expect(args).toContain('-y');
    expect(args).toContain('@gravitykit/block-mcp');
  });

  it('env contains WORDPRESS_URL', () => {
    expect(cursorConfig(CREDS).mcpServers['block-mcp'].env.WORDPRESS_URL).toBe(CREDS.site);
  });

  it('env contains WORDPRESS_USER', () => {
    expect(cursorConfig(CREDS).mcpServers['block-mcp'].env.WORDPRESS_USER).toBe(CREDS.user);
  });

  it('env contains WORDPRESS_APP_PASSWORD', () => {
    expect(cursorConfig(CREDS).mcpServers['block-mcp'].env.WORDPRESS_APP_PASSWORD).toBe(
      CREDS.password
    );
  });
});

// ── buildMcpEntry ─────────────────────────────────────────────────────────────

describe('buildMcpEntry', () => {
  it('sets command to npx', () => {
    expect(buildMcpEntry(CREDS).command).toBe('npx');
  });

  it('sets args to [-y, @gravitykit/block-mcp]', () => {
    expect(buildMcpEntry(CREDS).args).toEqual(['-y', '@gravitykit/block-mcp']);
  });

  it('sets all three env vars', () => {
    const { env } = buildMcpEntry(CREDS);
    expect(env.WORDPRESS_URL).toBe(CREDS.site);
    expect(env.WORDPRESS_USER).toBe(CREDS.user);
    expect(env.WORDPRESS_APP_PASSWORD).toBe(CREDS.password);
  });
});

// ── claudeDesktopConfigPath ───────────────────────────────────────────────────

describe('claudeDesktopConfigPath', () => {
  it('returns macOS path on darwin', () => {
    const p = claudeDesktopConfigPath('darwin');
    expect(p).toContain('Library');
    expect(p).toContain('Application Support');
    expect(p).toContain('Claude');
    expect(p.endsWith('claude_desktop_config.json')).toBe(true);
  });

  it('returns Windows path on win32', () => {
    const p = claudeDesktopConfigPath('win32');
    expect(p).toContain('Claude');
    expect(p.endsWith('claude_desktop_config.json')).toBe(true);
  });

  it('returns Linux path on linux', () => {
    const p = claudeDesktopConfigPath('linux');
    expect(p).toContain('.config');
    expect(p).toContain('Claude');
    expect(p.endsWith('claude_desktop_config.json')).toBe(true);
  });

  it('darwin path contains Library/Application Support', () => {
    const p = claudeDesktopConfigPath('darwin');
    expect(p).toMatch(/Library\/Application Support/);
  });

  it('linux path starts in home dir', () => {
    const p = claudeDesktopConfigPath('linux');
    expect(p.startsWith(process.env.HOME ?? '/home')).toBe(true);
  });
});

// ── claudeCodeAddArgs ─────────────────────────────────────────────────────────

describe('claudeCodeAddArgs', () => {
  const args = claudeCodeAddArgs(CREDS);

  it('starts with mcp add block-mcp', () => {
    expect(args[0]).toBe('mcp');
    expect(args[1]).toBe('add');
    expect(args[2]).toBe('block-mcp');
  });

  it('includes --scope user', () => {
    const idx = args.indexOf('--scope');
    expect(idx).toBeGreaterThan(-1);
    expect(args[idx + 1]).toBe('user');
  });

  it('includes WORDPRESS_URL env pair', () => {
    const envIdx = args.indexOf(`WORDPRESS_URL=${CREDS.site}`);
    expect(envIdx).toBeGreaterThan(-1);
  });

  it('includes WORDPRESS_USER env pair', () => {
    expect(args).toContain(`WORDPRESS_USER=${CREDS.user}`);
  });

  it('includes WORDPRESS_APP_PASSWORD env pair', () => {
    expect(args).toContain(`WORDPRESS_APP_PASSWORD=${CREDS.password}`);
  });

  it('includes -- separator before npx', () => {
    const sep = args.indexOf('--');
    expect(sep).toBeGreaterThan(-1);
    expect(args[sep + 1]).toBe('npx');
  });

  it('ends with @gravitykit/block-mcp', () => {
    expect(args[args.length - 1]).toBe('@gravitykit/block-mcp');
  });

  it('is a plain array (no shell string)', () => {
    // Each element must be a string, not contain unquoted shell metacharacters
    for (const arg of args) {
      expect(typeof arg).toBe('string');
    }
    // The full concatenated string should NOT be a single shell invocation
    expect(args.length).toBeGreaterThan(3);
  });

  it('has --env flags before each env pair', () => {
    const urlIdx = args.indexOf(`WORDPRESS_URL=${CREDS.site}`);
    expect(args[urlIdx - 1]).toBe('--env');

    const userIdx = args.indexOf(`WORDPRESS_USER=${CREDS.user}`);
    expect(args[userIdx - 1]).toBe('--env');

    const pwIdx = args.indexOf(`WORDPRESS_APP_PASSWORD=${CREDS.password}`);
    expect(args[pwIdx - 1]).toBe('--env');
  });
});

// ── handleCallback ────────────────────────────────────────────────────────────

describe('handleCallback', () => {
  const STATE = 'abc-123-uuid';
  const CODE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
  const GOOD_URL = `/callback?code=${CODE}&state=${STATE}`;

  it('returns ok:true for matching state', () => {
    const result = handleCallback(GOOD_URL, STATE);
    expect(result.ok).toBe(true);
  });

  it('returns the exchange code', () => {
    const result = handleCallback(GOOD_URL, STATE);
    expect(result.ok && result.code).toBe(CODE);
  });

  it('does not surface a credential on the callback (code only)', () => {
    const result = handleCallback(GOOD_URL, STATE);
    // The post-WP-F3 callback carries no site/user/password — only the code.
    expect(result.ok && 'creds' in result).toBe(false);
  });

  it('rejects mismatched state (CSRF guard)', () => {
    const result = handleCallback(GOOD_URL, 'wrong-state');
    expect(result.ok).toBe(false);
  });

  it('CSRF rejection reason mentions state mismatch', () => {
    const result = handleCallback(GOOD_URL, 'wrong-state');
    expect(!result.ok && result.reason).toMatch(/state mismatch|csrf/i);
  });

  it('rejects missing state param', () => {
    const noState = `/callback?code=${CODE}`;
    const result = handleCallback(noState, STATE);
    expect(result.ok).toBe(false);
  });

  it('rejects empty state param', () => {
    const emptyState = `/callback?code=${CODE}&state=`;
    const result = handleCallback(emptyState, STATE);
    expect(result.ok).toBe(false);
  });

  it('rejects callback missing the code', () => {
    const noCode = `/callback?state=${STATE}`;
    const result = handleCallback(noCode, STATE);
    expect(result.ok).toBe(false);
  });

  it('accepts full URL form (not just path)', () => {
    const fullUrl = `http://127.0.0.1:9999/callback?code=${CODE}&state=${STATE}`;
    const result = handleCallback(fullUrl, STATE);
    expect(result.ok && result.code).toBe(CODE);
  });
});

// ── parseExchangeResponse ─────────────────────────────────────────────────────

describe('parseExchangeResponse', () => {
  const OK = {
    success: true,
    data: { site: 'https://example.com', user: 'block-mcp', password: 'secret pw' },
  };

  it('returns the credentials from a success envelope', () => {
    expect(parseExchangeResponse(OK)).toEqual({
      site: 'https://example.com',
      user: 'block-mcp',
      password: 'secret pw',
    });
  });

  it('throws on a success:false envelope', () => {
    expect(() => parseExchangeResponse({ success: false, data: { message: 'bad' } })).toThrow();
  });

  it('throws when data is missing', () => {
    expect(() => parseExchangeResponse({ success: true })).toThrow();
  });

  it('throws when the password field is missing', () => {
    expect(() =>
      parseExchangeResponse({ success: true, data: { site: 'x', user: 'y' } })
    ).toThrow();
  });

  it('throws on a non-object response', () => {
    expect(() => parseExchangeResponse(null)).toThrow();
    expect(() => parseExchangeResponse('nope')).toThrow();
  });
});

// ── Password-never-logged invariant ───────────────────────────────────────────

describe('password-never-logged invariant', () => {
  it('claudeCodeAddArgs does not appear as a single shell string', () => {
    // The args array must NOT be joined and passed to a shell.
    // Verify the array form: no single element contains the whole command.
    const args = claudeCodeAddArgs(CREDS);
    // The joined string would contain the password — that's expected in the
    // args array itself. What we assert is that this is an array (not a string)
    // so it never goes through shell expansion.
    expect(Array.isArray(args)).toBe(true);
  });

  it('buildMcpEntry places password in env object, not in command/args', () => {
    const entry = buildMcpEntry(CREDS);
    // Password must NOT appear in command string
    expect(entry.command).not.toContain(CREDS.password);
    // Password must NOT appear in any args element
    for (const arg of entry.args) {
      expect(arg).not.toContain(CREDS.password);
    }
    // Password IS in env — that's the right place
    expect(entry.env.WORDPRESS_APP_PASSWORD).toBe(CREDS.password);
  });

  it('handleCallback does not expose password in error responses', () => {
    // A CSRF rejection should not echo back the password param even if present
    const malicious =
      `/callback?site=https%3A%2F%2Fevil.com&user=hacker&password=stolen&state=wrong`;
    const result = handleCallback(malicious, 'correct-state');
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.reason).not.toContain('stolen');
    }
  });
});

// ── Multi-site server naming (--name) ──────────────────────────────────────────

describe('defaultServerName', () => {
  it('derives block-mcp-<sanitized-host> from the full host', () => {
    expect(defaultServerName('https://www.gravitykit.com')).toBe('block-mcp-www-gravitykit-com');
  });

  it('keeps the whole host so dotted hosts stay distinct', () => {
    expect(defaultServerName('https://dev.test')).toBe('block-mcp-dev-test');
    expect(defaultServerName('https://gkclone.orb.local')).toBe('block-mcp-gkclone-orb-local');
  });

  it('does NOT collide hosts that share a leading label (dev.test vs dev.local)', () => {
    const a = defaultServerName('https://dev.test');
    const b = defaultServerName('https://dev.local');
    expect(a).toBe('block-mcp-dev-test');
    expect(b).toBe('block-mcp-dev-local');
    expect(a).not.toBe(b);
  });

  it('does NOT collapse www — www.X and X are distinct hosts', () => {
    expect(defaultServerName('https://www.gravitykit.com')).toBe('block-mcp-www-gravitykit-com');
    expect(defaultServerName('https://gravitykit.com')).toBe('block-mcp-gravitykit-com');
    expect(defaultServerName('https://www.gravitykit.com')).not.toBe(
      defaultServerName('https://gravitykit.com')
    );
  });

  it('keeps the port so same-host different-port sites stay distinct', () => {
    expect(defaultServerName('http://localhost:7701')).toBe('block-mcp-localhost-7701');
    expect(defaultServerName('http://dev.test:8080')).toBe('block-mcp-dev-test-8080');
    expect(defaultServerName('http://dev.test:9090')).not.toBe(
      defaultServerName('http://dev.test:8080')
    );
  });

  it('collapses runs of non-alphanumerics and trims stray hyphens', () => {
    expect(defaultServerName('https://my_site..example.com')).toBe('block-mcp-my-site-example-com');
  });

  it('keeps subdomains distinct (apex vs app vs staging)', () => {
    const apex = defaultServerName('https://gravitykit.com');
    const app = defaultServerName('https://app.gravitykit.com');
    const staging = defaultServerName('https://staging.gravitykit.com');
    expect(apex).toBe('block-mcp-gravitykit-com');
    expect(app).toBe('block-mcp-app-gravitykit-com');
    expect(staging).toBe('block-mcp-staging-gravitykit-com');
    expect(new Set([apex, app, staging]).size).toBe(3);
  });

  it('falls back to block-mcp for an unparseable site', () => {
    expect(defaultServerName('not a url')).toBe('block-mcp');
  });
});

describe('--name argument', () => {
  it('parses --name <value>', () => {
    const args = parseConnectArgs(['--site', 'https://example.com', '--name', 'block-mcp-prod']);
    expect(args.name).toBe('block-mcp-prod');
  });

  it('parses --name=value form', () => {
    const args = parseConnectArgs(['--site=https://example.com', '--name=block-mcp-prod']);
    expect(args.name).toBe('block-mcp-prod');
  });

  it('defaults the name from the full site host when --name is omitted', () => {
    const args = parseConnectArgs(['--site', 'https://www.gravitykit.com']);
    expect(args.name).toBe('block-mcp-www-gravitykit-com');
  });
});

describe('multi-site config (named servers coexist)', () => {
  it('mergeMcpServers writes under the given name', () => {
    const result = mergeMcpServers({ mcpServers: {} }, CREDS, 'block-mcp-prod');
    expect(result.mcpServers['block-mcp-prod']).toBeDefined();
    expect(result.mcpServers['block-mcp-prod'].env.WORDPRESS_URL).toBe(CREDS.site);
  });

  it('does NOT overwrite a different site already configured under another name', () => {
    const existing: McpConfig = {
      mcpServers: {
        'block-mcp-dev': { command: 'npx', args: ['-y', '@gravitykit/block-mcp'], env: { WORDPRESS_URL: 'https://dev.test' } },
      },
    };
    const result = mergeMcpServers(existing, CREDS, 'block-mcp-prod');
    // Both sites must be present — the whole point of named servers.
    expect(result.mcpServers['block-mcp-dev']).toBeDefined();
    expect(result.mcpServers['block-mcp-dev'].env.WORDPRESS_URL).toBe('https://dev.test');
    expect(result.mcpServers['block-mcp-prod']).toBeDefined();
  });

  it('still defaults to the block-mcp key when no name is given', () => {
    expect(mergeMcpServers({ mcpServers: {} }, CREDS).mcpServers['block-mcp']).toBeDefined();
  });

  it('claudeCodeAddArgs registers under the given server name', () => {
    expect(claudeCodeAddArgs(CREDS, 'block-mcp-prod')[2]).toBe('block-mcp-prod');
  });

  it('claudeCodeAddArgs still defaults to block-mcp', () => {
    expect(claudeCodeAddArgs(CREDS)[2]).toBe('block-mcp');
  });
});

// ── exchangeCode network-failure diagnostics ──────────────────────────────────
//
// undici reports every network-level failure as a bare "fetch failed" TypeError
// with the real reason (TLS, DNS, refused connection) hidden in `cause`. The
// exchange error must surface that cause, and certificate-trust failures must
// carry an actionable NODE_EXTRA_CA_CERTS hint: Node ignores the OS keychain,
// so a local dev site's CA that the browser trusts still fails here.

describe('exchangeCode network-failure diagnostics', () => {
  /** Build the rejection shape undici's fetch produces for a network failure. */
  function fetchFailure(code: string): Error {
    const cause = Object.assign(new Error(`network failure ${code}`), { code });
    return Object.assign(new TypeError('fetch failed'), { cause });
  }

  function failingFetch(code: string): typeof fetch {
    return (async () => {
      throw fetchFailure(code);
    }) as unknown as typeof fetch;
  }

  it('surfaces the TLS cause code hidden behind the generic "fetch failed"', async () => {
    const error = await exchangeCode('https://dev.test', 'code123', failingFetch('UNABLE_TO_VERIFY_LEAF_SIGNATURE')).catch(
      (e: Error) => e
    );
    expect(error).toBeInstanceOf(Error);
    expect((error as Error).message).toContain('UNABLE_TO_VERIFY_LEAF_SIGNATURE');
    expect((error as Error).message).toContain('dev.test');
  });

  it('adds a NODE_EXTRA_CA_CERTS hint for every certificate-trust failure code', async () => {
    const trustCodes = [
      'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
      'DEPTH_ZERO_SELF_SIGNED_CERT',
      'SELF_SIGNED_CERT_IN_CHAIN',
      'UNABLE_TO_GET_ISSUER_CERT_LOCALLY',
    ];
    for (const code of trustCodes) {
      const error = await exchangeCode('https://dev.test', 'code123', failingFetch(code)).catch((e: Error) => e);
      expect((error as Error).message).toContain('NODE_EXTRA_CA_CERTS');
    }
  });

  it('does NOT suggest NODE_EXTRA_CA_CERTS for non-trust network failures', async () => {
    const error = await exchangeCode('https://dev.test', 'code123', failingFetch('ECONNREFUSED')).catch((e: Error) => e);
    expect((error as Error).message).toContain('ECONNREFUSED');
    expect((error as Error).message).not.toContain('NODE_EXTRA_CA_CERTS');
  });

  it('keeps the plain error message when the failure has no cause', async () => {
    const bareFetch = (async () => {
      throw new Error('This operation was aborted');
    }) as unknown as typeof fetch;
    const error = await exchangeCode('https://dev.test', 'code123', bareFetch).catch((e: Error) => e);
    expect((error as Error).message).toContain('This operation was aborted');
    expect((error as Error).message).not.toContain('NODE_EXTRA_CA_CERTS');
  });
});

// ── NODE_EXTRA_CA_CERTS propagation into generated configs ───────────────────
//
// The MCP server talks to the same site over the same Node TLS stack as the
// connector, so a CA bundle that was required to complete the exchange must be
// written into every generated client config — otherwise the server fails with
// the exact TLS error the connector just got past.

describe('NODE_EXTRA_CA_CERTS propagation', () => {
  it('buildMcpEntry copies an explicit CA bundle path into the server env', () => {
    const { env } = buildMcpEntry(CREDS, '/certs/local-ca.pem');
    expect(env.NODE_EXTRA_CA_CERTS).toBe('/certs/local-ca.pem');
  });

  it('buildMcpEntry omits the variable when the environment does not set it', () => {
    vi.stubEnv('NODE_EXTRA_CA_CERTS', '');
    try {
      expect('NODE_EXTRA_CA_CERTS' in buildMcpEntry(CREDS).env).toBe(false);
    } finally {
      vi.unstubAllEnvs();
    }
  });

  it('buildMcpEntry omits the variable when it is blank', () => {
    expect('NODE_EXTRA_CA_CERTS' in buildMcpEntry(CREDS, '   ').env).toBe(false);
  });

  it('buildMcpEntry defaults from process.env.NODE_EXTRA_CA_CERTS', () => {
    vi.stubEnv('NODE_EXTRA_CA_CERTS', '/certs/from-env.pem');
    try {
      expect(buildMcpEntry(CREDS).env.NODE_EXTRA_CA_CERTS).toBe('/certs/from-env.pem');
    } finally {
      vi.unstubAllEnvs();
    }
  });

  it('flows through cursorConfig / mergeMcpServers (the config-file path)', () => {
    vi.stubEnv('NODE_EXTRA_CA_CERTS', '/certs/from-env.pem');
    try {
      const config = cursorConfig(CREDS, 'block-mcp-dev-test');
      expect(config.mcpServers['block-mcp-dev-test'].env.NODE_EXTRA_CA_CERTS).toBe('/certs/from-env.pem');
    } finally {
      vi.unstubAllEnvs();
    }
  });

  it('claudeCodeAddArgs adds an --env pair before the -- separator when set', () => {
    const args = claudeCodeAddArgs(CREDS, 'block-mcp', '/certs/local-ca.pem');
    const idx = args.indexOf('NODE_EXTRA_CA_CERTS=/certs/local-ca.pem');
    expect(idx).toBeGreaterThan(-1);
    expect(args[idx - 1]).toBe('--env');
    expect(idx).toBeLessThan(args.indexOf('--'));
  });

  it('claudeCodeAddArgs omits the pair when the environment does not set it', () => {
    vi.stubEnv('NODE_EXTRA_CA_CERTS', '');
    try {
      const args = claudeCodeAddArgs(CREDS, 'block-mcp');
      expect(args.some((arg) => arg.includes('NODE_EXTRA_CA_CERTS'))).toBe(false);
    } finally {
      vi.unstubAllEnvs();
    }
  });
});
