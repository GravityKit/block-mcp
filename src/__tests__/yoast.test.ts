import { describe, it, expect, vi, beforeEach } from 'vitest';
import { YOAST_TOOLS, handleYoastTool } from '../tools/yoast.js';

const fakeMeta = {
  post_id: 42,
  title: 'Old',
  description: 'Old desc',
  noindex: null,
  seo_score: 80,
  readability_score: 70,
  inclusive_language_score: null,
};

const mockClient = {
  getYoastSEO: vi.fn().mockResolvedValue(fakeMeta),
  updateYoastSEO: vi.fn().mockResolvedValue({ ...fakeMeta, title: 'New' }),
  bulkUpdateYoastSEO: vi.fn().mockResolvedValue([
    { post_id: 1, success: true, seo: fakeMeta },
    { post_id: 2, success: true, seo: { ...fakeMeta, post_id: 2 } },
  ]),
} as any;

describe('YOAST_TOOLS', () => {
  it('exposes the three yoast tools', () => {
    const names = YOAST_TOOLS.map((t) => t.name);
    expect(names).toEqual(['yoast_get_seo', 'yoast_update_seo', 'yoast_bulk_update_seo']);
  });

  it('each tool requires post_id (or posts for bulk)', () => {
    const get = YOAST_TOOLS.find((t) => t.name === 'yoast_get_seo')!;
    const upd = YOAST_TOOLS.find((t) => t.name === 'yoast_update_seo')!;
    const bulk = YOAST_TOOLS.find((t) => t.name === 'yoast_bulk_update_seo')!;
    expect(get.inputSchema.required).toContain('post_id');
    expect(upd.inputSchema.required).toContain('post_id');
    expect(bulk.inputSchema.required).toContain('posts');
  });
});

describe('handleYoastTool — yoast_get_seo', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires post_id as a number', async () => {
    await expect(handleYoastTool('yoast_get_seo', {}, mockClient)).rejects.toThrow('post_id');
    await expect(handleYoastTool('yoast_get_seo', { post_id: '42' }, mockClient)).rejects.toThrow('post_id');
    expect(mockClient.getYoastSEO).not.toHaveBeenCalled();
  });

  it('forwards post_id to the client', async () => {
    await handleYoastTool('yoast_get_seo', { post_id: 42 }, mockClient);
    expect(mockClient.getYoastSEO).toHaveBeenCalledWith(42);
  });
});

describe('handleYoastTool — yoast_update_seo', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires post_id', async () => {
    await expect(
      handleYoastTool('yoast_update_seo', { title: 'New' }, mockClient),
    ).rejects.toThrow('post_id');
    expect(mockClient.updateYoastSEO).not.toHaveBeenCalled();
  });

  it('requires at least one mutating field besides post_id', async () => {
    await expect(
      handleYoastTool('yoast_update_seo', { post_id: 1 }, mockClient),
    ).rejects.toThrow('at least one Yoast field');
    expect(mockClient.updateYoastSEO).not.toHaveBeenCalled();
  });

  it('forwards narrowed fields', async () => {
    await handleYoastTool(
      'yoast_update_seo',
      { post_id: 1, title: 'New', noindex: true, hostile_extra: 'ignored' },
      mockClient,
    );
    expect(mockClient.updateYoastSEO).toHaveBeenCalledWith(1, { title: 'New', noindex: true });
  });

  it('preserves null in noindex (tri-state)', async () => {
    await handleYoastTool(
      'yoast_update_seo',
      { post_id: 1, noindex: null },
      mockClient,
    );
    expect(mockClient.updateYoastSEO).toHaveBeenCalledWith(1, { noindex: null });
  });

  it('filters robots_advanced to known directives', async () => {
    await handleYoastTool(
      'yoast_update_seo',
      { post_id: 1, robots_advanced: ['noarchive', 'evil', 'nosnippet'] },
      mockClient,
    );
    expect(mockClient.updateYoastSEO).toHaveBeenCalledWith(1, {
      robots_advanced: ['noarchive', 'nosnippet'],
    });
  });

  it('drops out-of-enum schema types', async () => {
    await handleYoastTool(
      'yoast_update_seo',
      { post_id: 1, schema_page_type: 'BogusType', title: 'X' },
      mockClient,
    );
    expect(mockClient.updateYoastSEO).toHaveBeenCalledWith(1, { title: 'X' });
  });
});

describe('handleYoastTool — yoast_bulk_update_seo', () => {
  beforeEach(() => vi.clearAllMocks());

  it('rejects empty posts array', async () => {
    await expect(
      handleYoastTool('yoast_bulk_update_seo', { posts: [] }, mockClient),
    ).rejects.toThrow('non-empty');
    expect(mockClient.bulkUpdateYoastSEO).not.toHaveBeenCalled();
  });

  it('rejects items missing post_id', async () => {
    await expect(
      handleYoastTool(
        'yoast_bulk_update_seo',
        { posts: [{ title: 'X' }] },
        mockClient,
      ),
    ).rejects.toThrow('post_id');
    expect(mockClient.bulkUpdateYoastSEO).not.toHaveBeenCalled();
  });

  it('forwards a normalized item list', async () => {
    await handleYoastTool(
      'yoast_bulk_update_seo',
      {
        posts: [
          { post_id: 1, title: 'A', evil: 'x' },
          { post_id: 2, noindex: false, focus_keyword: 'B' },
        ],
      },
      mockClient,
    );
    expect(mockClient.bulkUpdateYoastSEO).toHaveBeenCalledWith([
      { post_id: 1, title: 'A' },
      { post_id: 2, noindex: false, focus_keyword: 'B' },
    ]);
  });
});

describe('handleYoastTool — unknown', () => {
  it('throws on unknown tool', async () => {
    await expect(handleYoastTool('unknown', {}, mockClient)).rejects.toThrow('Unknown yoast tool');
  });
});
