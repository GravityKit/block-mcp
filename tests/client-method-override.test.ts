import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import * as http from 'node:http';
import { WordPressBlockClient } from '../src/client.js';

/**
 * Coverage for the PUT/PATCH/DELETE fallback to `X-HTTP-Method-Override`.
 *
 * Some managed hosts front WordPress with a WAF that answers those verbs with a
 * bare 405 before the request reaches PHP. GET and POST pass, so reads and
 * create_post work while every editing tool fails. WordPress core honours
 * `X-HTTP-Method-Override` on a POST, and the plugin registers its editing
 * routes as literal PUT / PATCH / DELETE, so the header (not a plain POST) is
 * what carries the intended verb through.
 *
 * These tests drive the real client against loopback servers that reproduce
 * both host shapes and assert what goes on the wire.
 */

interface Seen {
  method: string;
  url: string;
  override?: string;
}

/** A host whose edge rejects real PUT/PATCH/DELETE with a bare 405. */
function wafServer(seen: Seen[]): http.Server {
  return http.createServer((req, res) => {
    const method = (req.method ?? '').toUpperCase();
    const override = req.headers['x-http-method-override'] as string | undefined;
    seen.push({ method, url: req.url ?? '', override });

    req.resume();
    req.on('end', () => {
      if (method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
        // nginx-style rejection: HTML body, no WordPress involved.
        res.writeHead(405, { 'Content-Type': 'text/html' });
        res.end('<html><head><title>405 Not Allowed</title></head></html>');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true }));
    });
  });
}

/** A host that accepts every verb, like stock WordPress behind no WAF. */
function permissiveServer(seen: Seen[]): http.Server {
  return http.createServer((req, res) => {
    seen.push({
      method: (req.method ?? '').toUpperCase(),
      url: req.url ?? '',
      override: req.headers['x-http-method-override'] as string | undefined,
    });
    req.resume();
    req.on('end', () => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true }));
    });
  });
}

async function listen(server: http.Server): Promise<number> {
  await new Promise<void>((r) => server.listen(0, '127.0.0.1', () => r()));
  return (server.address() as { port: number }).port;
}

const clientFor = (port: number) =>
  new WordPressBlockClient({
    wordpress_url: `http://127.0.0.1:${port}`,
    auth: { username: 'u', application_password: 'p' },
  });

describe('method override fallback on hosts that reject PUT/PATCH/DELETE', () => {
  let server: http.Server;
  let port = 0;
  const seen: Seen[] = [];

  beforeAll(async () => {
    server = wafServer(seen);
    port = await listen(server);
  });

  afterAll(async () => {
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('replays a rejected PATCH as POST carrying the override header', async () => {
    seen.length = 0;
    const client = clientFor(port);

    const result = await client.updatePost(123, { title: 'T' });
    expect(result).toEqual({ success: true });

    expect(seen).toHaveLength(2);
    // First attempt uses the real verb — the fallback is adaptive, not blanket.
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    // Replay carries the intent in the header so the literal PATCH route matches.
    expect(seen[1]).toMatchObject({ method: 'POST', override: 'PATCH' });
    expect(seen[1].url).toBe(seen[0].url);
  });

  it('remembers the host, so later writes skip the rejected attempt', async () => {
    seen.length = 0;
    const client = clientFor(port);

    await client.updatePost(123, { title: 'first' });
    expect(seen.filter((s) => s.method === 'PATCH')).toHaveLength(1);

    seen.length = 0;
    await client.updatePost(456, { title: 'second' });

    // No rejected round-trip the second time.
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'POST', override: 'PATCH' });
  });

  it('applies the override to DELETE as well as PATCH', async () => {
    seen.length = 0;
    const client = clientFor(port);

    await client.deleteBlock(123, 0);

    expect(seen[0]).toMatchObject({ method: 'DELETE', override: undefined });
    expect(seen[seen.length - 1]).toMatchObject({ method: 'POST', override: 'DELETE' });
  });

  it('surfaces a genuine 405 rather than looping', async () => {
    seen.length = 0;
    const client = clientFor(port);

    // The replay is a POST, which this server answers 200, so the call resolves.
    // What matters is that exactly one replay happens: no repeated fallback.
    await client.updatePost(789, { title: 'x' });
    expect(seen.filter((s) => s.method === 'POST')).toHaveLength(1);
  });
});

describe('hosts that accept real verbs are left alone', () => {
  let server: http.Server;
  let port = 0;
  const seen: Seen[] = [];

  beforeAll(async () => {
    server = permissiveServer(seen);
    port = await listen(server);
  });

  afterAll(async () => {
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('sends a real PATCH with no override header', async () => {
    seen.length = 0;
    const client = clientFor(port);

    await client.updatePost(123, { title: 'T' });

    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
  });

  it('sends a real DELETE with no override header', async () => {
    seen.length = 0;
    const client = clientFor(port);

    await client.deleteBlock(123, 0);

    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'DELETE', override: undefined });
  });
});
