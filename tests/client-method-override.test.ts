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
 * both host shapes and assert what actually goes on the wire.
 */

interface Seen {
  method: string;
  url: string;
  override?: string;
  auth?: string;
  contentType?: string;
  body: string;
}

/** Decides each response, so a test can shape the host's behaviour. */
type Policy = (req: { method: string; override?: string; nth: number }) => {
  status: number;
  json?: unknown;
};

/** A host that answers real PUT/PATCH/DELETE with a bare nginx-style 405. */
const wafPolicy: Policy = ({ method }) => {
  const rejected = method === 'PUT' || method === 'PATCH' || method === 'DELETE';
  return rejected ? { status: 405 } : { status: 200, json: { success: true } };
};

/** A host that accepts every verb, like stock WordPress behind no WAF. */
const permissivePolicy: Policy = () => ({ status: 200, json: { success: true } });

function startServer(seen: Seen[], policy: Policy): Promise<{ server: http.Server; port: number }> {
  let nth = 0;
  const server = http.createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (c) => chunks.push(c as Buffer));
    req.on('end', () => {
      const method = (req.method ?? '').toUpperCase();
      const override = req.headers['x-http-method-override'] as string | undefined;
      seen.push({
        method,
        url: req.url ?? '',
        override,
        auth: req.headers.authorization as string | undefined,
        contentType: req.headers['content-type'] as string | undefined,
        body: Buffer.concat(chunks).toString('utf8'),
      });

      nth += 1;
      const { status, json } = policy({ method, override, nth });
      if (json === undefined) {
        // Edge rejection: HTML body, no WordPress involved.
        res.writeHead(status, { 'Content-Type': 'text/html' });
        res.end('<html><head><title>405 Not Allowed</title></head></html>');
        return;
      }
      res.writeHead(status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(json));
    });
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () =>
      resolve({ server, port: (server.address() as { port: number }).port }),
    );
  });
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
    ({ server, port } = await startServer(seen, wafPolicy));
  });

  afterAll(async () => {
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('replays a rejected PATCH as POST carrying the override header', async () => {
    seen.length = 0;
    const result = await clientFor(port).updatePost(123, { title: 'T' });
    expect(result).toEqual({ success: true });

    expect(seen).toHaveLength(2);
    // First attempt uses the real verb — the fallback is adaptive, not blanket.
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    expect(seen[1]).toMatchObject({ method: 'POST', override: 'PATCH' });
    expect(seen[1].url).toBe(seen[0].url);
  });

  it('replays a rejected PUT, the verb behind a full rewrite', async () => {
    seen.length = 0;
    await clientFor(port).replaceAllBlocks(123, [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }]);

    expect(seen[0]).toMatchObject({ method: 'PUT', override: undefined });
    expect(seen[1]).toMatchObject({ method: 'POST', override: 'PUT' });
  });

  it('replays a rejected DELETE', async () => {
    seen.length = 0;
    await clientFor(port).deleteBlock(123, 0);

    expect(seen[0]).toMatchObject({ method: 'DELETE', override: undefined });
    expect(seen[1]).toMatchObject({ method: 'POST', override: 'DELETE' });
  });

  it('carries the request body through the replay unchanged', async () => {
    seen.length = 0;
    await clientFor(port).updatePost(123, { title: 'Keep me', excerpt: 'And me' });

    // A dropped body would silently write nothing — assert both attempts match.
    expect(JSON.parse(seen[1].body)).toEqual({ title: 'Keep me', excerpt: 'And me' });
    expect(seen[1].body).toBe(seen[0].body);
    expect(seen[1].contentType).toMatch(/^application\/json/);
  });

  it('preserves query parameters through the replay', async () => {
    seen.length = 0;
    await clientFor(port).deleteBlock(123, 2, 3);

    expect(seen[0].url).toContain('count=3');
    expect(seen[1].url).toBe(seen[0].url);
  });

  it('preserves authentication through the replay', async () => {
    seen.length = 0;
    await clientFor(port).updatePost(123, { title: 'T' });

    expect(seen[1].auth).toBeDefined();
    expect(seen[1].auth).toBe(seen[0].auth);
    expect(seen[1].auth).toMatch(/^Basic /);
  });

  it('remembers the host, so later writes skip the rejected attempt', async () => {
    const client = clientFor(port);
    seen.length = 0;
    await client.updatePost(123, { title: 'first' });
    expect(seen.filter((s) => s.method === 'PATCH')).toHaveLength(1);

    seen.length = 0;
    await client.updatePost(456, { title: 'second' });
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'POST', override: 'PATCH' });
  });

  it('replays every concurrent rejection, not just the first', async () => {
    // Writes issued together all go out as real verbs, because none of them has
    // seen a 405 yet. Replaying only the first would leave the rest surfacing a
    // 405 the caller can do nothing about.
    seen.length = 0;
    const client = clientFor(port);

    const results = await Promise.all([
      client.updatePost(1, { title: 'a' }),
      client.updatePost(2, { title: 'b' }),
      client.updatePost(3, { title: 'c' }),
    ]);

    expect(results).toEqual([{ success: true }, { success: true }, { success: true }]);
    expect(seen.filter((s) => s.method === 'PATCH')).toHaveLength(3);
    expect(seen.filter((s) => s.method === 'POST' && s.override === 'PATCH')).toHaveLength(3);
  });

  it('keeps the fallback per client, not global', async () => {
    // One client learning the host must not silence the real-verb attempt for
    // a client pointed at a different (possibly healthy) host.
    const flagged = clientFor(port);
    await flagged.updatePost(1, { title: 'flag me' });

    seen.length = 0;
    await clientFor(port).updatePost(2, { title: 'fresh client' });
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
  });

  it('leaves GET alone once the fallback is active', async () => {
    const client = clientFor(port);
    await client.updatePost(1, { title: 'flag me' });

    seen.length = 0;
    await client.getBlockTypes();
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'GET', override: undefined });
  });
});

