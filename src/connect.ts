/**
 * connect — Browser-Approve handoff CLI for @gravitykit/block-mcp.
 *
 * Starts a loopback HTTP server, opens the WordPress admin authorize URL in
 * the browser, waits for the admin to click Approve, then writes the chosen
 * AI client's MCP config with the returned credentials.
 *
 * The app password is only written to stdout when the user explicitly opts in
 * via `--reveal` or `--client print`. The default invocation redacts it.
 *
 * Each connection is registered under an MCP server name so several sites can
 * coexist in one client config. The name defaults to a host-derived
 * `block-mcp-<host-label>` (e.g. block-mcp-gravitykit) and can be overridden
 * with `--name`. Connecting a second site adds a new entry rather than
 * overwriting the first.
 */

import * as http from 'node:http';
import * as crypto from 'node:crypto';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import * as cp from 'node:child_process';

// ── Types ─────────────────────────────────────────────────────────────────────

export type ClientTarget =
  | 'claude-code'
  | 'cursor'
  | 'chatgpt-desktop'
  | 'claude-desktop'
  | 'print';

/** Accepted `--client` values. Both `--client <v>` and `--client=<v>` validate against this. */
const VALID_CLIENTS: ClientTarget[] = [
  'claude-code',
  'cursor',
  'chatgpt-desktop',
  'claude-desktop',
  'print',
];

/** Validate a raw `--client` value against the allowlist; throws on a typo. */
function assertValidClient(val: string): ClientTarget {
  if (!VALID_CLIENTS.includes(val as ClientTarget)) {
    throw new Error(
      `Invalid --client value "${val}". Must be one of: ${VALID_CLIENTS.join(', ')}`
    );
  }
  return val as ClientTarget;
}

export interface ConnectArgs {
  site: string;
  client: ClientTarget;
  port: number | null;
  open: boolean;
  /**
   * Opt-in to printing the cleartext app password to stdout. False by default
   * so the secret stays out of shell scrollback / CI logs; set by `--reveal`
   * or by an explicit `--client print`.
   */
  reveal: boolean;
  /**
   * MCP server name this connection is registered under. Lets several sites
   * coexist in one client config instead of overwriting a single entry. Set by
   * `--name`; defaults to a host-derived `block-mcp-<host-label>`.
   */
  name: string;
}

export interface Credentials {
  site: string;
  user: string;
  password: string;
}

export interface McpServerEntry {
  command: string;
  args: string[];
  env: Record<string, string>;
}

export interface McpConfig {
  mcpServers: Record<string, McpServerEntry>;
}

// ── Arg parsing ───────────────────────────────────────────────────────────────

/**
 * Parse the argv slice after `connect` into a ConnectArgs object.
 * Throws a descriptive Error for missing or invalid input.
 */
export function parseConnectArgs(argv: string[]): ConnectArgs {
  let site: string | undefined;
  let client: ClientTarget = 'print';
  let port: number | null = null;
  let open = true;
  let reveal = false;
  let name: string | undefined;

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--site') {
      site = argv[++i];
    } else if (arg === '--client') {
      const val = assertValidClient(argv[++i]);
      client = val;
      // An explicit `--client print` is itself an opt-in to reveal the secret;
      // the implicit default 'print' does not reveal.
      if (val === 'print') {
        reveal = true;
      }
    } else if (arg === '--port') {
      const raw = argv[++i];
      const n = parseInt(raw, 10);
      if (isNaN(n) || n < 1 || n > 65535) {
        throw new Error(`Invalid --port value "${raw}". Must be 1–65535.`);
      }
      port = n;
    } else if (arg === '--no-open') {
      open = false;
    } else if (arg === '--reveal') {
      reveal = true;
    } else if (arg === '--name') {
      name = argv[++i];
    } else if (arg.startsWith('--name=')) {
      name = arg.slice('--name='.length);
    } else if (arg.startsWith('--site=')) {
      site = arg.slice('--site='.length);
    } else if (arg.startsWith('--client=')) {
      const val = assertValidClient(arg.slice('--client='.length));
      client = val;
      if (val === 'print') {
        reveal = true;
      }
    } else if (arg.startsWith('--port=')) {
      const n = parseInt(arg.slice('--port='.length), 10);
      if (isNaN(n) || n < 1 || n > 65535) {
        throw new Error(`Invalid --port value. Must be 1–65535.`);
      }
      port = n;
    }
  }

  if (!site) {
    throw new Error('--site <url> is required. Example: --site https://example.com');
  }

  const resolvedName = name && name.trim() !== '' ? name.trim() : defaultServerName(site);

  return { site, client, port, open, reveal, name: resolvedName };
}

