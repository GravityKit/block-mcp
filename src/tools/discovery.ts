/**
 * Discovery / Lookup Tools
 *
 * Read-only tools for exploring the registry, browsing patterns, scanning
 * inventory, and addressing posts (URL → ID, search, lookup). Always
 * `readOnlyHint: true` except `scan_storage_modes` which writes a WP option.
 */

import type { WordPressBlockClient } from '../client.js';
import { enrichBlockTypes, enrichPatternList } from '../preferences.js';

const READ_ANNOT = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true } as const;

export const DISCOVERY_TOOLS = [
  {
    name: 'list_block_types',
    description:
      'Registered block types grouped by tier (preferred / acceptable / avoid / legacy). Call before inserting unfamiliar blocks. Live policy comes from the site\'s Preferences config — see block-mcp://block-preferences.',
    annotations: { ...READ_ANNOT, title: 'List block types' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        namespace:      { type: 'string',  description: 'Filter by namespace (e.g. "core", "filter").' },
        category:       { type: 'string',  description: 'Filter by category (e.g. "text", "media").' },
        preferred_only: { type: 'boolean', description: 'If true, only blocks with score >= 50.' },
      },
    },
  },
  {
    name: 'list_patterns',
    description: 'Block patterns sorted by preference score. Check before building from scratch.',
    annotations: { ...READ_ANNOT, title: 'List patterns' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        search:    { type: 'string',  description: 'Search by name or keyword.' },
        synced:    { type: 'boolean', description: 'true = synced only, false = registered only, omit = all.' },
        min_score: { type: 'number',  description: 'Min preference score; 0 excludes legacy.' },
        limit:     { type: 'number',  description: 'Max results. Default 20.' },
      },
    },
  },
  {
    name: 'get_pattern',
    description: "Single pattern's full block content + metadata. Use after list_patterns.",
    annotations: { ...READ_ANNOT, title: 'Get pattern' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        pattern_id: { type: ['number', 'string'], description: 'Numeric post ID (synced) or registered pattern name.' },
      },
      required: ['pattern_id'],
    },
  },
  {
    name: 'get_site_usage',
    description: 'Site-wide block + pattern inventory: usage counts, namespace totals, pattern reference counts, legacy patterns.',
    annotations: { ...READ_ANNOT, title: 'Get site usage' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        refresh: { type: 'boolean', description: 'Bust the 1-hour cache and rebuild.' },
      },
    },
  },
  {
    name: 'scan_storage_modes',
    description:
      'Walk every published post and persist a `block_name → "static"|"dynamic"|"dual"` map (option `gk_block_api_storage_modes`). Slow on large sites; rate-limited to 1/hr. After this runs, get_page_blocks `storage_mode` annotations and dual-storage write enforcement use the live classification. Returns `{scanned_posts, unique_blocks, classification, dual_count, dynamic_count, static_count}`.',
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Scan storage modes' },
    inputSchema: { type: 'object' as const, properties: {} },
  },
  {
    name: 'resolve_url',
    description: 'URL or path → post ID. Accepts full URLs or site-relative paths. Run this before get_page_blocks / update_block / edit_block_tree when you only have a URL.',
    annotations: { ...READ_ANNOT, title: 'Resolve URL to post' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        url: { type: 'string', description: 'Full URL or path (e.g. "/some/page/").' },
      },
      required: ['url'],
    },
  },
  {
    name: 'list_posts',
    description: 'Search posts by title/content with pagination. Returns `{posts: [{post_id, title, slug, post_type, post_status, post_url, modified}], total, page, per_page, total_pages}`. Use instead of wp post list / wp-json.',
    annotations: { ...READ_ANNOT, title: 'List/search posts' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        search:      { type: 'string', description: 'Free-text across title + content. Omit to list.' },
        post_type:   { type: 'string', description: 'Single or comma-separated. Default: public types.' },
        post_status: { type: 'string', description: 'publish | draft | private | any | csv. Default: publish. (`any` is exclusive.)' },
        per_page:    { type: 'number', description: 'Default 20, max 100.' },
        page:        { type: 'number', description: 'Default 1.' },
      },
    },
  },
  {
    name: 'get_post_info',
    description: 'Post metadata by post_id, url, or slug+post_type. Returns `{post_id, title, status, post_url, edit_url, modified, created, parent_id, author, mime_type, comment_count}`. Replaces wp eval / get_permalink() shell-outs.',
    annotations: { ...READ_ANNOT, title: 'Get post info' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id:   { type: 'number', description: 'One of post_id, url, or slug.' },
        url:       { type: 'string', description: 'Full URL or path. Resolved via url_to_postid.' },
        slug:      { type: 'string', description: 'post_name. Combine with post_type for uniqueness.' },
        post_type: { type: 'string', description: 'Scope a slug lookup. Default: any.' },
      },
    },
  },
];

export async function handleDiscoveryTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'list_block_types': {
      const response = await client.getBlockTypes({
        namespace: args.namespace as string | undefined,
        category: args.category as string | undefined,
        preferred: args.preferred_only as boolean | undefined,
      });
      const enriched = enrichBlockTypes(response.block_types);
      return { block_types: enriched.block_types, count: enriched.block_types.length, guidance: enriched.guidance };
    }

    case 'list_patterns': {
      const response = await client.getPatterns({
        q: args.search as string | undefined,
        synced: args.synced as boolean | undefined,
        min_score: args.min_score as number | undefined,
        limit: args.limit as number | undefined,
      });
      const enriched = enrichPatternList(response.patterns);
      return { patterns: enriched.patterns, count: enriched.patterns.length, summary: enriched.summary };
    }

    case 'get_pattern': {
      const patternId = args.pattern_id;
      if (patternId === undefined || patternId === null) throw new Error('pattern_id is required');
      return await client.getPattern(patternId as number | string);
    }

    case 'get_site_usage':
      return await client.getSiteUsage(args.refresh as boolean | undefined);

    case 'scan_storage_modes':
      return await client.scanStorageModes();

    case 'resolve_url': {
      const url = args.url;
      if (typeof url !== 'string' || url.length === 0) throw new Error('url is required');
      return await client.resolveUrl(url);
    }

    case 'list_posts':
      return await client.findPosts({
        search:      args.search as string | undefined,
        post_type:   args.post_type as string | undefined,
        post_status: args.post_status as string | undefined,
        per_page:    args.per_page as number | undefined,
        page:        args.page as number | undefined,
      });

    case 'get_post_info': {
      const postId = args.post_id;
      const url    = args.url;
      const slug   = args.slug;
      if (
        (postId === undefined || postId === null) &&
        (typeof url !== 'string' || url.length === 0) &&
        (typeof slug !== 'string' || slug.length === 0)
      ) {
        throw new Error('get_post_info requires one of: post_id, url, or slug');
      }
      return await client.getPostInfo({
        post_id:   typeof postId === 'number' ? postId : undefined,
        url:       typeof url === 'string' ? url : undefined,
        slug:      typeof slug === 'string' ? slug : undefined,
        post_type: args.post_type as string | undefined,
      });
    }

    default:
      throw new Error(`Unknown discovery tool: ${toolName}`);
  }
}
