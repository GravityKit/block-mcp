/**
 * Tool tests: create_post
 *
 * Covers:
 *   - Schema: title required
 *   - Validation: non-empty title (empty, whitespace-only)
 *   - Validation: content + blocks mutually exclusive
 *   - Happy path: args forwarded to client
 *   - Response shape passthrough
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { POST_TOOLS, handlePostTool } from '../../../tools/posts.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { createPostResponse } from '../../fixtures/rest-responses.js';

describe('create_post — schema', () => {
  it('exposes create_post tool', () => {
    expect(POST_TOOLS.map((t) => t.name)).toContain('create_post');
  });

  it('requires title in inputSchema', () => {
    const tool = POST_TOOLS.find((t) => t.name === 'create_post')!;
    expect(tool.inputSchema.required).toContain('title');
  });
});

describe('create_post — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects missing title', async () => {
    await expect(handlePostTool('create_post', {}, client as any)).rejects.toThrow('title');
    expect(client.createPost).not.toHaveBeenCalled();
  });

  it('rejects empty-string title', async () => {
    await expect(handlePostTool('create_post', { title: '' }, client as any)).rejects.toThrow('title');
    expect(client.createPost).not.toHaveBeenCalled();
  });

  it('rejects whitespace-only title', async () => {
    await expect(handlePostTool('create_post', { title: '   ' }, client as any)).rejects.toThrow('title');
    expect(client.createPost).not.toHaveBeenCalled();
  });

  it('rejects content and blocks together (mutually exclusive)', async () => {
    await expect(handlePostTool('create_post', {
      title: 'X', content: 'some text', blocks: [{ name: 'core/paragraph' }],
    }, client as any)).rejects.toThrow('mutually exclusive');
    expect(client.createPost).not.toHaveBeenCalled();
  });
});

describe('create_post — happy path', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.createPost.mockResolvedValue(createPostResponse);
    vi.clearAllMocks();
  });

  it('forwards args to client', async () => {
    await handlePostTool('create_post', {
      title: 'Hello', status: 'draft', categories: [12],
    }, client as any);
    expect(client.createPost).toHaveBeenCalledWith({
      title: 'Hello', status: 'draft', categories: [12],
    });
  });

  it('returns client response unchanged', async () => {
    const result = await handlePostTool('create_post', { title: 'X' }, client as any);
    expect(result).toEqual(createPostResponse);
  });

  it('response includes success, id, post_type, status', async () => {
    const result = await handlePostTool('create_post', { title: 'X' }, client as any) as any;
    expect(result.success).toBe(true);
    expect(result.id).toBe(9999);
    expect(result.post_type).toBe('post');
    expect(result.status).toBe('draft');
  });
});