/**
 * Derive a default MCP server name from a site URL: `block-mcp-<sanitized-host>`.
 *
 * Uses the URL's full host authority — hostname AND any non-default port —
 * verbatim, lowercased, with each run of non-alphanumerics collapsed to a
 * single hyphen and stray hyphens trimmed. Nothing is stripped, truncated, or
 * collapsed, so every distinct host gets a distinct name and no two sites
 * silently share one entry: https://www.gravitykit.com → block-mcp-www-gravitykit-com
 * (≠ https://gravitykit.com → block-mcp-gravitykit-com), https://dev.test →
 * block-mcp-dev-test, http://localhost:7701 → block-mcp-localhost-7701. Falls
 * back to `block-mcp` when the site has no parseable host. Override with `--name`.
 */
export function defaultServerName(site: string): string {
  let host: string;
  try {
    host = new URL(site).host.toLowerCase();
  } catch {
    return 'block-mcp';
  }

  const slug = host.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return slug ? `block-mcp-${slug}` : 'block-mcp';
}

// ── Site URL normalisation ────────────────────────────────────────────────────

/**
 * True for loopback / local-dev hostnames where plain http:// is acceptable.
 *
 * The connector POSTs a single-use code to the site and gets the credential
 * back in the response body, so the transport must be confidential for any
 * non-local host. Loopback (127.0.0.1 / ::1 / localhost) and the dev TLDs
 * `.local` / `.test` / `.localhost` are the only places plain http is allowed.
 */
export function isLoopbackOrDevHost(hostname: string): boolean {
  const h = hostname.toLowerCase().replace(/^\[|\]$/g, ''); // strip IPv6 brackets
  if (h === 'localhost' || h === '127.0.0.1' || h === '::1') {
    return true;
  }
  if (h.startsWith('127.')) {
    return true;
  }
  return h.endsWith('.localhost') || h.endsWith('.local') || h.endsWith('.test');
}

/**
 * Normalise a site URL: strip trailing slashes, require an http/https scheme,
 * reject shell metacharacters, and require https:// for any non-local host.
 *
 * The site URL is later handed to the OS browser-open command, so parsing it
 * with `new URL()` and rejecting `[\s"'`<>|&^$\\]` keeps a crafted --site from
 * smuggling a second command. And because the credential-bearing exchange runs
 * against this host, plain http:// is refused for non-loopback/non-dev hosts so
 * the code + returned password can't travel in cleartext. Throws a descriptive
 * Error on any invalid input.
 */
