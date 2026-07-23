/**
 * Discovery / Lookup Tools
 *
 * Read-only tools for exploring the registry, browsing patterns, scanning
 * inventory, and addressing posts (URL → ID, search, lookup). Always
 * `readOnlyHint: true` except `scan_storage_modes` which writes a WP option.
 */

import type { WordPressBlockClient } from '../client.js';
import { enrichBlockTypes, enrichPatternList } from '../preferences.js';
import { coercePostId } from '../coerce.js';

const READ_ANNOT = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true } as const;

export const DISCOVERY_TOOLS = [
  {
    name: 'list_block_types',
    description:
      'Registered block types with per-block `preference` (tier + replacement), `storage_mode` ("static"|"dynamic"|"dual"), `usage` (count + post_count), `attributes` (incl. `source` declarations), and a top-level `guidance` summary grouped by tier. `styles` lists valid is-style-* variations — check it before setting an is-style-* className; `parent`/`ancestor`/`allowed_blocks` are nesting constraints — respect them when nesting. Pass `include_supports:true` for the full supports object. Filters: namespace, category, tier, storage_mode, search (name/title substring), preferred_only, usage_only. Pagination: limit/offset → next_offset. Returns `{block_types[], count, total, offset, next_offset, guidance}`.',
    annotations: { ...READ_ANNOT, title: 'List block types' },
    outputSchema: {
      type: 'object',
      properties: {
        block_types: { type: 'array' },
        count:       { type: 'number' },
        total:       { type: 'number' },
        offset:      { type: 'number' },
        next_offset: { type: ['number', 'null'] },
        guidance:    { type: 'string' },
      },
    },
    inputSchema: {
      type: 'object' as const,
      properties: {
        namespace:      { type: 'string',  description: 'Filter by namespace (e.g. "core", "filter").' },
        category:       { type: 'string',  description: 'Filter by category (e.g. "text", "media").' },
        tier:           { type: 'string',  enum: ['preferred', 'acceptable', 'avoid', 'legacy'], description: 'Exact tier match. Use for migration audits.' },
        storage_mode:   { type: 'string',  enum: ['static', 'dynamic', 'dual'], description: 'Filter by storage mode. "dual" surfaces blocks needing both attrs+innerHTML on update.' },
        search:         { type: 'string',  description: 'Case-insensitive substring match against name + title.' },
        preferred_only:   { type: 'boolean', description: 'Shorthand for `tier in {preferred,acceptable}` (score ≥ 50).' },
        usage_only:       { type: 'boolean', description: 'Only blocks with usage.count > 0 on this site.' },
        include_supports: { type: 'boolean', description: 'Include each block\'s full `supports` object. Default false.' },
        limit:            { type: 'number',  description: 'Max results. Default 50.' },
        offset:           { type: 'number',  description: 'Skip this many. Default 0.' },
      },
    },
  },
  {
    name: 'list_patterns',
    description: 'Block patterns sorted by preference score. Check before building from scratch. Server respects `limit`; `offset` slices client-side. Reference counts are cached for 1 hour — pass `refresh:true` to rebuild. `category` filters differently by pattern kind: registered patterns are matched against their declared pattern categories, while synced patterns (no separate category taxonomy) are matched against the block categories actually used in their content. The response\'s top-level `categories` lists the registered pattern-category vocabulary.',
    annotations: { ...READ_ANNOT, title: 'List patterns' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        search:    { type: 'string',  description: 'Search by name or keyword.' },
        synced:    { type: 'boolean', description: 'true = synced only, false = registered only, omit = all.' },
        category:  { type: 'string',  description: 'Filter by pattern category. Registered patterns match declared categories; synced patterns match block categories used in their content.' },
        min_score: { type: 'number',  description: 'Min preference score; 0 excludes legacy.' },
        limit:     { type: 'number',  description: 'Max results. Default 20.' },
        offset:    { type: 'number',  description: 'Skip this many results. Default 0.' },
        refresh:   { type: 'boolean', description: 'Bust the 1-hour reference-count cache before listing.' },
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
    name: 'list_binding_sources',
    description: 'Registered block bindings sources — what a block\'s `metadata.bindings` attribute can reference to pull an attribute value dynamically (e.g. `core/post-meta`, `core/pattern-overrides`). Returns `{sources: [{name, label, uses_context?}], note?}`; `note` is present with `sources` empty on WordPress below 6.5.',
    annotations: { ...READ_ANNOT, title: 'List block bindings sources' },
    inputSchema: { type: 'object' as const, properties: {} },
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
      if (args.include_supports !== undefined && typeof args.include_supports !== 'boolean') {
        throw new Error(
          `list_block_types: "include_supports" must be a boolean, got ${JSON.stringify(args.include_supports)}.`,
        );
      }
      const response = await client.getBlockTypes({
        namespace:        args.namespace as string | undefined,
        category:         args.category as string | undefined,
        preferred_only:   args.preferred_only as boolean | undefined,
        tier:             args.tier as 'preferred' | 'acceptable' | 'avoid' | 'legacy' | undefined,
        storage_mode:     args.storage_mode as 'static' | 'dynamic' | 'dual' | undefined,
        search:           args.search as string | undefined,
        usage_only:       args.usage_only as boolean | undefined,
        include_supports: args.include_supports as boolean | undefined,
      });
      const enriched = enrichBlockTypes(response.block_types);
      const total  = enriched.block_types.length;
      const limit  = (args.limit as number | undefined) ?? 50;
      const offset = (args.offset as number | undefined) ?? 0;
      const page   = enriched.block_types.slice(offset, offset + limit);
      return {
        block_types: page,
        count: page.length,
        total,
        offset,
        next_offset: offset + page.length < total ? offset + page.length : null,
        guidance: enriched.guidance,
      };
    }

    case 'list_patterns': {
      const limit  = (args.limit as number | undefined) ?? 20;
      const offset = (args.offset as number | undefined) ?? 0;
      // Fetch the full set (no server-side limit) and paginate locally, matching
      // list_block_types. Passing limit: offset+limit truncated `total` to the
      // fetched window and made next_offset always null on a full page — the
      // agent then never saw that more patterns existed.
      const response = await client.getPatterns({
        q: args.search as string | undefined,
        synced: args.synced as boolean | undefined,
        category: args.category as string | undefined,
        min_score: args.min_score as number | undefined,
        refresh: args.refresh as boolean | undefined,
      });
      const enriched = enrichPatternList(response.patterns);
      const total = enriched.patterns.length;
      const page  = enriched.patterns.slice(offset, offset + limit);
      return {
        patterns: page,
        count: page.length,
        total,
        offset,
        next_offset: offset + page.length < total ? offset + page.length : null,
        summary: enriched.summary,
        categories: response.categories,
      };
    }

    case 'get_pattern': {
      const patternId = args.pattern_id;
      if (patternId === undefined || patternId === null) throw new Error('pattern_id is required');
      return await client.getPattern(patternId as number | string);
    }

    case 'get_site_usage':
      return await client.getSiteUsage(args.refresh as boolean | undefined);

    case 'list_binding_sources':
      return await client.getBindingSources();

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
      // Coerce well-formed integer strings (some MCP clients and untyped JSON
      // send numeric IDs as strings); undefined is allowed here because url/slug
      // are alternate selectors. Shared with update_post / yoast_* so the whole
      // tool surface accepts a post_id identically.
      const normalizedPostId = coercePostId(postId, 'get_post_info');
      return await client.getPostInfo({
        post_id:   normalizedPostId,
        url:       typeof url === 'string' ? url : undefined,
        slug:      typeof slug === 'string' ? slug : undefined,
        post_type: args.post_type as string | undefined,
      });
    }

    default:
      throw new Error(`Unknown discovery tool: ${toolName}`);
  }
}
