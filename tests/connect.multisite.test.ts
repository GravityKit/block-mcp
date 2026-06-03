/**
 * Multi-site connection tests.
 *
 * Pointing Block MCP at several WordPress sites means registering one MCP
 * server entry per site, under a distinct name, each carrying its own
 * WORDPRESS_URL / WORDPRESS_USER / WORDPRESS_APP_PASSWORD. "Works properly"
 * means:
 *   - distinct sites get distinct, readable default names;
 *   - connecting site B never overwrites site A in the same config;
 *   - every entry's credentials stay isolated (no cross-contamination);
 *   - re-connecting the same site updates that one entry in place;
 *   - unrelated MCP servers already in the config are preserved;
 *   - this holds across Claude Desktop, Cursor, and Claude Code.
 *
 * File-writer tests redirect os.homedir() via $HOME (POSIX) to a temp dir, so
 * they're skipped on win32. Pure tests run everywhere.
 */

import { describe, it, expect, afterEach } from 'vitest';
import * as os from 'node:os';
import * as fs from 'node:fs';
import * as path from 'node:path';
import {
  defaultServerName,
  parseConnectArgs,
  mergeMcpServers,
  cursorConfig,
  claudeCodeAddArgs,
  writeClaudeDesktopConfig,
  writeCursorConfig,
} from '../src/connect.js';
import type { Credentials, McpConfig } from '../src/connect.js';

const isWin = process.platform === 'win32';

const SITE_A: Credentials = { site: 'https://www.gravitykit.com', user: 'block-mcp', password: 'pw-A-1111' };
const SITE_B: Credentials = { site: 'https://dev.test', user: 'block-mcp', password: 'pw-B-2222' };
const SITE_C: Credentials = { site: 'https://gkclone.orb.local', user: 'block-mcp', password: 'pw-C-3333' };

const NAME_A = 'block-mcp-www-gravitykit-com';
const NAME_B = 'block-mcp-dev-test';
const NAME_C = 'block-mcp-gkclone-orb-local';

const ORIGINAL_HOME = process.env.HOME;

function mkTmpHome(): string {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'gk-multisite-'));
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

// ── Naming: distinct sites → distinct names ─────────────────────────────────────

describe('multi-site: default names are distinct per site', () => {
  it('the three example sites resolve to three different names', () => {
    const names = [SITE_A, SITE_B, SITE_C].map((c) => defaultServerName(c.site));
    expect(names).toEqual([NAME_A, NAME_B, NAME_C]);
    expect(new Set(names).size).toBe(3); // all unique
  });

  it('parseConnectArgs derives a distinct name for each site', () => {
    const a = parseConnectArgs(['--site', SITE_A.site]).name;
    const b = parseConnectArgs(['--site', SITE_B.site]).name;
    const c = parseConnectArgs(['--site', SITE_C.site]).name;
    expect(new Set([a, b, c]).size).toBe(3);
    expect([a, b, c]).toEqual([NAME_A, NAME_B, NAME_C]);
  });

  it('lowercases the host but keeps www distinct from the apex', () => {
    expect(defaultServerName('https://WWW.GravityKit.com')).toBe(NAME_A); // case-insensitive
    expect(defaultServerName('https://gravitykit.com')).toBe('block-mcp-gravitykit-com');
    expect(defaultServerName('https://www.gravitykit.com')).not.toBe(
      defaultServerName('https://gravitykit.com')
    );
  });

  it('hosts that share a leading label do NOT collide (full host is used)', () => {
    const names = [
      defaultServerName('https://dev.test'),
      defaultServerName('https://dev.local'),
      defaultServerName('https://dev.example.com'),
    ];
    expect(names).toEqual(['block-mcp-dev-test', 'block-mcp-dev-local', 'block-mcp-dev-example-com']);
    expect(new Set(names).size).toBe(3);
  });

  it('different subdomains of the same domain get distinct names', () => {
    const names = [
      defaultServerName('https://acme.com'),
      defaultServerName('https://app.acme.com'),
      defaultServerName('https://staging.acme.com'),
    ];
    expect(names).toEqual([
      'block-mcp-acme-com',
      'block-mcp-app-acme-com',
      'block-mcp-staging-acme-com',
    ]);
    expect(new Set(names).size).toBe(3);
  });

  it('--name still overrides the derived name', () => {
    expect(parseConnectArgs(['--site', 'https://app.acme.com', '--name', 'block-mcp-acme-staging']).name)
      .toBe('block-mcp-acme-staging');
  });
});

// ── mergeMcpServers: isolation, idempotency, immutability, coexistence ──────────

