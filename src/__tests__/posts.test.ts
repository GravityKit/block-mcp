import { describe, it, expect, vi, beforeEach } from 'vitest';
import { POST_TOOLS, handlePostTool } from '../tools/posts.js';

const fakeCreateResponse = {
  success: true,
  id: 1,
  post_type: 'post',
  status: 'draft',
  title: 'X',
  slug: 'x',
  permalink: '',
  edit_link: '',
  before_revision_id: null,
  revision_id: null,
  warnings: [],
};

const fakeUpdateResponse = {
  ...fakeCreateResponse,
  status: 'publish',
  transitioned_to_publish: true,
};

const mockClient = {
  createPost: vi.fn().mockResolvedValue(fakeCreateResponse),
  updatePost: vi.fn().mockResolvedValue(fakeUpdateResponse),
} as any;

describe('POST_TOOLS', () => {
  it('exposes create_post and update_post', () => {
    const names = POST_TOOLS.map((t) => t.name);
    expect(names).toContain('create_post');
    expect(names).toContain('update_post');
  });

  it('create_post requires title in schema', () => {
    const tool = POST_TOOLS.find((t) => t.name === 'create_post')!;
    expect(tool.inputSchema.required).toContain('title');
  });

  it('update_post requires post_id in schema', () => {
    const tool = POST_TOOLS.find((t) => t.name === 'update_post')!;
    expect(tool.inputSchema.required).toContain('post_id');
  });
});

describe('handlePostTool — create_post', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires non-empty title', async () => {
    await expect(handlePostTool('create_post', {}, mockClient)).rejects.toThrow('title');
    await expect(handlePostTool('create_post', { title: '' }, mockClient)).rejects.toThrow('title');
    await expect(handlePostTool('create_post', { title: '   ' }, mockClient)).rejects.toThrow('title');
    expect(mockClient.createPost).not.toHaveBeenCalled();
  });

  it('rejects content + blocks together', async () => {
    await expect(
      handlePostTool(
        'create_post',
        { title: 'X', content: 'a', blocks: [{ name: 'core/paragraph' }] },
        mockClient,
      ),
    ).rejects.toThrow('mutually exclusive');
    expect(mockClient.createPost).not.toHaveBeenCalled();
  });

  it('passes args to client unchanged', async () => {
    await handlePostTool(
      'create_post',
      { title: 'Hello', status: 'draft', categories: [12] },
      mockClient,
    );
    expect(mockClient.createPost).toHaveBeenCalledWith({
      title: 'Hello',
      status: 'draft',
      categories: [12],
    });
  });

  it('returns the client response unchanged', async () => {
    const result = await handlePostTool('create_post', { title: 'X' }, mockClient);
    expect(result).toEqual(fakeCreateResponse);
  });
});

describe('handlePostTool — update_post', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requires post_id', async () => {
    await expect(
      handlePostTool('update_post', { title: 'X' }, mockClient),
    ).rejects.toThrow('post_id');
    expect(mockClient.updatePost).not.toHaveBeenCalled();
  });

  it('requires at least one mutating field besides post_id', async () => {
    await expect(
      handlePostTool('update_post', { post_id: 1 }, mockClient),
    ).rejects.toThrow('at least one mutating field');
    expect(mockClient.updatePost).not.toHaveBeenCalled();
  });

  it('separates id from body and forwards rest', async () => {
    await handlePostTool('update_post', { post_id: 99, status: 'publish', title: 'New' }, mockClient);
    expect(mockClient.updatePost).toHaveBeenCalledWith(99, { status: 'publish', title: 'New' });
  });

  it('returns the client response unchanged', async () => {
    const result = await handlePostTool('update_post', { post_id: 1, status: 'publish' }, mockClient);
    expect(result).toEqual(fakeUpdateResponse);
  });
});

describe('handlePostTool — unknown', () => {
  it('throws on unknown tool', async () => {
    await expect(handlePostTool('unknown', { title: 'X' }, mockClient)).rejects.toThrow('Unknown post tool');
  });
});
