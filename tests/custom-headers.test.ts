/**
 * Custom headers let Block MCP reach sites behind a login gateway such as
 * Cloudflare Access. The request tests use real local HTTP servers because which
 * headers survive a redirect is decided inside the HTTP client.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import * as fs from 'node:fs';
import * as http from 'node:http';
import * as os from 'node:os';
import * as path from 'node:path';
import type { AddressInfo } from 'node:net';
import { WordPressBlockClient } from '../src/client.js';
import { resolveWordPressConfig } from '../src/config.js';
import {
  buildMcpEntry,
  claudeCodeAddArgs,
  exchangeCode,
  printConfig,
  runConnect,
} from '../src/connect.js';
import { CUSTOM_HEADERS_ENV, parseCustomHeaders, stripHeadersOffSite, warnIfHeadersUnencrypted } from '../src/custom-headers.js';
import { fetchAddendum } from '../src/instructions.js';

const TOKEN = { 'CF-Access-Client-Id': 'probe-id', 'CF-Access-Client-Secret': 'probe-secret' };
const TOKEN_JSON = JSON.stringify(TOKEN);
const CREDS = { site: 'https://example.com', user: 'block-mcp', password: 'app-password' };

interface Seen {
  method: string;
  url: string;
  headers: http.IncomingHttpHeaders;
  body: string;
}

const servers: http.Server[] = [];

/** Start a local server, recording every request it receives. */
async function serve(
  handler: (req: http.IncomingMessage, res: http.ServerResponse) => void
): Promise<{ url: string; seen: Seen[] }> {
  const seen: Seen[] = [];
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (chunk) => (body += chunk));
    req.on('end', () => {
      seen.push({ method: req.method ?? '', url: req.url ?? '', headers: req.headers, body });
      handler(req, res);
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  servers.push(server);
  return { url: `http://127.0.0.1:${(server.address() as AddressInfo).port}`, seen };
}

/** A gateway: requests with the token reach the site; others go to its login page. */
function gateway(loginUrl: string, respond = '{"posts":[],"addendum":""}') {
  return (req: http.IncomingMessage, res: http.ServerResponse) => {
    if (req.headers['cf-access-client-id'] !== TOKEN['CF-Access-Client-Id']) {
      res.writeHead(302, { Location: `${loginUrl}/cdn-cgi/access/login` });
      res.end();
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(respond.replace('{{HOST}}', req.headers.host ?? ''));
  };
}

function loginPage(_req: http.IncomingMessage, res: http.ServerResponse): void {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
  res.end('<html>Sign in with Cloudflare Access</html>');
}

/** The body and status real Cloudflare Access returns to a request that asks for JSON. */
function accessRefusal(_req: http.IncomingMessage, res: http.ServerResponse): void {
  res.writeHead(403, { 'Content-Type': 'application/json; charset=utf-8', 'cf-access-domain': 'site.test' });
  res.end(`{"message":"Forbidden. You don't have permission to view this. Please contact your system administrator.","status_code":403}`);
}

/** Sends a request for one address to a local server, so a test can use an address it can't serve. */
function mockFetch(actualUrl: string): typeof fetch {
  return ((url: string, init: RequestInit) => {
    const { pathname, search } = new URL(url);
    return fetch(`${actualUrl}${pathname}${search}`, init);
  }) as typeof fetch;
}

function makeClient(url: string, customHeaders?: Record<string, string>): WordPressBlockClient {
  return new WordPressBlockClient({
    wordpress_url: url,
    auth: { username: 'u', application_password: 'p' },
    custom_headers: customHeaders,
  });
}

afterEach(async () => {
  vi.restoreAllMocks();
  delete process.env[CUSTOM_HEADERS_ENV];
  await Promise.all(servers.splice(0).map((s) => new Promise((resolve) => s.close(resolve))));
});

describe('parseCustomHeaders', () => {
  it('treats a missing or blank setting as no headers', () => {
    expect(parseCustomHeaders(undefined)).toEqual({ ok: true, headers: {} });
    expect(parseCustomHeaders('   ')).toEqual({ ok: true, headers: {} });
  });

  it('accepts a JSON object of header names and values', () => {
    expect(parseCustomHeaders(TOKEN_JSON)).toEqual({ ok: true, headers: TOKEN });
  });

  it.each([
    ['not JSON', '{"CF-Access-Client-Secret": probe-secret'],
    ['an array', '["probe-secret"]'],
    ['a string', '"probe-secret"'],
    ['a null', 'null'],
    ['a non-string value', '{"X-Token": 1}'],
    ['an empty value', '{"X-Token": ""}'],
    ['a line break in a value', '{"X-Token": "probe-secret\\r\\nX-Evil: 1"}'],
    ['a space in a name', '{"X Token": "probe-secret"}'],
  ])('rejects %s without repeating the secret', (_label, raw) => {
    const result = parseCustomHeaders(raw);
    expect(result.ok).toBe(false);
    if (result.ok) throw new Error('unreachable');
    expect(result.message).toContain(CUSTOM_HEADERS_ENV);
    expect(result.message).not.toContain('probe-secret');
  });

  it.each(['Authorization', 'authorization', 'Host', 'Content-Type', 'X-HTTP-Method-Override'])(
    'refuses to replace %s, which Block MCP sets itself',
    (name) => {
      const result = parseCustomHeaders(JSON.stringify({ [name]: 'x' }));
      expect(result.ok).toBe(false);
    }
  );
});

describe('warnIfHeadersUnencrypted', () => {
  it.each([
    ['http://site.test', TOKEN, true],
    ['https://site.test', TOKEN, false],
    ['http://localhost:8080', TOKEN, false],
    ['http://127.0.0.1:8080', TOKEN, false],
    ['http://[::1]:8080', TOKEN, false],
    ['http://site.localhost', TOKEN, false],
    ['http://dev.local', TOKEN, true],
    ['http://site.test', {}, false],
  ])('for %s with headers %j returns %s', (url, headers, expected) => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(warnIfHeadersUnencrypted(url, headers)).toBe(expected);
    expect(error).toHaveBeenCalledTimes(expected ? 1 : 0);
    expect(error.mock.calls.flat().join(' ')).not.toContain('probe-secret');
  });
});

describe('stripHeadersOffSite', () => {
  const redirect = (href: string | undefined) => ({
    href,
    headers: { 'cf-access-client-secret': 'probe-secret', accept: 'application/json' } as Record<string, unknown>,
  });

  it('keeps the headers when an IPv6 site redirects within itself', () => {
    const options = redirect('http://[::1]:8080/moved');
    stripHeadersOffSite(options, 'http://[::1]:8080', TOKEN);
    expect(options.headers['cf-access-client-secret']).toBe('probe-secret');
  });

  it.each([undefined, 'not a url'])('removes only the custom headers when the target is %s', (href) => {
    const options = redirect(href);
    stripHeadersOffSite(options, 'http://site.test:8080', TOKEN);
    expect(options.headers['cf-access-client-secret']).toBeUndefined();
    expect(options.headers.accept).toBe('application/json');
  });

  it('removes the password from a site with no custom headers when it cannot read the target', () => {
    const options = {
      href: 'not a url',
      headers: { Authorization: 'Basic abc', accept: 'application/json' } as Record<string, unknown>,
    };
    stripHeadersOffSite(options, 'http://site.test:8080', {});
    expect(options.headers.Authorization).toBeUndefined();
    expect(options.headers.accept).toBe('application/json');
  });
});

describe('resolveWordPressConfig with custom headers', () => {
  const base = { WORDPRESS_URL: 'https://a.test', WORDPRESS_USER: 'u', WORDPRESS_APP_PASSWORD: 'p' };

  it('defaults to no headers, so existing configs are unchanged', () => {
    const result = resolveWordPressConfig(base);
    expect(result.ok && result.config.customHeaders).toEqual({});
  });

  it('passes valid headers through', () => {
    const result = resolveWordPressConfig({ ...base, [CUSTOM_HEADERS_ENV]: TOKEN_JSON });
    expect(result.ok && result.config.customHeaders).toEqual(TOKEN);
  });

  it('starts degraded with a clear reason when the headers are invalid', () => {
    const result = resolveWordPressConfig({ ...base, [CUSTOM_HEADERS_ENV]: 'not json' });
    expect(result.ok).toBe(false);
    if (result.ok) throw new Error('unreachable');
    expect(result.missing).toEqual([]);
    expect(result.message).toContain(CUSTOM_HEADERS_ENV);
  });
});

describe('client requests through a gateway', () => {
  it('sends the headers and auth on every request, reaching the site', async () => {
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url));

    await makeClient(site.url, TOKEN).findPosts();

    expect(site.seen).toHaveLength(1);
    expect(site.seen[0].headers['cf-access-client-id']).toBe('probe-id');
    expect(site.seen[0].headers['cf-access-client-secret']).toBe('probe-secret');
    expect(site.seen[0].headers.authorization).toMatch(/^Basic /);
    expect(login.seen).toHaveLength(0);
  });

  it('never sends the headers or auth to another site on a redirect', async () => {
    const elsewhere = await serve((_req, res) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"posts":[]}');
    });
    const site = await serve((_req, res) => {
      res.writeHead(302, { Location: `${elsewhere.url}/steal` });
      res.end();
    });

    await makeClient(site.url, TOKEN).findPosts();

    expect(site.seen[0].headers['cf-access-client-secret']).toBe('probe-secret');
    expect(elsewhere.seen).toHaveLength(1);
    expect(elsewhere.seen[0].headers['cf-access-client-id']).toBeUndefined();
    expect(elsewhere.seen[0].headers['cf-access-client-secret']).toBeUndefined();
    expect(elsewhere.seen[0].headers.authorization).toBeUndefined();
  });

  it('keeps the headers on a redirect within the same site', async () => {
    const site = await serve((req, res) => {
      if (!req.url?.startsWith('/moved')) {
        res.writeHead(301, { Location: '/moved' });
        res.end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"posts":[]}');
    });

    await makeClient(site.url, TOKEN).findPosts();

    expect(site.seen).toHaveLength(2);
    expect(site.seen[1].headers['cf-access-client-secret']).toBe('probe-secret');
  });
});

