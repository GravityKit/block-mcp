/**
 * Typed mock for WordPressBlockClient.
 *
 * Returns a vi.fn() stub for every public client method. Tests override
 * individual methods with .mockResolvedValueOnce() as needed.
 *
 * Usage:
 *   import { makeMockClient } from '../helpers/mock-client.js';
 *   const client = makeMockClient();
 *   client.getPageBlocks.mockResolvedValueOnce(pageBlocksResponse);
 */
import { vi } from 'vitest';

/** The full shape of the mock client returned by makeMockClient(). */
export type MockClient = ReturnType<typeof makeMockClient>;

/**
 * Build a fresh mock client with all methods stubbed as vi.fn().
 * Each method returns a resolved promise with a sensible empty/success value.
 * Override per-test with .mockResolvedValueOnce().
 */
export function makeMockClient() {
  return {
    // ── Discovery ────────────────────────────────────────────────────────
    getBlockTypes: vi.fn().mockResolvedValue(
      { block_types: [] }
    ),
    getPatterns: vi.fn().mockResolvedValue(
      { patterns: [] }
    ),
    getPattern: vi.fn().mockResolvedValue({ id: 1, name: 'Test Pattern' }),
    searchPatterns: vi.fn().mockResolvedValue(
      { patterns: [] }
    ),
    getSiteUsage: vi.fn().mockResolvedValue(
      { block_usage: {}, namespace_totals: {}, pattern_references: {}, legacy_patterns: [] }
    ),
    resolveUrl: vi.fn().mockResolvedValue({
      post_id: 1,
      post_type: 'page',
      title: 'Test Page',
      status: 'publish',
      slug: 'test-page',
      edit_url: 'https://example.test/wp-admin/post.php?post=1&action=edit',
    }),
    findPosts: vi.fn().mockResolvedValue(
      { posts: [], count: 0, total: 0, total_pages: 0, page: 1, per_page: 20 }
    ),
    getPostInfo: vi.fn().mockResolvedValue({
      post_id: 1, title: 'Test', slug: 'test', post_type: 'page',
      post_status: 'publish', post_url: '', edit_url: '',
      modified: '2026-01-01', created: '2026-01-01',
      parent_id: 0, author: { id: 1, display_name: 'Admin' },
      mime_type: '', comment_count: 0,
    }),
    scanStorageModes: vi.fn().mockResolvedValue({
      scanned_posts: 0, unique_blocks: 0, classification: {},
      dual_count: 0, dynamic_count: 0, static_count: 0,
    }),
    getBindingSources: vi.fn().mockResolvedValue({ sources: [] }),

    // ── Read ─────────────────────────────────────────────────────────────
    getPageBlocks: vi.fn().mockResolvedValue(
      { blocks: [], summary: undefined }
    ),
    getBlock: vi.fn().mockResolvedValue({
      success: true,
      saved: {
        flat_index: 0, block_name: 'core/paragraph',
        attributes: {}, inner_html: '<p></p>', is_dynamic: false,
      },
    }),

    // ── Write ────────────────────────────────────────────────────────────
    updateBlock: vi.fn().mockResolvedValue({
      success: true,
      block: { index: 0, name: 'core/paragraph', attributes: {} },
      saved: {
        flat_index: 0, block_name: 'core/paragraph',
        attributes: {}, inner_html: '<p></p>', is_dynamic: false,
      },
      before_revision_id: 1,
      revision_id: 2,
    }),
    updateBlockByRef: vi.fn().mockResolvedValue({
      success: true,
      block: { index: 0, name: 'core/paragraph', attributes: {}, ref: 'blk_test0001' },
      saved: {
        flat_index: 0, block_name: 'core/paragraph',
        attributes: {}, inner_html: '<p></p>', is_dynamic: false, ref: 'blk_test0001',
      },
      before_revision_id: 1,
      revision_id: 2,
    }),
    updateBlocksBatch: vi.fn().mockResolvedValue({
      success: true, count: 0, results: [], before_revision_id: 1, revision_id: 2,
    }),
    insertBlocks: vi.fn().mockResolvedValue({
      success: true,
      inserted: [{ index: 0, name: 'core/paragraph' }],
      warnings: [],
      before_revision_id: 1,
      revision_id: 2,
    }),
    deleteBlock: vi.fn().mockResolvedValue(
      { success: true, removed: 1, before_revision_id: 1, revision_id: 2 }
    ),
    deleteBlockByRef: vi.fn().mockResolvedValue(
      { success: true, removed: 1, before_revision_id: 1, revision_id: 2 }
    ),
    replaceBlocksRange: vi.fn().mockResolvedValue({
      success: true, removed: 0,
      inserted: [{ index: 0, name: 'core/paragraph' }],
      warnings: [],
      before_revision_id: 1, revision_id: 2,
    }),
    replaceAllBlocks: vi.fn().mockResolvedValue({
      success: true,
      inserted: [],
      warnings: [],
      before_revision_id: 1,
      revision_id: 2,
    }),
    revertToRevision: vi.fn().mockResolvedValue({ success: true, revision_id: 1 }),

    // ── Mutation ─────────────────────────────────────────────────────────
    mutateBlockTree: vi.fn().mockResolvedValue({
      success: true,
      op: 'update-attrs' as const,
      path: [0],
      block: { name: 'core/paragraph', attributes: {} },
      warnings: [],
      before_revision_id: 1,
      revision_id: 2,
    }),

    // ── Pattern ──────────────────────────────────────────────────────────
    insertPattern: vi.fn().mockResolvedValue({
      success: true,
      inserted: { index: 0, name: 'core/block', attributes: { ref: 1 }, synced: true },
      pattern_name: 'Test Pattern',
      synced: true,
      before_revision_id: 1,
      revision_id: 2,
    }),
    createPattern: vi.fn().mockResolvedValue({
      pattern_id: 1, title: 'Test Pattern', slug: 'test-pattern', sync_status: 'synced',
      edit_url: 'https://example.test/wp-admin/post.php?post=1&action=edit',
      reference: { blockName: 'core/block', attrs: { ref: 1 } }, warnings: [],
    }),

    // ── Post lifecycle ────────────────────────────────────────────────────
    createPost: vi.fn().mockResolvedValue({
      success: true, id: 1, post_type: 'post', status: 'draft',
      title: 'Test Post', slug: 'test-post',
      permalink: 'https://example.test/test-post/',
      edit_link: '', before_revision_id: null, revision_id: null, warnings: [],
    }),
    updatePost: vi.fn().mockResolvedValue({
      success: true, id: 1, post_type: 'post', status: 'publish',
      title: 'Test Post', slug: 'test-post',
      permalink: 'https://example.test/test-post/',
      edit_link: '', before_revision_id: null, revision_id: null, warnings: [],
      transitioned_to_publish: true,
    }),

    // ── Terms ─────────────────────────────────────────────────────────────
    listTerms: vi.fn().mockResolvedValue(
      { taxonomy: 'category', total: 0, page: 1, per_page: 100, terms: [] }
    ),

    // ── Media ─────────────────────────────────────────────────────────────
    uploadMedia: vi.fn().mockResolvedValue({
      success: true, id: 1, title: 'test.png', filename: 'test.png',
      url: 'https://example.test/test.png',
      source_url: 'https://example.test/test.png',
      mime_type: 'image/png', alt_text: '', post_parent: 0,
    }),

    // ── Yoast ─────────────────────────────────────────────────────────────
    getYoastSEO: vi.fn().mockResolvedValue({
      post_id: 1, title: '', description: '', noindex: null,
      seo_score: null, readability_score: null, inclusive_language_score: null,
    }),
    updateYoastSEO: vi.fn().mockResolvedValue({
      post_id: 1, title: 'Updated', description: '', noindex: null,
      seo_score: 80, readability_score: 70, inclusive_language_score: null,
    }),
    bulkUpdateYoastSEO: vi.fn().mockResolvedValue([]),

    // ── Templates ────────────────────────────────────────────────────────
    getTemplates: vi.fn().mockResolvedValue({ templates: [], count: 0 }),
    getTemplate: vi.fn().mockResolvedValue({
      id: 'test-theme//index', slug: 'index', theme: 'test-theme', type: 'wp_template',
      title: 'Index', description: '', source: 'theme', origin: null, status: 'publish',
      has_theme_file: true, is_custom: true, wp_id: null,
      content: '', blocks: [],
    }),
    updateTemplate: vi.fn().mockResolvedValue({
      success: true, wp_id: 1, override_created: true,
      revert_hint: 'Call reset_template to remove this override and revert to the theme file.',
      warnings: [], before_revision_id: null, revision_id: null,
    }),
    resetTemplate: vi.fn().mockResolvedValue({ success: true, id: 'test-theme//index', wp_id: 1 }),
  };
}