export function normalizeSite(raw: string): string {
  const trimmed = raw.replace(/\/+$/, '');

  let url: URL;
  try {
    url = new URL(trimmed);
  } catch {
    throw new Error(`--site must start with http:// or https://. Got: "${raw}"`);
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new Error(`--site must start with http:// or https://. Got: "${raw}"`);
  }

  if (/[\s"'`<>|&^$\\]/.test(trimmed)) {
    throw new Error(
      `--site contains characters that are not allowed in a site URL: "${raw}"`
    );
  }

  if (url.protocol === 'http:' && !isLoopbackOrDevHost(url.hostname)) {
    throw new Error(
      `--site must use https:// for a public host (got http://${url.hostname}). ` +
        'Plain http would send your connection credential in cleartext. ' +
        'Use https://, or a local host (localhost / *.local / *.test) for development.'
    );
  }

  return trimmed;
}

// ── Authorize URL builder ─────────────────────────────────────────────────────

export interface AuthorizeUrlParams {
  site: string;
  callback: string;
  state: string;
  client: ClientTarget;
}

/**
 * Build the WordPress admin authorize URL that the admin visits to approve.
 * All variable query params are URL-encoded.
 */
export function buildAuthorizeUrl(params: AuthorizeUrlParams): string {
  const { site, callback, state, client } = params;
  const base = `${site}/wp-admin/options-general.php`;
  const query = new URLSearchParams({
    page: 'gk-block-api-settings',
    tab: 'connect',
    gk_authorize: '1',
    callback,
    state,
    client,
  });
  return `${base}?${query.toString()}`;
}

// ── Callback handler ──────────────────────────────────────────────────────────

export interface CallbackResult {
  ok: true;
  code: string;
}

export interface CallbackError {
  ok: false;
  reason: string;
}

/**
 * Parse and validate the loopback callback URL.
 *
 * Verifies the CSRF state and extracts the single-use exchange code. The
 * callback never carries the credential itself — only the code, which the
 * caller then exchanges for the credential over a direct request to the site.
 */
export function handleCallback(
  reqUrl: string,
  expectedState: string
): CallbackResult | CallbackError {
  let parsed: URL;
  try {
    // reqUrl may be just a path+query; prepend a dummy base to parse it
    parsed = new URL(reqUrl, 'http://127.0.0.1');
  } catch {
    return { ok: false, reason: 'Could not parse callback URL' };
  }

  const state = parsed.searchParams.get('state');
  if (!state || state !== expectedState) {
    return { ok: false, reason: 'State mismatch — possible CSRF. Connection rejected.' };
  }

  const code = parsed.searchParams.get('code');
  if (!code) {
    return { ok: false, reason: 'Callback missing the exchange code.' };
  }

  return { ok: true, code };
}

// ── Credential exchange ───────────────────────────────────────────────────────

/**
 * Parse the exchange endpoint's JSON response into a Credentials object.
 *
 * The endpoint replies with the WordPress `wp_send_json_success` envelope
 * `{ success: true, data: { site, user, password } }`. Throws a descriptive
 * Error on any other shape or a missing field.
 */
export function parseExchangeResponse(json: unknown): Credentials {
  const root = json as { success?: unknown; data?: unknown } | null;
  if (!root || typeof root !== 'object' || root.success !== true || typeof root.data !== 'object' || root.data === null) {
    throw new Error('Exchange failed: the site did not return a valid credential response.');
  }

  const data = root.data as { site?: unknown; user?: unknown; password?: unknown };
  const { site, user, password } = data;
  if (typeof site !== 'string' || typeof user !== 'string' || typeof password !== 'string' || !site || !user || !password) {
    throw new Error('Exchange response is missing the site, user, or password.');
  }

  return { site, user, password };
}

/** Default timeout (ms) for the credential-exchange request. */
export const EXCHANGE_FETCH_TIMEOUT_MS = 15_000;

/**
 * Exchange a single-use code for the credential set.
 *
 * POSTs the code to the site's REST exchange route and returns the credential
 * once, in a direct response body — never in a URL — so it stays out of browser
 * history and Referer headers.
 *
 * The transport is the REST API, NOT admin-post.php: admin-post.php is routinely
 * 30x'd before the handler runs by canonical/SSL redirects, the Redirection
 * plugin, and security plugins on real sites — which surfaced as "fetch failed"
 * under the old `redirect:'error'`. REST routes escape those front-end rules.
 *
 * The URL uses the `?rest_route=` form — the permalink-independent form that
 * WordPress's rest_url() itself falls back to. The connector is Node and cannot
 * call rest_url(), and hardcoding `/wp-json/` would break on plain permalinks or
 * a custom rest_url_prefix; `?rest_route=` works on every permalink configuration.
 *
 * Redirects are handled manually: up to 3 SAME-ORIGIN hops are followed by
 * re-POSTing the body (so a canonical/SSL 30x still delivers the code), while a
 * cross-origin redirect is REFUSED — the credential must never be POSTed
 * off-origin. A timeout bounds the whole exchange. `fetchFn` is injectable for
 * testing.
 */
export async function exchangeCode(
  site: string,
  code: string,
  fetchFn: typeof fetch = fetch,
  timeoutMs: number = EXCHANGE_FETCH_TIMEOUT_MS
): Promise<Credentials> {
  const origin = new URL(site).origin;
  let url = `${site}/?rest_route=/gk-block-api/v1/connect/exchange`;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let res!: Response;
  try {
    for (let hops = 0; ; hops++) {
      try {
        res = await fetchFn(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ code }),
          redirect: 'manual',
          signal: controller.signal,
        });
      } catch (err) {
        throw new Error(`Exchange failed: could not reach ${url} (${(err as Error).message}).`);
      }

      // 2xx (or any non-redirect) → done.
      if (res.status < 300 || res.status >= 400) {
        break;
      }

      // Follow only same-origin redirects, re-POSTing the body.
      const location = res.headers.get('location');
      if (!location || hops >= 3) {
        throw new Error(
          `Exchange failed: the site redirected the request (HTTP ${res.status}); ensure --site is the canonical site URL.`
        );
      }
      const next = new URL(location, url);
      if (next.origin !== origin) {
        throw new Error(
          `Exchange failed: the site redirected to a different origin (${next.origin}); refusing to send the credential off-site.`
        );
      }
      url = next.toString();
    }
  } finally {
    clearTimeout(timer);
  }

  let json: unknown;
  try {
    json = await res.json();
  } catch {
    throw new Error(`Exchange failed: the site returned a non-JSON response (HTTP ${res.status}).`);
  }

  return parseExchangeResponse(json);
}