describe('redirects through an HTTP proxy', () => {
  /** A forward proxy: it receives the full URL and repeats the request to that target. */
  async function serveProxy() {
    return serve((req, res) => {
      const target = new URL(req.url as string);
      const headers = { ...req.headers, host: target.host };
      const upstream = http.request(target, { method: req.method, headers }, (upstreamRes) => {
        res.writeHead(upstreamRes.statusCode ?? 502, upstreamRes.headers);
        upstreamRes.pipe(res);
      });
      upstream.end();
    });
  }

  /** The proxy skips loopback hosts by default, so clear NO_PROXY for the test. */
  async function withProxy<T>(proxyUrl: string, run: () => Promise<T>): Promise<T> {
    const saved = { http: process.env.http_proxy, no: process.env.no_proxy, NO: process.env.NO_PROXY };
    process.env.http_proxy = proxyUrl;
    delete process.env.no_proxy;
    delete process.env.NO_PROXY;
    try {
      return await run();
    } finally {
      const restore = (name: string, value: string | undefined) => {
        if (value === undefined) delete process.env[name];
        else process.env[name] = value;
      };
      restore('http_proxy', saved.http);
      restore('no_proxy', saved.no);
      restore('NO_PROXY', saved.NO);
    }
  }

  it('keeps the headers on a redirect within the same site', async () => {
    const site = await serve((req, res) => {
      if (!req.url?.startsWith('/moved')) {
        res.writeHead(302, { Location: '/moved' });
        res.end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"posts":[]}');
    });
    const proxy = await serveProxy();

    await withProxy(proxy.url, () => makeClient(site.url, TOKEN).findPosts());

    expect(proxy.seen).toHaveLength(2);
    expect(site.seen).toHaveLength(2);
    expect(site.seen[1].headers['cf-access-client-secret']).toBe('probe-secret');
  });

  it('still removes the headers on a redirect to another site', async () => {
    const elsewhere = await serve((_req, res) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"posts":[]}');
    });
    const site = await serve((_req, res) => {
      res.writeHead(302, { Location: `${elsewhere.url}/steal` });
      res.end();
    });
    const proxy = await serveProxy();

    await withProxy(proxy.url, () => makeClient(site.url, TOKEN).findPosts());

    expect(elsewhere.seen).toHaveLength(1);
    expect(elsewhere.seen[0].headers['cf-access-client-secret']).toBeUndefined();
  });
});

