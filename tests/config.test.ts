import { describe, it, expect } from 'vitest';
import { resolveWordPressConfig, buildNotConfiguredResult } from '../src/config.js';

// A missing/empty WordPress config must not crash the server on startup (which
// surfaces to the MCP client as an opaque -32000 with the real reason stranded
// on stderr). Instead the server starts degraded and every tool call returns a
// clear, structured "not configured" error. These pin the two pure seams that
// decision rests on.
describe('resolveWordPressConfig', () => {
  it('reports every missing var instead of throwing, with an actionable message', () => {
    const r = resolveWordPressConfig({});
    expect(r.ok).toBe(false);
    if (r.ok) throw new Error('unreachable');
    expect(r.missing).toEqual(['WORDPRESS_URL', 'WORDPRESS_USER', 'WORDPRESS_APP_PASSWORD']);
    expect(r.message).toContain('WORDPRESS_URL');
    expect(r.message).toContain('MCP client config');
  });

  it('treats an empty-string value as missing (not a valid config)', () => {
    const r = resolveWordPressConfig({
      WORDPRESS_URL: '',
      WORDPRESS_USER: 'u',
      WORDPRESS_APP_PASSWORD: 'p',
    });
    expect(r.ok).toBe(false);
    if (r.ok) throw new Error('unreachable');
    expect(r.missing).toEqual(['WORDPRESS_URL']);
  });

  it('returns ok with the config when all three are present', () => {
    const r = resolveWordPressConfig({
      WORDPRESS_URL: 'https://example.com',
      WORDPRESS_USER: 'block-mcp',
      WORDPRESS_APP_PASSWORD: 'abcd efgh',
    });
    expect(r.ok).toBe(true);
    if (!r.ok) throw new Error('unreachable');
    expect(r.config).toEqual({ url: 'https://example.com', user: 'block-mcp', password: 'abcd efgh' });
  });

  it('accepts the legacy GK_* env var names', () => {
    const r = resolveWordPressConfig({
      GK_SITE_URL: 'https://legacy.test',
      GK_BLOCK_API_USER: 'u',
      GK_BLOCK_API_APP_PASSWORD: 'p',
    });
    expect(r.ok).toBe(true);
  });
});

describe('buildNotConfiguredResult', () => {
  it('is an isError tool result carrying a structured not_configured code', () => {
    const res = buildNotConfiguredResult('set WORDPRESS_URL');
    expect(res.isError).toBe(true);
    const payload = JSON.parse(res.content[0].text);
    expect(payload.error).toBe(true);
    expect(payload.code).toBe('not_configured');
    expect(payload.message).toContain('set WORDPRESS_URL');
  });
});
