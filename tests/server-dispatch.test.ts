import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import * as http from 'node:http';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

/**
 * Live dispatch-path regression for tool-argument validation.
 *
 * tests/validate-args.test.ts proves validateToolArgs() works in isolation —
 * but not that the running server actually calls it. This drives the REAL
 * stack: an MCP client speaking stdio to the built bundle (dist/index.cjs),
 * whose WordPress is a loopback fake. A misnamed insert_blocks position key
 * must be rejected at dispatch — with the valid anchors named — and the
 * request must never reach WordPress. Correct anchors must pass through.
 *
 * Teeth: remove the validateToolArgs() call from the CallTool handler in
 * src/index.ts (and rebuild) and the first test fails — the bad key is
 * silently dropped, the insert "succeeds", and the POST hits WordPress.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_BUNDLE = path.resolve(__dirname, '../dist/index.cjs');

describe('live dispatch: CallTool validates args against inputSchema', () => {
  let wp: http.Server;
  const posts: Array<{ url: string; body: string }> = [];
  let client: Client | undefined;

  beforeAll(async () => {
    // Fake WordPress: record POSTs, answer everything 200 with an insert-shaped body.
    wp = http.createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (c) => chunks.push(c as Buffer));
      req.on('end', () => {
        if (req.method === 'POST') {
          posts.push({ url: req.url ?? '', body: Buffer.concat(chunks).toString('utf8') });
        }
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(
          JSON.stringify({
            success: true,
            inserted: [
              { index: 0, top_level_counter: 0, path: [0], name: 'core/paragraph', ref: 'blk_test0000' },
            ],
            warnings: [],
            before_revision_id: 1,
            revision_id: 2,
          }),
        );
      });
    });
    await new Promise<void>((r) => wp.listen(0, '127.0.0.1', () => r()));
    const port = (wp.address() as { port: number }).port;

    const transport = new StdioClientTransport({
      command: process.execPath, // absolute node path — no PATH dependence
      args: [SERVER_BUNDLE],
      env: {
        WORDPRESS_URL: `http://127.0.0.1:${port}`,
        WORDPRESS_USER: 'u',
        WORDPRESS_APP_PASSWORD: 'p',
        BLOCK_MCP_INSTRUCTIONS_OFF: '1', // skip the startup addendum fetch
      },
      stderr: 'ignore',
    });
    client = new Client({ name: 'dispatch-test', version: '0.0.0' });
    await client.connect(transport);
  }, 20000);

  afterAll(async () => {
    await client?.close();
    await new Promise<void>((r) => wp.close(() => r()));
  });

  function textOf(result: unknown): string {
    const content = ((result as { content?: unknown }).content ?? []) as Array<{
      type: string;
      text?: string;
    }>;
    return content.map((c) => c.text ?? '').join('\n');
  }

  it('rejects a misnamed position key at dispatch — nothing reaches WordPress', async () => {
    const result = await client!.callTool({
      name: 'insert_blocks',
      arguments: {
        post_id: 1,
        after: 'start', // WRONG key — the real anchor is after_top_level/before_top_level
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
      },
    });
    const text = textOf(result);
    expect(text).toMatch(/Unknown parameter/i);
    expect(text).toContain("'after'");
    expect(text).toContain('after_top_level'); // valid anchors are named
    expect(posts.length).toBe(0); // rejected BEFORE any HTTP left the server
  });

  it('correct anchors pass validation and reach WordPress', async () => {
    const result = await client!.callTool({
      name: 'insert_blocks',
      arguments: {
        post_id: 1,
        before_top_level: 0,
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>x</p>' }],
      },
    });
    expect(textOf(result)).not.toMatch(/Unknown parameter/i);
    expect(posts.length).toBe(1);
    expect(posts[0].url).toContain('/posts/1/blocks');
    // The tool arg `before_top_level` maps to the REST wire param `before`
    // (the 1.4.0 tool-surface rename; the handler translates back).
    expect(JSON.parse(posts[0].body)).toMatchObject({ before: 0 });
  });
});