describe('redirects to a subdomain, which the HTTP client treats as trusted', () => {
  /** Hostnames don't need DNS through a forward proxy, so this test can use real subdomains. */
  async function serveFakeHosts(redirectTo: string) {
    const destinations: Seen[] = [];
    const proxy = await serve((req, res) => {
      const target = new URL(req.url as string);
      if (target.hostname === 'site.test') {
        res.writeHead(302, { Location: redirectTo });
        res.end();
        return;
      }
      destinations.push({ method: req.method ?? '', url: req.url ?? '', headers: req.headers, body: '' });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"posts":[]}');
    });
    return { proxy, destinations };
  }

  async function viaProxy(proxyUrl: string, customHeaders?: Record<string, string>): Promise<void> {
    const saved = { http: process.env.http_proxy, no: process.env.no_proxy, NO: process.env.NO_PROXY };
    process.env.http_proxy = proxyUrl;
    delete process.env.no_proxy;
    delete process.env.NO_PROXY;
    try {
      await makeClient('http://site.test', customHeaders).findPosts();
    } finally {
      for (const [name, value] of [['http_proxy', saved.http], ['no_proxy', saved.no], ['NO_PROXY', saved.NO]] as const) {
        if (value === undefined) delete process.env[name];
        else process.env[name] = value;
      }
    }
  }

  it.each([
    ['with custom headers', TOKEN],
    ['without custom headers', undefined],
  ])('removes the password and headers on a redirect to another subdomain, %s', async (_label, headers) => {
    const { proxy, destinations } = await serveFakeHosts('http://untrusted.site.test/steal');

    await viaProxy(proxy.url, headers);

    expect(destinations).toHaveLength(1);
    expect(destinations[0].headers.authorization).toBeUndefined();
    expect(destinations[0].headers['cf-access-client-secret']).toBeUndefined();
  });

  it('keeps the password and headers on a redirect to the www address', async () => {
    const { proxy, destinations } = await serveFakeHosts('http://www.site.test/moved');

    await viaProxy(proxy.url, TOKEN);

    expect(destinations).toHaveLength(1);
    expect(destinations[0].headers.authorization).toMatch(/^Basic /);
    expect(destinations[0].headers['cf-access-client-secret']).toBe('probe-secret');
  });
});

