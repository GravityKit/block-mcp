/**
 * Tool tests: upload_media
 *
 * Covers:
 *   - Schema: upload_media exposed
 *   - Validation: exactly one input mode (path, url, data_base64)
 *   - Validation: data_base64 requires filename
 *   - Request shape: url upload forwarded
 *   - Request shape: base64 upload forwarded
 *   - Request shape: path upload forwarded
 *   - Optional fields (alt_text, post_id) forwarded with url mode
 *   - Response shape: id, url, mime_type present
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { MEDIA_TOOLS, handleMediaTool } from '../../../tools/media.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('upload_media — schema', () => {
  it('exposes upload_media tool', () => {
    expect(MEDIA_TOOLS.map((t) => t.name)).toContain('upload_media');
  });
});

describe('upload_media — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects when no input mode is provided', async () => {
    await expect(handleMediaTool('upload_media', { alt_text: 'x' }, client as any))
      .rejects.toThrow(/path.*url.*data_base64/);
    expect(client.uploadMedia).not.toHaveBeenCalled();
  });

  it('rejects when multiple input modes are provided (path + url)', async () => {
    await expect(handleMediaTool('upload_media', {
      path: '/a.png', url: 'https://example.com/x.png',
    }, client as any)).rejects.toThrow('only one of');
    expect(client.uploadMedia).not.toHaveBeenCalled();
  });

  it('rejects when multiple input modes are provided (url + data_base64)', async () => {
    await expect(handleMediaTool('upload_media', {
      url: 'https://example.com/x.png', data_base64: 'aGVsbG8=', filename: 'x.png',
    }, client as any)).rejects.toThrow('only one of');
    expect(client.uploadMedia).not.toHaveBeenCalled();
  });

  it('rejects data_base64 without filename', async () => {
    await expect(handleMediaTool('upload_media', { data_base64: 'aGVsbG8=' }, client as any))
      .rejects.toThrow('filename');
    expect(client.uploadMedia).not.toHaveBeenCalled();
  });
});

describe('upload_media — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    vi.clearAllMocks();
  });

  it('forwards url upload to client', async () => {
    await handleMediaTool('upload_media', { url: 'https://example.com/img.png' }, client as any);
    expect(client.uploadMedia).toHaveBeenCalledWith({ url: 'https://example.com/img.png' });
  });

  it('forwards url upload with optional alt_text and post_id', async () => {
    await handleMediaTool('upload_media', {
      url: 'https://example.com/img.png', alt_text: 'hero image', post_id: 7,
    }, client as any);
    expect(client.uploadMedia).toHaveBeenCalledWith({
      url: 'https://example.com/img.png', alt_text: 'hero image', post_id: 7,
    });
  });

  it('forwards base64 upload with filename', async () => {
    await handleMediaTool('upload_media', {
      data_base64: 'aGVsbG8=', filename: 'hello.png',
    }, client as any);
    expect(client.uploadMedia).toHaveBeenCalledWith({
      data_base64: 'aGVsbG8=', filename: 'hello.png',
    });
  });

  it('forwards path upload', async () => {
    await handleMediaTool('upload_media', { path: '/tmp/photo.jpg' }, client as any);
    expect(client.uploadMedia).toHaveBeenCalledWith({ path: '/tmp/photo.jpg' });
  });
});

describe('upload_media — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    vi.clearAllMocks();
  });

  it('returns success, id, url, mime_type', async () => {
    const result = await handleMediaTool('upload_media', { url: 'https://example.com/x.png' }, client as any) as any;
    expect(result.success).toBe(true);
    expect(result.id).toBe(1);
    expect(result.url).toBeDefined();
    expect(result.mime_type).toBe('image/png');
  });

  it('returns filename and alt_text', async () => {
    const result = await handleMediaTool('upload_media', { path: '/tmp/x.png' }, client as any) as any;
    expect(result.filename).toBeDefined();
    expect(typeof result.alt_text).toBe('string');
  });
});

describe('upload_media — unknown tool', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('throws on unknown tool name', async () => {
    await expect(handleMediaTool('unknown_tool', {}, client as any))
      .rejects.toThrow('Unknown media tool');
  });
});
