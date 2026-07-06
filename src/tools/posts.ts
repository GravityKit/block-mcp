/**
 * Post lifecycle tools.
 *
 * - `create_post`: create a new post or page (draft, publish, etc.) optionally
 *    with structured blocks or raw content.
 * - `update_post`: partial update of metadata, status, or terms. Status `trash`
 *    trashes the post; any non-trash status untrashes a trashed post.
 *
 * Block content edits stay on the per-block tools.
 */

import type { WordPressBlockClient } from '../client.js';
import type { CreatePostRequest, UpdatePostRequest } from '../types.js';
import { BLOCK_INPUT_SCHEMA } from './write.js';
import { coercePostId } from '../coerce.js';

const POST_STATUS_CREATE = ['draft', 'pending', 'private', 'publish', 'future'] as const;
const POST_STATUS_UPDATE = ['draft', 'pending', 'private', 'publish', 'future', 'trash'] as const;

export const POST_TOOLS = [
  {
    name: 'create_post',
    description:
      'Create a new post or page. Returns ID, slug, permalink, and edit link. Provide either `content` (raw HTML or block markup) OR `blocks` (structured), not both. Status defaults to draft. Use update_post for trash transitions.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Create post' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        title: { type: 'string', description: 'Post title (required, non-empty).' },
        post_type: { type: 'string', description: 'Post type slug (default: post).' },
        status: {
          type: 'string',
          enum: [...POST_STATUS_CREATE],
          description: 'Initial status. Use update_post for trash transitions.',
        },
        content: {
          type: 'string',
          description: 'Raw post_content (HTML or block markup). Mutually exclusive with blocks.',
        },
        blocks: {
          type: 'array',
          description:
            'Structured blocks. Validated against block registry and preference tier — legacy blocks are rejected.',
          items: BLOCK_INPUT_SCHEMA,
        },
        slug: { type: 'string' },
        parent: { type: 'number', description: 'Parent post ID (hierarchical post types only).' },
        excerpt: { type: 'string' },
        featured_media: {
          type: 'number',
          description: 'Attachment ID. Must be an image MIME. Send 0 to leave unset.',
        },
        categories: {
          type: 'array',
          items: { type: 'number' },
          description: 'Term IDs in the `category` taxonomy.',
        },
        tags: {
          type: 'array',
          items: { type: 'number' },
          description: 'Term IDs in the `post_tag` taxonomy.',
        },
        terms: {
          type: 'object',
          description: 'Map of taxonomy slug → term IDs. For non-built-in taxonomies on CPTs.',
        },
        date: { type: 'string', description: 'ISO 8601 publish date.' },
        menu_order: { type: 'number' },
        comment_status: { type: 'string', enum: ['open', 'closed'] },
        ping_status: { type: 'string', enum: ['open', 'closed'] },
        author: {
          type: 'number',
          description: 'User ID. Other-user authorship requires edit_others_posts cap.',
        },
      },
      required: ['title'],
    },
  },
  {
    name: 'update_post',
    description:
      'Partial update of post metadata, status, or terms. Block content edits stay on the per-block tools. Use status: "trash" to trash; any non-trash status untrashes a trashed post. At least one mutating field besides post_id is required.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Update post' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'WordPress post ID.' },
        title: { type: 'string' },
        status: { type: 'string', enum: [...POST_STATUS_UPDATE] },
        slug: { type: 'string' },
        parent: { type: 'number' },
        excerpt: { type: 'string' },
        featured_media: {
          type: 'number',
          description: 'Attachment ID. Send 0 to clear.',
        },
        categories: { type: 'array', items: { type: 'number' } },
        tags: { type: 'array', items: { type: 'number' } },
        terms: { type: 'object' },
        date: { type: 'string' },
        menu_order: { type: 'number' },
        comment_status: { type: 'string', enum: ['open', 'closed'] },
        ping_status: { type: 'string', enum: ['open', 'closed'] },
        author: { type: 'number' },
      },
      required: ['post_id'],
    },
  },
];

