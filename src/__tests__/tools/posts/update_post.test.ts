/**
 * Tool tests: update_post
 *
 * Covers:
 *   - Schema: post_id required
 *   - Validation: post_id required
 *   - Validation: at least one mutating field besides post_id
 *   - Request shape: separates post_id from body
 *   - Response shape passthrough
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { POST_TOOLS, handlePostTool } from '../../../tools/posts.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { updatePostResponse } from '../../fixtures/rest-responses.js';

describe('update_post — schema', () => {
  it('exposes update_post tool', () => {
    expect(POST_TOOLS.map((t) => t.name)).toContain('update_post');
  });

  it('requires post_id in inputSchema', () => {
    const tool = POST_TOOLS.find((t) => t.name === 'update_post')!;
    expect(tool.inputSchema.required).toContain('post_id');
  });
});

describe('update_post — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('requires post_id', async () => {
    await expect(handlePostTool('update_post', { title: 'X' }, client as any))
      .rejects.toThrow('post_id');
    expect(client.updatePost).not.toHaveBeenCalled();
  });

  it('requires at least one mutating field besides post_id', async () => {
    await expect(handlePostTool('update_post', { post_id: 1 }, client as any))
      .rejects.toThrow('at least one mutating field');
    expect(client.updatePost).not.toHaveBeenCalled();
  });
});

describe('update_post — request shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.updatePost.mockResolvedValue(updatePostResponse);
    vi.clearAllMocks();
  });

  it('separates post_id from body and forwards remaining fields', async () => {
    await handlePostTool('update_post', { post_id: 99, status: 'publish', title: 'New' }, client as any);
    expect(client.updatePost).toHaveBeenCalledWith(99, { status: 'publish', title: 'New' });
  });

  it('post_id is not included in the body', async () => {
    await handlePostTool('update_post', { post_id: 5, status: 'draft' }, client as any);
    const body = client.updatePost.mock.calls[0]![1] as Record<string, unknown>;
    expect(body).not.toHaveProperty('post_id');
  });

  // Some MCP clients / untyped JSON send numeric IDs as strings. get_post_info
  // already coerces them; update_post must accept the same, or the same post is
  // editable via one tool and rejected by another in one session.
  it('coerces a numeric-string post_id to a number', async () => {
    await handlePostTool('update_post', { post_id: '42', status: 'draft' }, client as any);
    expect(client.updatePost).toHaveBeenCalledWith(42, { status: 'draft' });
  });

  it('rejects a non-numeric post_id string', async () => {
    await expect(handlePostTool('update_post', { post_id: 'abc', status: 'draft' }, client as any))
      .rejects.toThrow('post_id must be a positive integer');
    expect(client.updatePost).not.toHaveBeenCalled();
  });
});

describe('update_post — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.updatePost.mockResolvedValue(updatePostResponse);
    vi.clearAllMocks();
  });

  it('returns client response unchanged', async () => {
    const result = await handlePostTool('update_post', { post_id: 1, status: 'publish' }, client as any);
    expect(result).toEqual(updatePostResponse);
  });

  it('response includes transitioned_to_publish', async () => {
    const result = await handlePostTool('update_post', { post_id: 1, status: 'publish' }, client as any) as any;
    expect(result.transitioned_to_publish).toBe(true);
  });
});

describe('update_post — unknown tool', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('throws on unknown tool name', async () => {
    await expect(handlePostTool('unknown_tool', { post_id: 1 }, client as any))
      .rejects.toThrow('Unknown post tool');
  });
});