// ── MCP config builders ───────────────────────────────────────────────────────

/** Build the mcpServers entry for @gravitykit/block-mcp. */
export function buildMcpEntry(creds: Credentials): McpServerEntry {
  return {
    command: 'npx',
    args: ['-y', '@gravitykit/block-mcp'],
    env: {
      WORDPRESS_URL: creds.site,
      WORDPRESS_USER: creds.user,
      WORDPRESS_APP_PASSWORD: creds.password,
    },
  };
}

/**
 * Merge a block-mcp entry into an existing mcpServers config object under the
 * given server name. Existing servers (including other sites registered under
 * different names) are preserved, so several sites coexist in one config.
 */
export function mergeMcpServers(
  existing: McpConfig,
  creds: Credentials,
  name: string = 'block-mcp'
): McpConfig {
  return {
    ...existing,
    mcpServers: {
      ...existing.mcpServers,
      [name]: buildMcpEntry(creds),
    },
  };
}

/** Build the full cursor config object (for unit testing). */
export function cursorConfig(creds: Credentials, name: string = 'block-mcp'): McpConfig {
  return mergeMcpServers({ mcpServers: {} }, creds, name);
}

// ── Platform-specific config paths ───────────────────────────────────────────

/**
 * Return the claude_desktop_config.json path for the given platform string.
 * `platform` should be a `process.platform` value: 'darwin', 'win32', 'linux'.
 */
export function claudeDesktopConfigPath(platform: string): string {
  switch (platform) {
    case 'darwin':
      return path.join(
        os.homedir(),
        'Library',
        'Application Support',
        'Claude',
        'claude_desktop_config.json'
      );
    case 'win32': {
      const appData = process.env.APPDATA ?? path.join(os.homedir(), 'AppData', 'Roaming');
      return path.join(appData, 'Claude', 'claude_desktop_config.json');
    }
    default:
      // Linux and anything else
      return path.join(os.homedir(), '.config', 'Claude', 'claude_desktop_config.json');
  }
}

/**
 * Return the cursor MCP config path (~/.cursor/mcp.json).
 */
export function cursorConfigPath(): string {
  return path.join(os.homedir(), '.cursor', 'mcp.json');
}

// ── claude mcp add argv builder ───────────────────────────────────────────────

/**
 * Build the argv array for `claude mcp add` WITHOUT a shell.
 *
 * The credentials are discrete array elements (spawn with shell:false), so they
 * are kept out of the shell command string and shell history. They are NOT
 * fully hidden: `claude mcp add` accepts a secret only as an inline
 * `-e KEY=value` argument (no environment-inheritance or stdin channel), so the
 * app password is briefly visible in the child's process arguments
 * (`ps aux` / `/proc/<pid>/cmdline`) for the duration of the one-shot spawn.
 * That residual exposure is inherent to the `claude mcp add` interface; the
 * config it then writes is owned and protected by Claude Code.
 */
export function claudeCodeAddArgs(creds: Credentials, name: string = 'block-mcp'): string[] {
  return [
    'mcp',
    'add',
    name,
    '--scope',
    'user',
    '--env',
    `WORDPRESS_URL=${creds.site}`,
    '--env',
    `WORDPRESS_USER=${creds.user}`,
    '--env',
    `WORDPRESS_APP_PASSWORD=${creds.password}`,
    '--',
    'npx',
    '-y',
    '@gravitykit/block-mcp',
  ];
}