export async function handlePostTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
): Promise<unknown> {
  switch (toolName) {
    case 'create_post': {
      if (typeof args.title !== 'string' || args.title.trim() === '') {
        throw new Error('create_post: a non-empty "title" is required');
      }
      if (args.content !== undefined && Array.isArray(args.blocks)) {
        throw new Error('create_post: "content" and "blocks" are mutually exclusive');
      }
      // Narrow incoming args to the documented shape. The MCP SDK already
      // validates against `inputSchema`, but `terms` (Record<string, number[]>)
      // and nested block shapes aren't enforced — narrow defensively here.
      const create = narrowCreatePost(args);
      return client.createPost(create);
    }

    case 'update_post': {
      const postId = coercePostId(args.post_id, 'update_post');
      if (postId === undefined) {
        throw new Error('update_post: "post_id" is required');
      }
      const { post_id: _omit, ...rest } = args;
      void _omit;
      if (Object.keys(rest).length === 0) {
        throw new Error('update_post: provide at least one mutating field besides post_id');
      }
      const update = narrowUpdatePost(rest);
      return client.updatePost(postId, update);
    }

    default:
      throw new Error(`Unknown post tool: ${toolName}`);
  }
}

function narrowCreatePost(input: Record<string, unknown>): CreatePostRequest {
  // input.title is already validated as a non-empty string by the caller.
  const out: CreatePostRequest = { title: input.title as string };
  if (typeof input.post_type === 'string') out.post_type = input.post_type;
  if (typeof input.status === 'string') out.status = input.status as CreatePostRequest['status'];
  if (typeof input.content === 'string') out.content = input.content;
  if (Array.isArray(input.blocks)) out.blocks = input.blocks as CreatePostRequest['blocks'];
  if (typeof input.slug === 'string') out.slug = input.slug;
  if (typeof input.parent === 'number') out.parent = input.parent;
  if (typeof input.excerpt === 'string') out.excerpt = input.excerpt;
  if (typeof input.featured_media === 'number') out.featured_media = input.featured_media;
  if (Array.isArray(input.categories)) out.categories = (input.categories as unknown[]).filter((n) => typeof n === 'number') as number[];
  if (Array.isArray(input.tags)) out.tags = (input.tags as unknown[]).filter((n) => typeof n === 'number') as number[];
  if (input.terms && typeof input.terms === 'object' && !Array.isArray(input.terms)) {
    out.terms = narrowTermsMap(input.terms as Record<string, unknown>);
  }
  if (typeof input.date === 'string') out.date = input.date;
  if (typeof input.menu_order === 'number') out.menu_order = input.menu_order;
  if (input.comment_status === 'open' || input.comment_status === 'closed') out.comment_status = input.comment_status;
  if (input.ping_status === 'open' || input.ping_status === 'closed') out.ping_status = input.ping_status;
  if (typeof input.author === 'number') out.author = input.author;
  return out;
}

function narrowUpdatePost(input: Record<string, unknown>): UpdatePostRequest {
  const out: UpdatePostRequest = {};
  if (typeof input.title === 'string') out.title = input.title;
  if (typeof input.status === 'string') out.status = input.status as UpdatePostRequest['status'];
  if (typeof input.slug === 'string') out.slug = input.slug;
  if (typeof input.parent === 'number') out.parent = input.parent;
  if (typeof input.excerpt === 'string') out.excerpt = input.excerpt;
  if (typeof input.featured_media === 'number') out.featured_media = input.featured_media;
  if (Array.isArray(input.categories)) out.categories = (input.categories as unknown[]).filter((n) => typeof n === 'number') as number[];
  if (Array.isArray(input.tags)) out.tags = (input.tags as unknown[]).filter((n) => typeof n === 'number') as number[];
  if (input.terms && typeof input.terms === 'object' && !Array.isArray(input.terms)) {
    out.terms = narrowTermsMap(input.terms as Record<string, unknown>);
  }
  if (typeof input.date === 'string') out.date = input.date;
  if (typeof input.menu_order === 'number') out.menu_order = input.menu_order;
  if (input.comment_status === 'open' || input.comment_status === 'closed') out.comment_status = input.comment_status;
  if (input.ping_status === 'open' || input.ping_status === 'closed') out.ping_status = input.ping_status;
  if (typeof input.author === 'number') out.author = input.author;
  return out;
}

function narrowTermsMap(input: Record<string, unknown>): Record<string, number[]> {
  const out: Record<string, number[]> = {};
  for (const [taxonomy, ids] of Object.entries(input)) {
    if (Array.isArray(ids)) {
      out[taxonomy] = (ids as unknown[]).filter((n) => typeof n === 'number') as number[];
    }
  }
  return out;
}
