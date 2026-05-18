/**
 * Canonical block tree fixtures.
 *
 * Plain data objects only — no logic, no imports from src.
 * Shape matches the Block interface from src/types.ts.
 * Import these in any test that needs realistic block data.
 */

// ---------------------------------------------------------------------------
// Leaf block types
// ---------------------------------------------------------------------------

export const paragraphBlock = {
  index: 0,
  top_level_counter: 0,
  path: [0],
  ref: 'blk_para0001',
  name: 'core/paragraph',
  attributes: { content: 'Hello world.' },
  innerHTML: '<p>Hello world.</p>',
  storage_mode: 'static' as const,
};

export const headingBlock = {
  index: 1,
  top_level_counter: 1,
  path: [1],
  ref: 'blk_head0001',
  name: 'core/heading',
  attributes: { level: 2, content: 'Section Title' },
  innerHTML: '<h2 class="wp-block-heading">Section Title</h2>',
  storage_mode: 'static' as const,
};

export const imageBlock = {
  index: 2,
  top_level_counter: 2,
  path: [2],
  ref: 'blk_img00001',
  name: 'core/image',
  attributes: { url: 'https://example.test/wp-content/uploads/photo.jpg', alt: 'A photo' },
  innerHTML: '<figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/photo.jpg" alt="A photo"/></figure>',
  storage_mode: 'static' as const,
};

export const listBlock = {
  index: 3,
  top_level_counter: 3,
  path: [3],
  ref: 'blk_list0001',
  name: 'core/list',
  attributes: { ordered: false },
  innerHTML: '<ul><li>Item A</li><li>Item B</li></ul>',
  storage_mode: 'static' as const,
};

// ---------------------------------------------------------------------------
// Dynamic block (server-rendered)
// ---------------------------------------------------------------------------

export const queryLoopBlock = {
  index: 4,
  top_level_counter: 4,
  path: [4],
  ref: 'blk_qlop0001',
  name: 'core/query',
  attributes: { queryId: 1, query: { perPage: 10, postType: 'post' } },
  innerHTML: '',
  dynamic: true,
  storage_mode: 'dynamic' as const,
};

// ---------------------------------------------------------------------------
// Legacy / non-preferred blocks (server attaches preference field)
// ---------------------------------------------------------------------------

export const legacyHeadingBlock = {
  index: 5,
  top_level_counter: 5,
  path: [5],
  name: 'ugb/heading',
  attributes: { text: 'Old Heading' },
  innerHTML: '<div class="ugb-heading"><h2>Old Heading</h2></div>',
  preference: {
    tier: 'legacy' as const,
    suggested_replacement: 'core/heading',
  },
};

export const avoidBlock = {
  index: 6,
  top_level_counter: 6,
  path: [6],
  name: 'stackable/heading',
  attributes: { text: 'Stack Heading' },
  preference: {
    tier: 'avoid' as const,
    suggested_replacement: 'core/heading',
  },
};

// ---------------------------------------------------------------------------
// Container blocks (with innerBlocks)
// ---------------------------------------------------------------------------

export const groupBlock = {
  index: 7,
  top_level_counter: 7,
  path: [7],
  ref: 'blk_grp00001',
  name: 'core/group',
  attributes: { tagName: 'section' },
  innerHTML: '<section class="wp-block-group"></section>',
  storage_mode: 'static' as const,
  innerBlocks: [
    {
      index: 8,
      path: [7, 0],
      ref: 'blk_grpch001',
      name: 'core/heading',
      attributes: { level: 3, content: 'Inside Group' },
      innerHTML: '<h3>Inside Group</h3>',
    },
    {
      index: 9,
      path: [7, 1],
      ref: 'blk_grpch002',
      name: 'core/paragraph',
      attributes: { content: 'Group paragraph.' },
      innerHTML: '<p>Group paragraph.</p>',
    },
  ],
};

// ---------------------------------------------------------------------------
// Dual-storage block (yoast/faq-block example)
// ---------------------------------------------------------------------------

export const dualStorageBlock = {
  index: 10,
  top_level_counter: 8,
  path: [10],
  ref: 'blk_dual0001',
  name: 'yoast/faq-block',
  attributes: {
    questions: [
      { id: 'faq-q1', question: ['What is GravityKit?'], answer: ['A plugin suite.'] },
    ],
  },
  innerHTML: '<div class="schema-faq"><div class="schema-faq-section"><strong>What is GravityKit?</strong><p>A plugin suite.</p></div></div>',
  storage_mode: 'dual' as const,
};

// ---------------------------------------------------------------------------
// Flat page: a realistic mix for get_page_blocks responses
// ---------------------------------------------------------------------------

export const mixedPageBlocks = [
  paragraphBlock,
  headingBlock,
  imageBlock,
  groupBlock,
];

export const legacyPageBlocks = [
  paragraphBlock,
  legacyHeadingBlock,
  avoidBlock,
];

// ---------------------------------------------------------------------------
// SavedBlock snapshots (echoed by write operations)
// ---------------------------------------------------------------------------

export const savedParagraph = {
  flat_index: 0,
  block_name: 'core/paragraph',
  attributes: { content: 'Hello world.' },
  inner_html: '<p>Hello world.</p>',
  is_dynamic: false,
  ref: 'blk_para0001',
};

export const savedHeading = {
  flat_index: 1,
  block_name: 'core/heading',
  attributes: { level: 2, content: 'Section Title' },
  inner_html: '<h2 class="wp-block-heading">Section Title</h2>',
  is_dynamic: false,
  ref: 'blk_head0001',
};

export const savedDynamic = {
  flat_index: 4,
  block_name: 'core/query',
  attributes: { queryId: 1 },
  inner_html: '',
  is_dynamic: true,
};