// ── Config file writers ───────────────────────────────────────────────────────

/**
 * Read a JSON file, returning the default ONLY when the file does not exist.
 *
 * A missing config (ENOENT) is the normal first-run case, so fall back to the
 * default. Any other failure — an unreadable file or, critically, malformed
 * JSON — is rethrown so the caller surfaces it instead of silently treating a
 * corrupt config as empty and overwriting it (which would clobber every other
 * MCP server the user had configured).
 */
export function readJsonFile(filePath: string, defaultValue: McpConfig): McpConfig {
  let raw: string;
  try {
    raw = fs.readFileSync(filePath, 'utf8');
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === 'ENOENT') {
      return defaultValue;
    }
    throw err;
  }

  try {
    return JSON.parse(raw) as McpConfig;
  } catch (err) {
    throw new Error(
      `Could not parse the existing MCP config at ${filePath}: ${(err as Error).message}. ` +
        'Fix or remove the file, then re-run connect — refusing to overwrite it.'
    );
  }
}

/**
 * Write a JSON file, creating parent directories as needed.
 *
 * The file embeds the WordPress Application Password in cleartext, so it is
 * created owner-only (0600) under an owner-only parent directory (0700). The
 * `mode` option only applies when the file/dir is newly created, so an
 * explicit chmod also tightens a pre-existing loose-perm file. chmod is
 * best-effort: on filesystems without POSIX modes it is a no-op and any
 * error is ignored.
 */
function writeJsonFile(filePath: string, data: McpConfig): void {
  fs.mkdirSync(path.dirname(filePath), { recursive: true, mode: 0o700 });
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2) + '\n', { encoding: 'utf8', mode: 0o600 });
  try {
    fs.chmodSync(filePath, 0o600);
  } catch {
    // POSIX file modes unavailable (e.g. Windows) — nothing to tighten.
  }
}

/** Write to the Cursor MCP config, preserving existing servers. */
export function writeCursorConfig(creds: Credentials, name: string = 'block-mcp'): void {
  const configPath = cursorConfigPath();
  const existing = readJsonFile(configPath, { mcpServers: {} });
  const updated = mergeMcpServers(existing, creds, name);
  writeJsonFile(configPath, updated);
}

/** Write to the Claude Desktop config, preserving existing servers. */
export function writeClaudeDesktopConfig(
  creds: Credentials,
  platform: string = process.platform,
  name: string = 'block-mcp'
): void {
  const configPath = claudeDesktopConfigPath(platform);
  const existing = readJsonFile(configPath, { mcpServers: {} });
  const updated = mergeMcpServers(existing, creds, name);
  writeJsonFile(configPath, updated);
}

/** Run `claude mcp add` via spawnSync (no shell — args array only). */
export function runClaudeCodeAdd(
  creds: Credentials,
  name: string = 'block-mcp'
): { success: boolean; error?: string } {
  const args = claudeCodeAddArgs(creds, name);
  try {
    const result = cp.spawnSync('claude', args, {
      stdio: 'inherit',
      shell: false,
      encoding: 'utf8',
    });
    if (result.error) {
      // Binary not found
      return { success: false, error: (result.error as Error).message };
    }
    if (result.status !== 0) {
      return { success: false, error: `claude exited with status ${result.status}` };
    }
    return { success: true };
  } catch (err) {
    return { success: false, error: (err as Error).message };
  }
}

/**
 * Print the mcpServers JSON block for manual paste.
 *
 * The cleartext app password is printed only when `reveal` is true (explicit
 * `--reveal` / `--client print`). Otherwise it is replaced with a placeholder
 * so the secret never lands in stdout / shell scrollback / CI logs.
 */
export function printConfig(creds: Credentials, reveal: boolean, name: string = 'block-mcp'): void {
  const shown: Credentials = reveal
    ? creds
    : { ...creds, password: '<hidden — re-run with --reveal to print it>' };
  const entry = buildMcpEntry(shown);
  const block: McpConfig = { mcpServers: { [name]: entry } };
  console.log('\nAdd this to your MCP client config:\n');
  console.log(JSON.stringify(block, null, 2));
  console.log(
    '\nFor Claude Desktop: paste into ~/Library/Application Support/Claude/claude_desktop_config.json (macOS)'
  );
  console.log('For Cursor: paste into ~/.cursor/mcp.json\n');
  if (!reveal) {
    console.log(
      'The app password was hidden. Re-run with --reveal (or --client print) to print it.\n'
    );
  }
}

