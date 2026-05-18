/**
 * Canonical REST response envelopes.
 *
 * Plain data — shapes match the TypeScript interfaces in src/types.ts.
 * Use these as mock return values in tool-layer and client-layer tests.
 */

import {
  savedParagraph,
  savedHeading,
  mixedPageBlocks,
} from './block-trees.js';

// ---------------------------------------------------------------------------
// Discovery responses
// ---------------------------------------------------------------------------

export const blockTypesResponse = {
  block_types: [
    {
      name: 'core/paragraph',
      title: 'Paragraph',
      category: 'text',
      description: 'Start with the basic building block.',
      preference: { score: 90, tier: 'preferred' as const },
      storage_mode: 'static' as const,
    },
    {
      name: 'core/heading',
      title: 'Heading',
      category: 'text',
      preference: { score: 90, tier: 'preferred' as const },
      storage_mode: 'static' as const,
    },
    {
      name: 'stackable/heading',
      title: 'Stackable Heading',
      category: 'text',
      preference: { score: 10, tier: 'avoid' as const, namespace_policy: 'migrate_away' },
    },
    {
      name: 'ugb/text',
      title: 'UGB Text',
      category: 'text',
      preference: { score: 0, tier: 'legacy' as const, namespace_policy: 'never_use' },
    },
  ],
};

export const patternsResponse = {
  patterns: [
    {
      id: 1,
      name: 'Hero Section',
      type: 'synced' as const,
      created: '2026-01-01',
      modified: '2026-04-15',
      reference_count: 12,
      preference: { score: 85, tier: 'recommended' as const, reasons: ['recent', 'widely-used'] },
      contains_blocks: ['core/heading', 'core/paragraph', 'core/image'],
      has_legacy_blocks: false,
    },
    {
      id: 2,
      name: 'Old Legacy Pattern',
      type: 'synced' as const,
      created: '2023-01-01',
      modified: '2023-06-01',
      reference_count: 2,
      preference: { score: -80, tier: 'legacy' as const, reasons: ['has_legacy_blocks'] },
      contains_blocks: ['ugb/text', 'ugb/heading'],
      has_legacy_blocks: true,
      legacy_blocks: ['ugb/text', 'ugb/heading'],
    },
  ],
};

export const siteUsageResponse = {
  block_usage: {
    'core/paragraph': { count: 450, post_count: 120 },
    'core/heading': { count: 200, post_count: 95 },
    'stackable/heading': { count: 30, post_count: 15 },
  },
  namespace_totals: { core: 650, stackable: 30 },
  pattern_references: {
    '1': { name: 'Hero Section', refs: 12 },
  },
  legacy_patterns: [],
};

export const resolveUrlResponse = {
  post_id: 532208,
  post_type: 'download',
  title: 'GravityEdit',
  status: 'publish',
  slug: 'gravityedit',
  edit_url: 'https://example.test/wp-admin/post.php?post=532208&action=edit',
};

// ---------------------------------------------------------------------------
// Read responses
// ---------------------------------------------------------------------------

export const pageBlocksResponse = {
  blocks: mixedPageBlocks,
  summary: {
    total_blocks: 4,
    top_level_blocks: 4,
    block_types: { 'core/paragraph': 1, 'core/heading': 1, 'core/image': 1, 'core/group': 1 },
    sections: [],
    headings: [{ path: [1], level: 2, text: 'Section Title' }],
    legacy_blocks: [],
    max_path_depth: 2,
  },
};

export const getBlockResponse = {
  success: true,
  saved: savedParagraph,
};

// ---------------------------------------------------------------------------
// Write responses
// ---------------------------------------------------------------------------

export const updateBlockResponse = {
  success: true,
  block: {
    index: 1,
    name: 'core/heading',
    attributes: { level: 3, content: 'Updated' },
    ref: 'blk_head0001',
  },
  saved: savedHeading,
  before_revision_id: 100,
  revision_id: 101,
};

export const insertBlocksResponse = {
  success: true,
  inserted: [
    { index: 5, top_level_counter: 5, path: [5], ref: 'blk_new00001', name: 'core/paragraph' },
  ],
  warnings: [],
  before_revision_id: 100,
  revision_id: 101,
};

export const insertBlocksWithWarningsResponse = {
  success: true,
  inserted: [{ index: 5, name: 'stackable/heading' }],
  warnings: [
    { block: 'stackable/heading', message: 'Block 5: stackable/heading (AVOID)', suggested_replacement: 'core/heading' },
  ],
  before_revision_id: 100,
  revision_id: 101,
};

export const deleteBlockResponse = {
  success: true,
  removed: 1,
  before_revision_id: 100,
  revision_id: 101,
};

export const batchUpdateResponse = {
  success: true,
  count: 2,
  results: [
    {
      batch_index: 0,
      block: { index: 0, name: 'core/paragraph', attributes: { content: 'Updated 1' }, ref: 'blk_para0001' },
    },
    {
      batch_index: 1,
      block: { index: 1, name: 'core/heading', attributes: { level: 3 }, ref: 'blk_head0001' },
    },
  ],
  before_revision_id: 100,
  revision_id: 101,
};

export const replaceRangeResponse = {
  success: true,
  removed: 2,
  inserted: [
    { index: 1, name: 'core/paragraph', ref: 'blk_rpl00001' },
  ],
  warnings: [],
  before_revision_id: 100,
  revision_id: 101,
};

// ---------------------------------------------------------------------------
// Mutation responses
// ---------------------------------------------------------------------------

export const mutationUpdateAttrsResponse = {
  success: true,
  op: 'update-attrs' as const,
  path: [1],
  block: {
    name: 'core/heading',
    attributes: { level: 3 },
  },
  warnings: [],
  before_revision_id: 100,
  revision_id: 101,
};

export const mutationWithStaticWarning = {
  success: true,
  op: 'update-attrs' as const,
  path: [0],
  block: { name: 'core/paragraph', attributes: { content: 'New' } },
  warnings: [
    {
      type: 'static_markup_stale_risk' as const,
      block_name: 'core/paragraph',
      changed_attrs: ['content'],
      message: 'Updating content on a static block without new innerHTML may leave markup stale.',
    },
  ],
  before_revision_id: 100,
  revision_id: 101,
};

// ---------------------------------------------------------------------------
// Pattern responses
// ---------------------------------------------------------------------------

export const patternInsertResponse = {
  success: true,
  inserted: { index: 5, name: 'core/block', attributes: { ref: 1 }, synced: true },
  pattern_name: 'Hero Section',
  synced: true,
  before_revision_id: 100,
  revision_id: 101,
};

// ---------------------------------------------------------------------------
// Post lifecycle responses
// ---------------------------------------------------------------------------

export const createPostResponse = {
  success: true,
  id: 9999,
  post_type: 'post',
  status: 'draft',
  title: 'New Post',
  slug: 'new-post',
  permalink: 'https://example.test/new-post/',
  edit_link: 'https://example.test/wp-admin/post.php?post=9999&action=edit',
  before_revision_id: null,
  revision_id: null,
  warnings: [],
};

export const updatePostResponse = {
  ...createPostResponse,
  status: 'publish',
  transitioned_to_publish: true,
};

// ---------------------------------------------------------------------------
// Yoast responses
// ---------------------------------------------------------------------------

export const yoastSEOResponse = {
  post_id: 9999,
  title: 'SEO Title',
  description: 'Meta description.',
  canonical: '',
  focus_keyword: 'gravitykit',
  noindex: null,
  nofollow: false,
  seo_score: 78,
  readability_score: 65,
  inclusive_language_score: null,
};