describe('an HTML page answering a REST call', () => {
  it('returns an error when a redirect lands on a login page', async () => {
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url));

    const error = await makeClient(site.url).findPosts().catch((e) => e);

    expect(error).toBeInstanceOf(Error);
    expect(error.message).toContain('in front of WordPress');
    expect(error.message).toContain(CUSTOM_HEADERS_ENV);
    expect(error.message).toContain('Service Auth');
    expect(error.message).not.toContain('Sign in');
    expect(error.wpCode).toBe('gateway_page');
  });

  it('gives the same short error when a gateway refuses a bad token with a 403 page', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(403, { 'Content-Type': 'text/html' });
      res.end('<html>Forbidden: a very long gateway page</html>');
    });

    const error = await makeClient(site.url, TOKEN).findPosts().catch((e) => e);

    expect(error.message).toContain('Block API Error (403)');
    expect(error.message).toContain('in front of WordPress');
    expect(error.message).not.toContain('very long gateway page');
    expect(error.wpCode).toBe('gateway_page');
  });

  it('explains a refusal from Cloudflare Access, which answers JSON requests in JSON', async () => {
    const site = await serve(accessRefusal);

    const error = await makeClient(site.url, TOKEN).findPosts().catch((e) => e);

    expect(error.message).toContain('Block API Error (403)');
    expect(error.message).toContain("Forbidden. You don't have permission");
    expect(error.message).toContain('Service Auth');
    expect(error.wpCode).toBe('gateway_page');
  });

  it('keeps the reason from a plain-text refusal', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(401, { 'Content-Type': 'text/plain' });
      res.end('Token expired');
    });

    const error = await makeClient(site.url, TOKEN).findPosts().catch((e) => e);

    expect(error.message).toContain('Token expired');
    expect(error.message).toContain('Service Auth');
    expect(error.wpCode).toBe('gateway_page');
  });

  it('gives only the guidance for an empty refusal', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(403);
      res.end();
    });

    const error = await makeClient(site.url, TOKEN).findPosts().catch((e) => e);

    expect(error.message).toContain('Service Auth');
    expect(error.message).not.toContain('Unknown error');
    expect(error.wpCode).toBe('gateway_page');
  });

  it('keeps WordPress 401 and 403 errors that pass through a gateway', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(401, { 'Content-Type': 'application/json', 'cf-access-domain': 'site.test' });
      res.end('{"code":"incorrect_password","message":"The provided password is an invalid application password.","data":{"status":401}}');
    });

    const error = await makeClient(site.url, TOKEN).findPosts().catch((e) => e);

    expect(error.wpCode).toBe('incorrect_password');
    expect(error.message).toContain('invalid application password');
  });

  it.each([
    ['text/plain', '<!DOCTYPE html><html>Sign in</html>'],
    ['application/octet-stream', '  <html lang="en">Sign in</html>'],
    [undefined, '<html>Sign in</html>'],
  ])('returns an error for a login page labeled %s', async (contentType, page) => {
    const site = await serve((_req, res) => {
      res.writeHead(200, contentType ? { 'Content-Type': contentType } : {});
      res.end(page);
    });

    const error = await makeClient(site.url).findPosts().catch((e) => e);

    expect(error.wpCode).toBe('gateway_page');
    expect(error.message).not.toContain('Sign in');
  });

  it('still returns a plain-text body that is not a page', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      res.end('<html is mentioned here, but this is plain text');
    });

    await expect(makeClient(site.url).findPosts()).resolves.toContain('plain text');
  });

  it('still accepts JSON from a host that labels it text/html', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('{"posts":[{"id":1}]}');
    });

    const result = await makeClient(site.url).findPosts();

    expect(result).toEqual({ posts: [{ id: 1 }] });
  });

  it('still reports a WordPress JSON error with its own code and message', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end('{"code":"rest_post_invalid_id","message":"Invalid post ID.","data":{"status":404}}');
    });

    const error = await makeClient(site.url).findPosts().catch((e) => e);

    expect(error.wpCode).toBe('rest_post_invalid_id');
    expect(error.message).not.toContain('in front of WordPress');
  });
});