// ── Browser opener ────────────────────────────────────────────────────────────

/**
 * Resolve the platform-appropriate command + argv to open a URL in the default
 * browser. Pure (no side effects) so the argv can be asserted in tests.
 *
 * On Windows this uses `rundll32 url.dll,FileProtocolHandler <url>` rather than
 * `cmd /c start`: cmd.exe re-parses `& | ^ < > "` as metacharacters, so a URL
 * routed through it could smuggle a second command. rundll32 takes the URL as a
 * single argument with no shell re-parsing.
 */
export function browserOpenCommand(
  url: string,
  platform: string
): { cmd: string; args: string[] } {
  switch (platform) {
    case 'darwin':
      return { cmd: 'open', args: [url] };
    case 'win32':
      return { cmd: 'rundll32', args: ['url.dll,FileProtocolHandler', url] };
    default:
      return { cmd: 'xdg-open', args: [url] };
  }
}

/**
 * Open a URL in the default browser using the platform-appropriate command.
 * Uses spawn (not exec/shell) to avoid shell escaping issues. A spawn failure
 * (no opener on the box, command missing) emits an async 'error' event that
 * would otherwise crash the connector; we catch it and fall back to printing
 * the URL so the user can open it manually and the connect still completes.
 */
export function openBrowser(url: string): void {
  const { cmd, args } = browserOpenCommand(url, process.platform);
  const child = cp.spawn(cmd, args, { detached: true, stdio: 'ignore' });
  child.on('error', (e) => {
    console.error(
      `Could not open a browser automatically (${e.message}). Open this URL manually:\n\n  ${url}\n`
    );
  });
  child.unref();
}

// ── HTML response ─────────────────────────────────────────────────────────────

const SUCCESS_HTML = `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Connected</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;text-align:center}
h1{color:#2d6a4f}p{color:#555}</style></head>
<body><h1>&#x2713; Connected</h1>
<p>You can close this tab and return to your terminal.</p></body>
</html>`;

const ERROR_HTML = (msg: string) => `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Error</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;text-align:center}
h1{color:#c0392b}p{color:#555}</style></head>
<body><h1>Connection Error</h1><p>${msg}</p></body>
</html>`;

// ── Main orchestrator ─────────────────────────────────────────────────────────

export interface RunConnectOptions {
  /** Override process.platform for config path resolution. */
  platform?: string;
  /** Override the browser-open function (injectable for tests). */
  openBrowserFn?: (url: string) => void;
  /** Override the timeout in milliseconds (default 300_000 ms = 5 min). */
  timeoutMs?: number;
}

/**
 * Main entry point for the `connect` subcommand.
 * `argv` is the slice of process.argv after 'connect'.
 */
