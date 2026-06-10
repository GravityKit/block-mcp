import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import * as http from 'node:http';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { WordPressBlockClient } from '../src/client.js';

/**
 * Regression + e2e for upload_media `path` mode.
 *
 * The bug: the shared axios instance defaults Content-Type to application/json,
 * so posting a FormData made axios JSON-serialize it and send NO file — the
 * WordPress /media endpoint replied `missing_file` (400). It went unnoticed
 * because nothing exercised the real request serialization for uploads.
 *
 * This drives uploadMedia through a real loopback HTTP server so axios's actual
 * transform/serialization runs, then asserts the wire bytes are a true
 * multipart/form-data request carrying the file field + the file's bytes.
 * Teeth: revert the fix (drop form.getHeaders()) and the Content-Type comes back
 * application/json with no file part — every assertion below fails.
 */
describe('uploadMedia path mode posts real multipart/form-data', () => {
  let server: http.Server;
  let port = 0;
  let captured: { contentType?: string; body: Buffer } = { body: Buffer.alloc(0) };
  let tmpFile = '';
  const FILE_BYTES = Buffer.from('\x89PNG\r\n\x1a\n-block-mcp-upload-marker-9f3a2b1c');

  beforeAll(async () => {
    tmpFile = path.join(os.tmpdir(), `block-mcp-upload-${process.pid}.png`);
    fs.writeFileSync(tmpFile, FILE_BYTES);

    server = http.createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (c) => chunks.push(c as Buffer));
      req.on('end', () => {
        captured = {
          contentType: req.headers['content-type'],
          body: Buffer.concat(chunks),
        };
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ id: 4242, source_url: 'http://example.test/x.png' }));
      });
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', () => resolve()));
    port = (server.address() as { port: number }).port;
  });

  afterAll(async () => {
    fs.rmSync(tmpFile, { force: true });
    await new Promise<void>((resolve) => server.close(() => resolve()));
  });

  it('sends the file as multipart with the file field, filename, and bytes', async () => {
    const client = new WordPressBlockClient({
      wordpress_url: `http://127.0.0.1:${port}`,
      auth: { username: 'u', application_password: 'p' },
    });

    const result = await client.uploadMedia({ path: tmpFile, title: 'T', alt_text: 'A' });
    expect(result).toMatchObject({ id: 4242 });

    // Wire-level proof it was a real multipart upload (the regression).
    expect(captured.contentType).toMatch(/^multipart\/form-data; boundary=/);

    const text = captured.body.toString('latin1');
    expect(text).toContain('name="file"');
    expect(text).toContain(`filename="${path.basename(tmpFile)}"`);
    expect(text).toContain('Content-Type: image/png');
    expect(text).toContain('name="title"');
    expect(captured.body.includes(FILE_BYTES)).toBe(true);
  });
});
