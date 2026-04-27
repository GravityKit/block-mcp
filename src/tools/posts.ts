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

const POST_STATUS_CREATE = ['draft', 'pending', 'private', 'publish', 'future'] as const;
const POST_STATUS_UPDATE = ['draft', 'pending', 'private', 'publish', 'future', 'trash'] as const;

export const POST_TOOLS = [
  {
    name: 'create_post',
    description:
      'Create a new post or page. Returns ID, slug, permalink, and edit link. Provide either `content` (raw HTML or block markup) OR `blocks` (structured), not both. Status defaults to draft. Use update_post for trash transitions.',
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
          items: {
            type: 'object',
            properties: { name: { type: 'string' } },
            required: ['name'],
          },
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
      return client.createPost(args as unknown as CreatePostRequest);
    }

    case 'update_post': {
      if (typeof args.post_id !== 'number') {
        throw new Error('update_post: "post_id" (number) is required');
      }
      const { post_id: postId, ...rest } = args;
      if (Object.keys(rest).length === 0) {
        throw new Error('update_post: provide at least one mutating field besides post_id');
      }
      return client.updatePost(postId as number, rest as UpdatePostRequest);
    }

    default:
      throw new Error(`Unknown post tool: ${toolName}`);
  }
}
