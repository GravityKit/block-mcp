/**
 * Yoast SEO tools — read/write SEO metadata for posts and pages.
 *
 * Backed by `Yoast_Bridge` inside gk-block-api itself (routes:
 * `gk-block-api/v1/yoast/{id}` and `gk-block-api/v1/yoast/bulk`). Routes
 * register only when Yoast SEO is active on the target site; if Yoast SEO
 * isn't installed, calls return a 404 `rest_no_route` from WordPress.
 *
 * Replaces the standalone `yoast-seo-mcp` MCP server (deprecated as of v1.2)
 * and the older Block-Theme mu-plugin namespace (`gravitykit/v1/yoast-seo`).
 */

import type { WordPressBlockClient } from '../client.js';
import type {
  YoastSchemaPageType,
  YoastSchemaArticleType,
  YoastRobotsAdvanced,
  YoastUpdateRequest,
  YoastBulkUpdateItem,
} from '../types.js';

const SCHEMA_PAGE_TYPES: YoastSchemaPageType[] = [
  'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage',
  'ProfilePage', 'ContactPage', 'MedicalWebPage', 'CollectionPage',
  'CheckoutPage', 'RealEstateListing', 'SearchResultsPage',
];

const SCHEMA_ARTICLE_TYPES: YoastSchemaArticleType[] = [
  'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle',
  'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle',
  'TechArticle', 'Report', 'None',
];

const ROBOTS_ADVANCED: YoastRobotsAdvanced[] = ['noimageindex', 'noarchive', 'nosnippet'];

/** Field-level schema reused by yoast_update_seo and yoast_bulk_update_seo. */
const YOAST_FIELD_PROPERTIES = {
  title: { type: 'string', description: 'SEO title (supports Yoast variables like %%title%%).' },
  description: { type: 'string', description: 'Meta description.' },
  canonical: { type: 'string', description: 'Canonical URL override.' },
  focus_keyword: { type: 'string', description: 'Focus keyphrase.' },
  noindex: {
    type: 'boolean',
    description: 'true=noindex, false=explicit index. Omit to leave the post-type default. (Schema uses a single type because the Anthropic tool-schema validator rejects a "null" member in a type array; the runtime handler still accepts an explicit null.)',
  },
  nofollow: { type: 'boolean', description: 'true=nofollow, false=follow.' },
  robots_advanced: {
    type: 'array',
    items: { type: 'string', enum: ROBOTS_ADVANCED },
    description: 'Subset of: noimageindex, noarchive, nosnippet.',
  },
  og_title: { type: 'string', description: 'Open Graph title.' },
  og_description: { type: 'string', description: 'Open Graph description.' },
  og_image: { type: 'string', description: 'Open Graph image URL.' },
  og_image_id: { type: 'number', description: 'Attachment ID for the OG image.' },
  twitter_title: { type: 'string', description: 'Twitter card title.' },
  twitter_description: { type: 'string', description: 'Twitter card description.' },
  twitter_image: { type: 'string', description: 'Twitter card image URL.' },
  twitter_image_id: { type: 'number', description: 'Attachment ID for the Twitter image.' },
  schema_page_type: { type: 'string', enum: SCHEMA_PAGE_TYPES, description: 'Schema.org page type.' },
  schema_article_type: {
    type: 'string', enum: SCHEMA_ARTICLE_TYPES, description: 'Schema.org article type.',
  },
  is_cornerstone: { type: 'boolean', description: 'Cornerstone content flag.' },
  breadcrumb_title: { type: 'string', description: 'Breadcrumb title override.' },
  redirect: { type: 'string', description: 'Redirect URL (Yoast Premium).' },
  primary_terms: {
    type: 'object',
    additionalProperties: { type: 'number' },
    description: '{ taxonomy_name: term_id } — set primary term per taxonomy.',
  },
};

export const YOAST_TOOLS = [
  {
    name: 'yoast_get_seo',
    description:
      'Read all Yoast SEO metadata for a post or page (title, description, robots, Open Graph, Twitter card, schema types, cornerstone flag, breadcrumb, redirect, scores, primary terms).',
    annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Get Yoast SEO metadata' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'WordPress post or page ID.' },
      },
      required: ['post_id'],
    },
  },
  {
    name: 'yoast_update_seo',
    description:
      'Update one or more Yoast SEO fields on a single post or page. Only supplied fields are written. `noindex` is tri-state (true / false / null).',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Update Yoast SEO metadata' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        post_id: { type: 'number', description: 'WordPress post or page ID.' },
        ...YOAST_FIELD_PROPERTIES,
      },
      required: ['post_id'],
    },
  },
  {
    name: 'yoast_bulk_update_seo',
    description:
      'Update Yoast SEO fields on multiple posts in one call. Each item must include `post_id` plus any fields to update. Response preserves order.',
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true, title: 'Bulk-update Yoast SEO metadata' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        posts: {
          type: 'array',
          description: 'Array of objects, each with post_id and fields to update.',
          items: {
            type: 'object',
            properties: {
              post_id: { type: 'number' },
              ...YOAST_FIELD_PROPERTIES,
            },
            required: ['post_id'],
          },
        },
      },
      required: ['posts'],
    },
  },
];

