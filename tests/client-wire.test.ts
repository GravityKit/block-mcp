import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import * as http from 'node:http';
import { WordPressBlockClient } from '../src/client.js';

/**
 * Wire-level coverage for WordPressBlockClient request shaping.
 *
 * Most connector tests mock the client's methods, so the actual HTTP a request
 * produces — verb, path, Content-Type, body — went unverified. That's the gap
 * the upload multipart bug slipped through. These tests drive the client through
 * a real loopback server and assert what actually goes on the wire:
 *
 *  - upload_media `url` / `data_base64` ride as application/json (the multipart
 *    path is covered separately in upload-media.test.ts);
 *  - the write methods use the right verb + path + JSON body.
 */
describe('client request shaping (wire-level)', () => {
  let server: http.Server;
  let port = 0;
  let captured: { method?: string; url?: string; contentType?: string; body: string } = {
    body: '',
  };

  beforeAll(async () => {
    server = http.createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (c) => chunks.push(c as Buffer));
      req.on('end', () => {
        captured = {
          method: req.method,
          url: req.url,
          contentType: req.headers['content-type'],
          body: Buffer.concat(chunks).toString('utf8'),
        };
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ id: 1, source_url: 'http://x/y.png', success: true }));
      });
    });
    await new Promise<void>((r) => server.listen(0, '127.0.0.1', () => r()));
    port = (server.address() as { port: number }).port;
  });

  afterAll(async () => {
    await new Promise<void>((r) => server.close(() => r()));
  });

  const client = () =>
    new WordPressBlockClient({
      wordpress_url: `http://127.0.0.1:${port}`,
      auth: { username: 'u', application_password: 'p' },
    });

  const PREFIX = '/wp-json/gk-block-api/v1';

  it('upload_media url mode posts JSON, not multipart', async () => {
    await client().uploadMedia({ url: 'https://example.test/pic.png', title: 'T' });
    expect(captured.method).toBe('POST');
    expect(captured.url).toBe(`${PREFIX}/media`);
    expect(captured.contentType).toMatch(/^application\/json/);
    const json = JSON.parse(captured.body);
    expect(json.url).toBe('https://example.test/pic.png');
    expect(json.path).toBeUndefined();
  });

  it('upload_media data_base64 mode posts JSON with filename', async () => {
    await client().uploadMedia({ data_base64: 'aGVsbG8=', filename: 'hello.png', title: 'T' });
    expect(captured.contentType).toMatch(/^application\/json/);
    const json = JSON.parse(captured.body);
    expect(json.data_base64).toBe('aGVsbG8=');
    expect(json.filename).toBe('hello.png');
  });

  it('updateBlock PATCHes the per-block path as JSON', async () => {
    await client().updateBlock(7, 2, { innerHTML: '<p>x</p>' });
    expect(captured.method).toBe('PATCH');
    expect(captured.url).toBe(`${PREFIX}/posts/7/blocks/2`);
    expect(captured.contentType).toMatch(/^application\/json/);
    expect(JSON.parse(captured.body)).toMatchObject({ innerHTML: '<p>x</p>' });
  });

  it('insertBlocks POSTs the blocks path as JSON', async () => {
    await client().insertBlocks(7, { blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }] });
    expect(captured.method).toBe('POST');
    expect(captured.url).toBe(`${PREFIX}/posts/7/blocks`);
    expect(captured.contentType).toMatch(/^application\/json/);
    expect(JSON.parse(captured.body).blocks[0].name).toBe('core/paragraph');
  });

  it('replaceAllBlocks PUTs the blocks array as JSON', async () => {
    await client().replaceAllBlocks(7, [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }]);
    expect(captured.method).toBe('PUT');
    expect(captured.url).toBe(`${PREFIX}/posts/7/blocks`);
    expect(captured.contentType).toMatch(/^application\/json/);
    // replaceAllBlocks wraps the array as { blocks: [...] } on the wire.
    expect(JSON.parse(captured.body).blocks[0].name).toBe('core/paragraph');
  });
});
