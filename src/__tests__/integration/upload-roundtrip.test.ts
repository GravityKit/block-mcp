import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import { skipUnlessLive, makeLiveClient, LIVE_ENV } from './setup.js';

/**
 * Connector ↔ plugin media-upload round-trip.
 *
 * The upload multipart bug passed every other suite because nothing ran the
 * real connector against a real WordPress: the TS tests mocked the client, the
 * PHP tests dispatched synthetic WP_REST_Requests. This exercises both ends
 * together — the connector serializes a real request, WordPress's /media route
 * receives and stores it — for both upload modes.
 *
 * Skips cleanly offline (no WORDPRESS_URL/USER/APP_PASSWORD); the CI "integration"
 * job boots a real WordPress and sets those so this runs there.
 */

// 1x1 transparent PNG.
const PNG_B64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

describe.skipIf(skipUnlessLive())('integration: connector ↔ plugin media upload', () => {
  it('uploads a local file via path mode (real multipart round-trip)', async () => {
    const client = makeLiveClient();
    const tmp = path.join(os.tmpdir(), `gk-int-upload-${process.pid}.png`);
    fs.writeFileSync(tmp, Buffer.from(PNG_B64, 'base64'));
    try {
      const r = (await client.uploadMedia({
        path: tmp,
        title: `${LIVE_ENV.prefix} path upload`,
        alt_text: 'integration path upload',
      })) as { id: number; source_url?: string; url?: string };
      expect(typeof r.id).toBe('number');
      expect(r.id).toBeGreaterThan(0);
      expect(String(r.source_url ?? r.url)).toMatch(/\.png$/i);
    } finally {
      fs.rmSync(tmp, { force: true });
    }
  });

  it('uploads via data_base64 mode', async () => {
    const client = makeLiveClient();
    const r = (await client.uploadMedia({
      data_base64: PNG_B64,
      filename: 'gk-int-upload.png',
      title: `${LIVE_ENV.prefix} base64 upload`,
      alt_text: 'integration base64 upload',
    })) as { id: number; source_url?: string; url?: string };
    expect(typeof r.id).toBe('number');
    expect(r.id).toBeGreaterThan(0);
  });
});
