/**
 * WordPress connection config resolution for the MCP server.
 *
 * Kept side-effect free (no server construction, no process.exit) so the
 * startup decision is unit-testable and so a missing/invalid config degrades
 * the server gracefully instead of crashing it — a crash reaches the MCP client
 * only as an opaque -32000 with the real reason stranded on stderr.
 */

export interface WordPressConfig {
  url: string;
  user: string;
  password: string;
}

export type ConfigResult =
  | { ok: true; config: WordPressConfig }
  | { ok: false; missing: string[]; message: string };

/**
 * Read an env var by its primary name, falling back to a legacy alias. An
 * empty/whitespace-only value counts as absent, not as a valid empty config.
 */
function readEnv(env: NodeJS.ProcessEnv, primary: string, legacy: string): string | undefined {
  const fromPrimary = env[primary];
  if (fromPrimary && fromPrimary.trim() !== '') {
    return fromPrimary;
  }
  const fromLegacy = env[legacy];
  if (fromLegacy && fromLegacy.trim() !== '') {
    console.error(`[block-mcp] DEPRECATED: ${legacy} is deprecated; rename to ${primary} in your MCP client config.`);
    return fromLegacy;
  }
  return undefined;
}

/**
 * Resolve the WordPress connection config from the process environment.
 *
 * Returns `{ ok: false, missing, message }` when any of the three required
 * values is absent — the caller starts the server in a degraded mode and
 * surfaces `message` on tool calls, rather than exiting.
 */
export function resolveWordPressConfig(env: NodeJS.ProcessEnv): ConfigResult {
  const url = readEnv(env, 'WORDPRESS_URL', 'GK_SITE_URL');
  const user = readEnv(env, 'WORDPRESS_USER', 'GK_BLOCK_API_USER');
  const password = readEnv(env, 'WORDPRESS_APP_PASSWORD', 'GK_BLOCK_API_APP_PASSWORD');

  const missing: string[] = [];
  if (!url) {
    missing.push('WORDPRESS_URL');
  }
  if (!user) {
    missing.push('WORDPRESS_USER');
  }
  if (!password) {
    missing.push('WORDPRESS_APP_PASSWORD');
  }

  if (missing.length > 0) {
    const message =
      `Block MCP is not configured: ${missing.join(', ')} ${missing.length === 1 ? 'is' : 'are'} missing. ` +
      'Set them in the "env" block of the block-mcp server entry in your MCP client config. ' +
      'Your site\'s Settings → Block MCP → Connect generates a ready-made config for you.';
    return { ok: false, missing, message };
  }

  return { ok: true, config: { url: url as string, user: user as string, password: password as string } };
}

/**
 * MCP tool-call result returned for every tool while the server is unconfigured.
 * The index signature keeps it assignable to the SDK's ServerResult union.
 */
export interface NotConfiguredResult {
  [key: string]: unknown;
  content: Array<{ type: string; text: string }>;
  isError: true;
}

/**
 * Build the structured tool-call error returned when the server is running but
 * has no valid WordPress config, so the client shows an actionable reason
 * instead of an opaque connection failure.
 */
export function buildNotConfiguredResult(message: string): NotConfiguredResult {
  return {
    content: [
      {
        type: 'text',
        text: JSON.stringify({ error: true, code: 'not_configured', message }, null, 2),
      },
    ],
    isError: true,
  };
}