export async function runConnect(
  argv: string[],
  opts: RunConnectOptions = {}
): Promise<void> {
  const {
    platform = process.platform,
    openBrowserFn = openBrowser,
    timeoutMs = 300_000,
  } = opts;

  // ── 1. Parse args ──────────────────────────────────────────────────────
  let args: ConnectArgs;
  try {
    args = parseConnectArgs(argv);
    args = { ...args, site: normalizeSite(args.site) };
  } catch (err) {
    console.error(`Error: ${(err as Error).message}`);
    process.exit(1);
  }

  // ── 2. Generate state ──────────────────────────────────────────────────
  const state = crypto.randomUUID();

  // ── 3. Start loopback server ───────────────────────────────────────────
  // The callback promise is resolve-only: a malformed / wrong-state / stray
  // request gets a 400 but does NOT settle it, so a probe or an attacker's
  // forged callback can't kill the pending connect. Only a valid, state-matching
  // callback resolves it; otherwise the timeout below fires.
  let resolveCallback!: (code: string) => void;

  const callbackPromise = new Promise<string>((resolve) => {
    resolveCallback = resolve;
  });

  const server = http.createServer((req, res) => {
    if (!req.url?.startsWith('/callback')) {
      res.writeHead(404);
      res.end('Not found');
      return;
    }

    const result = handleCallback(req.url, state);

    if (!result.ok) {
      res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(ERROR_HTML(result.reason));
      console.error(`Ignoring an invalid callback (${result.reason}); still waiting…`);
      return;
    }

    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(SUCCESS_HTML);
    resolveCallback(result.code);
  });

  await new Promise<void>((resolve, reject) => {
    const listenPort = args.port ?? 0;
    server.listen(listenPort, '127.0.0.1', () => resolve());
    server.once('error', reject);
  });

  const address = server.address() as { port: number };
  const port = address.port;
  const callbackUrl = `http://127.0.0.1:${port}/callback`;

  // ── 4. Build and open authorize URL ────────────────────────────────────
  const authorizeUrl = buildAuthorizeUrl({
    site: args.site,
    callback: callbackUrl,
    state,
    client: args.client,
  });

  if (args.open) {
    console.error(`Opening browser to authorize URL…`);
    openBrowserFn(authorizeUrl);
  } else {
    console.log(`\nOpen this URL in your browser to authorize:\n\n  ${authorizeUrl}\n`);
  }

  console.error(`Waiting for approval (timeout ${timeoutMs / 1000}s)…`);

  // ── 5. Wait for callback with timeout ──────────────────────────────────
  // Keep the timer handle so we can clear it once a callback wins the race —
  // otherwise the pending setTimeout keeps the event loop alive after success.
  let timeoutHandle: ReturnType<typeof setTimeout>;
  const timeoutPromise = new Promise<never>((_, reject) => {
    timeoutHandle = setTimeout(
      () => reject(new Error(`Timed out after ${timeoutMs / 1000}s waiting for browser approval.`)),
      timeoutMs
    );
  });

  let code: string;
  try {
    code = await Promise.race([callbackPromise, timeoutPromise]);
  } catch (err) {
    server.close();
    console.error(`Error: ${(err as Error).message}`);
    process.exit(1);
  } finally {
    clearTimeout(timeoutHandle!);
    server.close();
  }

  // ── 6. Exchange the single-use code for the credential ──────────────────
  // The callback delivered only a code; the credential itself comes back in
  // this direct response body, never in a URL.
  console.error(`Approved. Retrieving credentials…`);
  let creds: Credentials;
  try {
    creds = await exchangeCode(args.site, code);
  } catch (err) {
    console.error(`Error: ${(err as Error).message}`);
    process.exit(1);
  }

  // ── 7. Write config ─────────────────────────────────────────────────────
  try {
    switch (args.client) {
      case 'cursor':
        writeCursorConfig(creds, args.name);
        console.log(`\n✓ Connected! Wrote "${args.name}" to ~/.cursor/mcp.json`);
        console.log(`  Site: ${creds.site}  User: ${creds.user}`);
        console.log(`  Restart Cursor to pick up the new server.\n`);
        break;

      case 'claude-desktop':
        writeClaudeDesktopConfig(creds, platform, args.name);
        console.log(`\n✓ Connected! Wrote "${args.name}" to ${claudeDesktopConfigPath(platform)}`);
        console.log(`  Site: ${creds.site}  User: ${creds.user}`);
        console.log(`  Restart Claude Desktop to pick up the new server.\n`);
        break;

      case 'claude-code': {
        const result = runClaudeCodeAdd(creds, args.name);
        if (result.success) {
          console.log(`\n✓ Connected! Registered "${args.name}" via 'claude mcp add'.`);
          console.log(`  Site: ${creds.site}  User: ${creds.user}\n`);
        } else {
          console.error(
            `\nWarning: 'claude' binary not found or failed (${result.error}).`
          );
          console.log(`\nFall back — add this to your Claude Code MCP config manually:`);
          // Explicit fallback for a client the user chose: the secret is needed.
          printConfig(creds, true, args.name);
        }
        break;
      }

      case 'chatgpt-desktop': {
        // ChatGPT Desktop does not have a standardised config path yet.
        // Print the JSON block so the user can paste it (secret needed here).
        console.log(`\n✓ Authorized! Paste the following into ChatGPT Desktop's MCP config:\n`);
        printConfig(creds, true, args.name);
        break;
      }

      case 'print':
      default:
        printConfig(creds, args.reveal, args.name);
        break;
    }
  } catch (err) {
    console.error(`Error writing config: ${(err as Error).message}`);
    process.exit(1);
  }
}