describe('instructions fetch through a gateway', () => {
  it('sends the headers and gets the addendum', async () => {
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url, '{"addendum":"site rules"}'));

    const addendum = await fetchAddendum(site.url, TOKEN);

    expect(addendum).toBe('site rules');
    expect(site.seen[0].headers['cf-access-client-secret']).toBe('probe-secret');
    expect(login.seen).toHaveLength(0);
  });

  it('falls back to baseline, without following the redirect, when the token is missing', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url));

    expect(await fetchAddendum(site.url)).toBe('');
    expect(login.seen).toHaveLength(0);
  });
});

describe('credential exchange through a gateway', () => {
  const exchanged = '{"success":true,"data":{"site":"http://{{HOST}}","user":"block-mcp","password":"pw"}}';

  it('sends the headers and receives the credential', async () => {
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url, exchanged));

    const creds = await exchangeCode(site.url, 'code123', fetch, 5000, TOKEN);

    expect(creds.password).toBe('pw');
    expect(site.seen[0].headers['cf-access-client-secret']).toBe('probe-secret');
    expect(login.seen).toHaveLength(0);
  });

  it('says a gateway refused the request when Access answers in JSON', async () => {
    const site = await serve(accessRefusal);

    await expect(exchangeCode(site.url, 'code123')).rejects.toThrow(/in front of WordPress refused.*Service Auth/);
  });

  it('still reports a WordPress error from the exchange as an invalid response', async () => {
    const site = await serve((_req, res) => {
      res.writeHead(403, { 'Content-Type': 'application/json' });
      res.end('{"code":"invalid_code","message":"Invalid code."}');
    });

    await expect(exchangeCode(site.url, 'code123')).rejects.not.toThrow(/in front of WordPress/);
  });

  const reportingSite = (site: string) =>
    serve((_req, res) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, data: { site, user: 'block-mcp', password: 'pw' } }));
    });

  it('refuses to save headers when the site reports a different address', async () => {
    const site = await reportingSite('https://other.example.com');

    await expect(exchangeCode(site.url, 'code123', fetch, 5000, TOKEN)).rejects.toThrow(
      /other\.example\.com.*WORDPRESS_CUSTOM_HEADERS was not saved/
    );
  });

  it('still connects to a site reporting a different address when no headers are set', async () => {
    const site = await reportingSite('https://other.example.com');

    const creds = await exchangeCode(site.url, 'code123');

    expect(creds.site).toBe('https://other.example.com');
  });

  it('accepts an HTTPS or www form of the same address with headers set', async () => {
    const httpsSite = await reportingSite('https://127.0.0.1');
    expect((await exchangeCode(httpsSite.url.replace(/:\d+$/, ''), 'x', mockFetch(httpsSite.url), 5000, TOKEN)).password).toBe('pw');

    const wwwSite = await reportingSite('https://www.site.test');
    expect((await exchangeCode('http://site.test', 'x', mockFetch(wwwSite.url), 5000, TOKEN)).password).toBe('pw');
  });

  it('refuses the login redirect without headers, and says how to fix it', async () => {
    const login = await serve(loginPage);
    const site = await serve(gateway(login.url));

    await expect(exchangeCode(site.url, 'code123')).rejects.toThrow(CUSTOM_HEADERS_ENV);
    expect(login.seen).toHaveLength(0);
  });
});