export async function handleYoastTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
): Promise<unknown> {
  switch (toolName) {
    case 'yoast_get_seo': {
      const postId = args.post_id;
      if (typeof postId !== 'number') {
        throw new Error('yoast_get_seo: "post_id" (number) is required');
      }
      return client.getYoastSEO(postId);
    }

    case 'yoast_update_seo': {
      const postId = args.post_id;
      if (typeof postId !== 'number') {
        throw new Error('yoast_update_seo: "post_id" (number) is required');
      }
      const { post_id: _omit, ...rest } = args;
      void _omit;
      const fields = narrowYoastFields(rest);
      if (Object.keys(fields).length === 0) {
        throw new Error('yoast_update_seo: provide at least one Yoast field besides post_id');
      }
      return client.updateYoastSEO(postId, fields);
    }

    case 'yoast_bulk_update_seo': {
      if (!Array.isArray(args.posts) || args.posts.length === 0) {
        throw new Error('yoast_bulk_update_seo: non-empty `posts` array is required');
      }
      const items: YoastBulkUpdateItem[] = [];
      for (const raw of args.posts as unknown[]) {
        if (!raw || typeof raw !== 'object') {
          throw new Error('yoast_bulk_update_seo: each item in `posts` must be an object');
        }
        const obj = raw as Record<string, unknown>;
        if (typeof obj.post_id !== 'number') {
          throw new Error('yoast_bulk_update_seo: each item requires `post_id` (number)');
        }
        const { post_id: id, ...rest } = obj;
        items.push({ post_id: id as number, ...narrowYoastFields(rest) });
      }
      return client.bulkUpdateYoastSEO(items);
    }

    default:
      throw new Error(`Unknown yoast tool: ${toolName}`);
  }
}

/**
 * Filter incoming object to known Yoast fields with the right value shapes.
 * Mirrors the JSON Schema in YOAST_FIELD_PROPERTIES.
 */
function narrowYoastFields(input: Record<string, unknown>): YoastUpdateRequest {
  const out: YoastUpdateRequest = {};
  if (typeof input.title === 'string') out.title = input.title;
  if (typeof input.description === 'string') out.description = input.description;
  if (typeof input.canonical === 'string') out.canonical = input.canonical;
  if (typeof input.focus_keyword === 'string') out.focus_keyword = input.focus_keyword;
  if (input.noindex === true || input.noindex === false || input.noindex === null) {
    out.noindex = input.noindex;
  }
  if (typeof input.nofollow === 'boolean') out.nofollow = input.nofollow;
  if (Array.isArray(input.robots_advanced)) {
    out.robots_advanced = (input.robots_advanced as unknown[]).filter(
      (v): v is YoastRobotsAdvanced => v === 'noimageindex' || v === 'noarchive' || v === 'nosnippet',
    );
  }
  if (typeof input.og_title === 'string') out.og_title = input.og_title;
  if (typeof input.og_description === 'string') out.og_description = input.og_description;
  if (typeof input.og_image === 'string') out.og_image = input.og_image;
  if (typeof input.og_image_id === 'number') out.og_image_id = input.og_image_id;
  if (typeof input.twitter_title === 'string') out.twitter_title = input.twitter_title;
  if (typeof input.twitter_description === 'string') out.twitter_description = input.twitter_description;
  if (typeof input.twitter_image === 'string') out.twitter_image = input.twitter_image;
  if (typeof input.twitter_image_id === 'number') out.twitter_image_id = input.twitter_image_id;
  if (typeof input.schema_page_type === 'string' && SCHEMA_PAGE_TYPES.includes(input.schema_page_type as YoastSchemaPageType)) {
    out.schema_page_type = input.schema_page_type as YoastSchemaPageType;
  }
  if (typeof input.schema_article_type === 'string' && SCHEMA_ARTICLE_TYPES.includes(input.schema_article_type as YoastSchemaArticleType)) {
    out.schema_article_type = input.schema_article_type as YoastSchemaArticleType;
  }
  if (typeof input.is_cornerstone === 'boolean') out.is_cornerstone = input.is_cornerstone;
  if (typeof input.breadcrumb_title === 'string') out.breadcrumb_title = input.breadcrumb_title;
  if (typeof input.redirect === 'string') out.redirect = input.redirect;
  if (input.primary_terms && typeof input.primary_terms === 'object' && !Array.isArray(input.primary_terms)) {
    const pt: Record<string, number> = {};
    for (const [k, v] of Object.entries(input.primary_terms as Record<string, unknown>)) {
      if (typeof v === 'number') pt[k] = v;
    }
    out.primary_terms = pt;
  }
  return out;
}