describe('multi-site: mergeMcpServers keeps sites independent', () => {
  it('three sites merged in sequence all survive with the right URLs', () => {
    let cfg: McpConfig = { mcpServers: {} };
    cfg = mergeMcpServers(cfg, SITE_A, NAME_A);
    cfg = mergeMcpServers(cfg, SITE_B, NAME_B);
    cfg = mergeMcpServers(cfg, SITE_C, NAME_C);

    expect(Object.keys(cfg.mcpServers).sort()).toEqual([NAME_A, NAME_B, NAME_C].sort());
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_URL).toBe(SITE_A.site);
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_URL).toBe(SITE_B.site);
    expect(cfg.mcpServers[NAME_C].env.WORDPRESS_URL).toBe(SITE_C.site);
  });

  it('each entry carries its own credentials (no cross-contamination)', () => {
    let cfg: McpConfig = { mcpServers: {} };
    cfg = mergeMcpServers(cfg, SITE_A, NAME_A);
    cfg = mergeMcpServers(cfg, SITE_B, NAME_B);

    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).toBe(SITE_A.password);
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_APP_PASSWORD).toBe(SITE_B.password);
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).not.toBe(SITE_B.password);
  });

  it('re-connecting the same site updates that entry in place, leaving others intact', () => {
    let cfg: McpConfig = { mcpServers: {} };
    cfg = mergeMcpServers(cfg, SITE_A, NAME_A);
    cfg = mergeMcpServers(cfg, SITE_B, NAME_B);

    const rotated: Credentials = { ...SITE_A, password: 'pw-A-rotated' };
    cfg = mergeMcpServers(cfg, rotated, NAME_A);

    expect(Object.keys(cfg.mcpServers)).toHaveLength(2); // no duplicate
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).toBe('pw-A-rotated');
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_APP_PASSWORD).toBe(SITE_B.password); // untouched
  });

  it('preserves an unrelated, non-block-mcp server already in the config', () => {
    const existing: McpConfig = {
      mcpServers: { sentry: { command: 'npx', args: ['-y', 'sentry-mcp'], env: {} } },
    };
    const cfg = mergeMcpServers(existing, SITE_A, NAME_A);
    expect(cfg.mcpServers.sentry).toBeDefined();
    expect(cfg.mcpServers[NAME_A]).toBeDefined();
  });

  it('does not mutate the input config when adding a second site', () => {
    const first = mergeMcpServers({ mcpServers: {} }, SITE_A, NAME_A);
    const second = mergeMcpServers(first, SITE_B, NAME_B);
    expect(first.mcpServers[NAME_B]).toBeUndefined(); // original untouched
    expect(second.mcpServers[NAME_B]).toBeDefined();
  });

  it('cursorConfig honors the per-site name', () => {
    expect(cursorConfig(SITE_B, NAME_B).mcpServers[NAME_B].env.WORDPRESS_URL).toBe(SITE_B.site);
  });
});

// ── Claude Code argv: per-site name + isolated env ──────────────────────────────

describe('multi-site: claudeCodeAddArgs is per-site', () => {
  it('registers each site under its own name', () => {
    expect(claudeCodeAddArgs(SITE_A, NAME_A)[2]).toBe(NAME_A);
    expect(claudeCodeAddArgs(SITE_B, NAME_B)[2]).toBe(NAME_B);
  });

  it('carries the correct site URL per call', () => {
    expect(claudeCodeAddArgs(SITE_A, NAME_A)).toContain(`WORDPRESS_URL=${SITE_A.site}`);
    expect(claudeCodeAddArgs(SITE_B, NAME_B)).toContain(`WORDPRESS_URL=${SITE_B.site}`);
    expect(claudeCodeAddArgs(SITE_A, NAME_A)).not.toContain(`WORDPRESS_URL=${SITE_B.site}`);
  });
});

// ── End-to-end file writes: sites coexist in one real config file ───────────────