describe('saving the headers to the client config', () => {
  it('adds them to the config entry and to the claude mcp add arguments', () => {
    expect(buildMcpEntry(CREDS, undefined, TOKEN_JSON).env[CUSTOM_HEADERS_ENV]).toBe(TOKEN_JSON);
    expect(claudeCodeAddArgs(CREDS, 'block-mcp', undefined, TOKEN_JSON)).toContain(
      `${CUSTOM_HEADERS_ENV}=${TOKEN_JSON}`
    );
  });

  it('leaves them out when unset, so existing configs are unchanged', () => {
    expect(buildMcpEntry(CREDS).env).not.toHaveProperty(CUSTOM_HEADERS_ENV);
    expect(claudeCodeAddArgs(CREDS).join(' ')).not.toContain(CUSTOM_HEADERS_ENV);
  });

  it('hides the secret when printing, unless --reveal was used', () => {
    process.env[CUSTOM_HEADERS_ENV] = TOKEN_JSON;
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});

    printConfig(CREDS, false);
    const hidden = log.mock.calls.flat().join('\n');
    expect(hidden).not.toContain('probe-secret');
    expect(hidden).toContain(CUSTOM_HEADERS_ENV);

    log.mockClear();
    printConfig(CREDS, true);
    expect(log.mock.calls.flat().join('\n')).toContain('probe-secret');
  });

  it('shows the password but hides the headers when claude mcp add is unavailable', async () => {
    const originalPath = process.env.PATH;
    process.env[CUSTOM_HEADERS_ENV] = TOKEN_JSON;
    const log = vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});

    const site = await serve((req, res) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(`{"success":true,"data":{"site":"http://${req.headers.host}","user":"block-mcp","password":"minted-once"}}`);
    });
    const openBrowserFn = (authorizeUrl: string): void => {
      const authorize = new URL(authorizeUrl);
      const callback = new URL(authorize.searchParams.get('callback') as string);
      callback.searchParams.set('code', 'deadbeefcafef00d1122334455667788');
      callback.searchParams.set('state', authorize.searchParams.get('state') as string);
      http.get(callback.toString(), (res) => res.resume()).on('error', () => {});
      process.env.PATH = '/nonexistent';
    };

    try {
      await runConnect(['--site', site.url, '--client', 'claude-code', '--name', 'gated'], { openBrowserFn });
    } finally {
      process.env.PATH = originalPath;
    }

    const printed = log.mock.calls.flat().join('\n');
    expect(printed).toContain('minted-once');
    expect(printed).toContain(CUSTOM_HEADERS_ENV);
    expect(printed).not.toContain('probe-secret');
  });

  it('connect gets through the gateway and writes the headers to the config', async () => {
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'gk-custom-headers-'));
    const originalHome = process.env.HOME;
    process.env.HOME = home;
    process.env[CUSTOM_HEADERS_ENV] = TOKEN_JSON;
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});

    const login = await serve(loginPage);
    const site = await serve(
      gateway(login.url, '{"success":true,"data":{"site":"http://{{HOST}}","user":"block-mcp","password":"pw"}}')
    );

    const openBrowserFn = (authorizeUrl: string): void => {
      const authorize = new URL(authorizeUrl);
      const callback = new URL(authorize.searchParams.get('callback') as string);
      callback.searchParams.set('code', 'deadbeefcafef00d1122334455667788');
      callback.searchParams.set('state', authorize.searchParams.get('state') as string);
      http.get(callback.toString(), (res) => res.resume()).on('error', () => {});
    };

    try {
      await runConnect(['--site', site.url, '--client', 'cursor', '--name', 'gated'], { openBrowserFn });
      const config = JSON.parse(fs.readFileSync(path.join(home, '.cursor', 'mcp.json'), 'utf8'));
      expect(site.seen[0].headers['cf-access-client-secret']).toBe('probe-secret');
      expect(login.seen).toHaveLength(0);
      expect(config.mcpServers.gated.env[CUSTOM_HEADERS_ENV]).toBe(TOKEN_JSON);
    } finally {
      process.env.HOME = originalHome;
      fs.rmSync(home, { recursive: true, force: true });
    }
  });
});
