import { describe, it, expect, vi, beforeEach } from 'vitest';
import { MEDIA_TOOLS, handleMediaTool } from '../tools/media.js';

const mockClient = {
  uploadMedia: vi.fn().mockResolvedValue({
    success: true,
    id: 42,
    title: 'fixture',
    filename: 'fixture.png',
    url: 'https://example.test/fixture.png',
    source_url: 'https://example.test/fixture.png',
    mime_type: 'image/png',
    alt_text: '',
    post_parent: 0,
  }),
} as any;

describe('MEDIA_TOOLS', () => {
  it('exposes upload_media', () => {
    expect(MEDIA_TOOLS.map((t) => t.name)).toContain('upload_media');
  });
});

describe('handleMediaTool', () => {
  beforeEach(() => vi.clearAllMocks());

  it('rejects when no input mode is provided', async () => {
    await expect(
      handleMediaTool('upload_media', { alt_text: 'x' }, mockClient),
    ).rejects.toThrow(/path.*url.*data_base64/);
    expect(mockClient.uploadMedia).not.toHaveBeenCalled();
  });

  it('rejects when multiple input modes are provided', async () => {
    await expect(
      handleMediaTool(
        'upload_media',
        { path: '/a.png', url: 'https://example.com/x.png' },
        mockClient,
      ),
    ).rejects.toThrow('only one of');
    expect(mockClient.uploadMedia).not.toHaveBeenCalled();
  });

  it('rejects data_base64 without filename', async () => {
    await expect(
      handleMediaTool(
        'upload_media',
        { data_base64: 'aGVsbG8=' },
        mockClient,
      ),
    ).rejects.toThrow('filename');
    expect(mockClient.uploadMedia).not.toHaveBeenCalled();
  });

  it('forwards a url upload to the client', async () => {
    await handleMediaTool(
      'upload_media',
      { url: 'https://example.com/x.png', alt_text: 'x', post_id: 7 },
      mockClient,
    );
    expect(mockClient.uploadMedia).toHaveBeenCalledWith({
      url: 'https://example.com/x.png',
      alt_text: 'x',
      post_id: 7,
    });
  });

  it('forwards a base64 upload (with filename) to the client', async () => {
    await handleMediaTool(
      'upload_media',
      { data_base64: 'aGVsbG8=', filename: 'sample.png' },
      mockClient,
    );
    expect(mockClient.uploadMedia).toHaveBeenCalledWith({
      data_base64: 'aGVsbG8=',
      filename: 'sample.png',
    });
  });

  it('forwards a path upload to the client', async () => {
    await handleMediaTool('upload_media', { path: '/tmp/x.png' }, mockClient);
    expect(mockClient.uploadMedia).toHaveBeenCalledWith({ path: '/tmp/x.png' });
  });

  it('throws on unknown tool', async () => {
    await expect(handleMediaTool('unknown', {}, mockClient)).rejects.toThrow('Unknown media tool');
  });
});