describe('multi-site: Claude Desktop config accumulates sites', () => {
  function readDesktop(home: string): McpConfig {
    const file = path.join(home, '.config', 'Claude', 'claude_desktop_config.json');
    return JSON.parse(fs.readFileSync(file, 'utf8')) as McpConfig;
  }

  it.skipIf(isWin)('writing three sites leaves three independent entries', () => {
    const home = mkTmpHome();
    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);
    writeClaudeDesktopConfig(SITE_C, 'linux', NAME_C);

    const cfg = readDesktop(home);
    expect(Object.keys(cfg.mcpServers).sort()).toEqual([NAME_A, NAME_B, NAME_C].sort());
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_URL).toBe(SITE_A.site);
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_URL).toBe(SITE_B.site);
    expect(cfg.mcpServers[NAME_C].env.WORDPRESS_URL).toBe(SITE_C.site);
  });

  it.skipIf(isWin)('each written entry keeps its own password', () => {
    const home = mkTmpHome();
    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);

    const cfg = readDesktop(home);
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).toBe(SITE_A.password);
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_APP_PASSWORD).toBe(SITE_B.password);
  });

  it.skipIf(isWin)('re-connecting one site updates it in place without disturbing the others', () => {
    const home = mkTmpHome();
    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);
    writeClaudeDesktopConfig({ ...SITE_A, password: 'pw-A-rotated' }, 'linux', NAME_A);

    const cfg = readDesktop(home);
    expect(Object.keys(cfg.mcpServers)).toHaveLength(2);
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).toBe('pw-A-rotated');
    expect(cfg.mcpServers[NAME_B].env.WORDPRESS_APP_PASSWORD).toBe(SITE_B.password);
  });

  it.skipIf(isWin)('preserves a pre-existing unrelated server across multiple site writes', () => {
    const home = mkTmpHome();
    const dir = path.join(home, '.config', 'Claude');
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(
      path.join(dir, 'claude_desktop_config.json'),
      JSON.stringify({ mcpServers: { sentry: { command: 'npx', args: ['-y', 'sentry-mcp'], env: {} } } }),
      'utf8'
    );

    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);

    const cfg = readDesktop(home);
    expect(cfg.mcpServers.sentry).toBeDefined();
    expect(cfg.mcpServers[NAME_A]).toBeDefined();
    expect(cfg.mcpServers[NAME_B]).toBeDefined();
  });

  it.skipIf(isWin)('the file remains valid JSON after several writes', () => {
    const home = mkTmpHome();
    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);
    writeClaudeDesktopConfig(SITE_C, 'linux', NAME_C);
    // readDesktop throws if the JSON is malformed.
    expect(() => readDesktop(home)).not.toThrow();
  });

  it.skipIf(isWin)('the multi-site config file stays owner-only (0600)', () => {
    const home = mkTmpHome();
    writeClaudeDesktopConfig(SITE_A, 'linux', NAME_A);
    writeClaudeDesktopConfig(SITE_B, 'linux', NAME_B);
    const file = path.join(home, '.config', 'Claude', 'claude_desktop_config.json');
    expect(fs.statSync(file).mode & 0o777).toBe(0o600);
  });

  it.skipIf(isWin)('two subdomains of the same domain coexist as separate entries', () => {
    const home = mkTmpHome();
    const app: Credentials = { site: 'https://app.acme.com', user: 'block-mcp', password: 'pw-app' };
    const stg: Credentials = { site: 'https://staging.acme.com', user: 'block-mcp', password: 'pw-stg' };
    writeClaudeDesktopConfig(app, 'linux', defaultServerName(app.site));
    writeClaudeDesktopConfig(stg, 'linux', defaultServerName(stg.site));

    const cfg = readDesktop(home);
    expect(Object.keys(cfg.mcpServers).sort()).toEqual(
      ['block-mcp-app-acme-com', 'block-mcp-staging-acme-com'].sort()
    );
    expect(cfg.mcpServers['block-mcp-app-acme-com'].env.WORDPRESS_URL).toBe(app.site);
    expect(cfg.mcpServers['block-mcp-staging-acme-com'].env.WORDPRESS_URL).toBe(stg.site);
    expect(cfg.mcpServers['block-mcp-app-acme-com'].env.WORDPRESS_APP_PASSWORD).toBe('pw-app');
    expect(cfg.mcpServers['block-mcp-staging-acme-com'].env.WORDPRESS_APP_PASSWORD).toBe('pw-stg');
  });
});

describe('multi-site: Cursor config accumulates sites', () => {
  it.skipIf(isWin)('two sites coexist in ~/.cursor/mcp.json with isolated env', () => {
    const home = mkTmpHome();
    writeCursorConfig(SITE_A, NAME_A);
    writeCursorConfig(SITE_C, NAME_C);

    const cfg = JSON.parse(
      fs.readFileSync(path.join(home, '.cursor', 'mcp.json'), 'utf8')
    ) as McpConfig;

    expect(Object.keys(cfg.mcpServers).sort()).toEqual([NAME_A, NAME_C].sort());
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_URL).toBe(SITE_A.site);
    expect(cfg.mcpServers[NAME_C].env.WORDPRESS_URL).toBe(SITE_C.site);
    expect(cfg.mcpServers[NAME_A].env.WORDPRESS_APP_PASSWORD).toBe(SITE_A.password);
    expect(cfg.mcpServers[NAME_C].env.WORDPRESS_APP_PASSWORD).toBe(SITE_C.password);
  });
});
