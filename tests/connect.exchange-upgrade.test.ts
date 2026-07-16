import { describe, it, expect } from 'vitest';
import { exchangeCode } from '../src/connect.js';

// A site that forces HTTPS redirects the exchange POST from http:// to https://
// on the SAME host. That is a security upgrade to the same site, not an off-site
// leak, but the connector refused it as "a different origin" and bombed the auth
// for anyone who passed --site http:// (a common, hard-to-diagnose failure). It
// must follow the upgrade while still refusing a redirect to a different host.
function resp(status: number, opts: { location?: string; json?: unknown } = {}): Response {
  return {
    status,
    headers: { get: (h: string) => (h.toLowerCase() === 'location' ? opts.location ?? null : null) },
    json: async () => opts.json,
  } as unknown as Response;
}

describe('exchangeCode — same-host http→https upgrade', () => {
  it('follows a same-host http→https redirect and completes the exchange', async () => {
    const urls: string[] = [];
    const fetchFn = (async (u: string) => {
      urls.push(u);
      return urls.length === 1
        ? resp(301, { location: 'https://mysite.test/?rest_route=/gk-block-api/v1/connect/exchange' })
        : resp(200, { json: { success: true, data: { site: 'https://mysite.test', user: 'block-mcp', password: 'secret-pw' } } });
    }) as unknown as typeof fetch;

    const creds = await exchangeCode('http://mysite.test', 'code123', fetchFn);

    expect(creds.password).toBe('secret-pw');
    expect(urls.length).toBe(2);
    expect(urls[1]).toContain('https://mysite.test');
  });

  it('still refuses a redirect to a different host (never sends the credential off-site)', async () => {
    const fetchFn = (async () => resp(301, { location: 'https://evil.test/steal' })) as unknown as typeof fetch;
    await expect(exchangeCode('http://mysite.test', 'code123', fetchFn)).rejects.toThrow(/different origin|off-site/i);
  });

  it('still refuses an https→http downgrade to the same host', async () => {
    const fetchFn = (async () => resp(301, { location: 'http://mysite.test/?rest_route=/gk-block-api/v1/connect/exchange' })) as unknown as typeof fetch;
    await expect(exchangeCode('https://mysite.test', 'code123', fetchFn)).rejects.toThrow(/different origin|off-site/i);
  });
});