describe('the fallback stays narrowly scoped', () => {
  const seen: Seen[] = [];
  let server: http.Server;
  let port = 0;

  afterAll(async () => {
    if (server) await new Promise<void>((r) => server.close(() => r()));
  });

  it('does not engage for a 405 on POST', async () => {
    seen.length = 0;
    // A host that 405s everything, including POST.
    ({ server, port } = await startServer(seen, () => ({ status: 405 })));

    await expect(clientFor(port).createPost({ title: 'T' })).rejects.toThrow(/405/);
    // POST is not an override verb: one attempt, no replay.
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'POST', override: undefined });
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('does not engage for non-405 failures on an override verb', async () => {
    seen.length = 0;
    ({ server, port } = await startServer(seen, () => ({
      status: 403,
      json: { code: 'rest_forbidden', message: 'nope' },
    })));

    await expect(clientFor(port).updatePost(1, { title: 'T' })).rejects.toThrow(/403/);
    // A real permission error must surface, not get masked by a replay.
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('ignores a structured 405 answered by the REST API', async () => {
    seen.length = 0;
    // A 405 carrying a REST error body is a real answer, not an edge rejection.
    // Replaying it as POST could reach a different route, so it must surface.
    ({ server, port } = await startServer(seen, () => ({
      status: 405,
      json: { code: 'rest_method_not_allowed', message: 'nope' },
    })));

    await expect(clientFor(port).updatePost(1, { title: 'T' })).rejects.toThrow(/405/);
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('does not remember the host when the replay is also rejected', async () => {
    seen.length = 0;
    ({ server, port } = await startServer(seen, () => ({ status: 405 })));
    const client = clientFor(port);

    await expect(client.updatePost(1, { title: 'a' })).rejects.toThrow(/405/);
    seen.length = 0;

    // The fallback never succeeded, so the next write must still probe with the
    // real verb rather than assume the override works.
    await expect(client.updatePost(2, { title: 'b' })).rejects.toThrow(/405/);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('surfaces the error and stops when the replay is rejected too', async () => {
    seen.length = 0;
    // 405s the real verb AND the overridden POST — a host we cannot write to.
    ({ server, port } = await startServer(seen, () => ({ status: 405 })));

    await expect(clientFor(port).updatePost(1, { title: 'T' })).rejects.toThrow(/405/);
    // Exactly one replay: the retry carries method POST, so it cannot loop.
    expect(seen).toHaveLength(2);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
    expect(seen[1]).toMatchObject({ method: 'POST', override: 'PATCH' });
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('keeps the override header across a transient-error retry', async () => {
    seen.length = 0;
    // 405 the real verb, 429 the first replay, then succeed: the backoff retry
    // must not strip the override the earlier fallback established.
    ({ server, port } = await startServer(seen, ({ method, override }) => {
      if (method === 'PATCH') return { status: 405 };
      if (override === 'PATCH' && seen.filter((s) => s.override === 'PATCH').length === 1) {
        return { status: 429, json: { code: 'too_many_requests' } };
      }
      return { status: 200, json: { success: true } };
    }));

    await expect(clientFor(port).updatePost(1, { title: 'T' })).resolves.toEqual({ success: true });
    const overridden = seen.filter((s) => s.method === 'POST' && s.override === 'PATCH');
    expect(overridden.length).toBeGreaterThanOrEqual(2);
    await new Promise<void>((r) => server.close(() => r()));
  });
});

describe('hosts that accept real verbs are left alone', () => {
  let server: http.Server;
  let port = 0;
  const seen: Seen[] = [];

  beforeAll(async () => {
    ({ server, port } = await startServer(seen, permissivePolicy));
  });

  afterAll(async () => {
    await new Promise<void>((r) => server.close(() => r()));
  });

  it('sends a real PATCH with no override header', async () => {
    seen.length = 0;
    await clientFor(port).updatePost(123, { title: 'T' });
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'PATCH', override: undefined });
  });

  it('sends a real DELETE with no override header', async () => {
    seen.length = 0;
    await clientFor(port).deleteBlock(123, 0);
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'DELETE', override: undefined });
  });

  it('sends a real PUT with no override header', async () => {
    seen.length = 0;
    await clientFor(port).replaceAllBlocks(123, [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }]);
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ method: 'PUT', override: undefined });
  });
});
