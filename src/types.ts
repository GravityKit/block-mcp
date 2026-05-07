/**
 * TypeScript interfaces for the GravityKit Block MCP server.
 *
 * Defines all data structures exchanged between the MCP server,
 * the WordPress REST API (gk-block-api), and AI agent consumers.
 */

// ============================================
// Configuration
// ============================================

/** MCP server configuration read from environment variables. */
export interface BlockMCPConfig {
  /** WordPress site URL (e.g. "https://www.gravitykit.com") */
  wordpress_url: string;
  auth: {
    /** WordPress username for Application Password auth */
    username: string;
    /** WordPress Application Password (space-separated groups) */
    application_password: string;
  };
}

// ============================================
// Block Types
// ============================================

/** Preference metadata attached to a block type. */
export interface BlockPreference {
  /** Numeric score (0-100 typical; can be negative for legacy) */
  score: number;
  /** Human-readable tier derived from score */
  tier: 'preferred' | 'acceptable' | 'avoid' | 'legacy';
  /** Namespace-level policy label */
  namespace_policy?: string;
  /** Suggested replacement block name (e.g. "core/heading") */
  replacement?: string;
}

/** Usage statistics for a single block type. */
export interface BlockTypeUsage {
  /** Total occurrences across all published content */
  count: number;
  /** Date the block was last used (ISO date string) */
  last_used?: string;
}

/** A registered WordPress block type with preference metadata. */
export interface BlockType {
  /** Fully-qualified block name (e.g. "filter/testimonial-wall") */
  name: string;
  /** Human-readable title */
  title: string;
  /** Block category slug */
  category: string;
  /** Short description of the block's purpose */
  description?: string;
  /**
   * Block attribute definitions.
   * `source` (when present) signals the attribute reads from innerHTML at
   * edit time — strong hint a block is dual-storage.
   */
  attributes?: Record<string, { type: string; default?: unknown; source?: string }>;
  /** Preference scoring metadata */
  preference: BlockPreference;
  /** Site-wide usage statistics */
  usage?: BlockTypeUsage;
  /**
   * Storage mode — `"static"` (innerHTML is source of truth),
   * `"dynamic"` (server-rendered, attributes are source of truth),
   * `"dual"` (both must stay in sync — write enforcement applies).
   */
  storage_mode?: 'static' | 'dynamic' | 'dual';
  /** Block names this block replaces (e.g. legacy equivalents) */
  replaces?: string[];
}

// ============================================
// Patterns
// ============================================

/** Preference metadata attached to a pattern. */
export interface PatternPreference {
  /** Computed preference score */
  score: number;
  /** Human-readable tier */
  tier: 'recommended' | 'acceptable' | 'avoid' | 'legacy';
  /** Reasons contributing to the score */
  reasons: string[];
}

/** A WordPress block pattern (synced or registered). */
export interface Pattern {
  /** Post ID for synced patterns, or a generated ID for registered ones */
  id: number | string;
  /** Pattern display name */
  name: string;
  /** "synced" (wp_block post) or "registered" */
  type: 'synced' | 'registered';
  /** Creation date (ISO date string) */
  created: string;
  /** Last modification date (ISO date string) */
  modified: string;
  /** Number of pages referencing this pattern (synced only) */
  reference_count: number;
  /** Preference scoring metadata */
  preference: PatternPreference;
  /** Block names contained within this pattern */
  contains_blocks: string[];
  /** Whether the pattern contains any legacy/avoid-tier blocks */
  has_legacy_blocks: boolean;
  /** Specific legacy block names found in the pattern */
  legacy_blocks?: string[];
  /** Truncated HTML preview of the pattern content */
  preview_html?: string;
}

// ============================================
// Page Blocks
// ============================================

/** A single parsed block from a page's post_content. */
export interface Block {
  /** Zero-based position in the flat block array (counts every block including innerBlocks). Used by `update_block.flat_index`. */
  index: number;
  /**
   * Zero-based sequential position among non-empty top-level blocks only.
   * Only set on top-level blocks (omitted on inner blocks).
   * Used by `delete_block.top_level_counter`, `insert_blocks.before_top_level`/`after_top_level`, and `replace_block_range.start`.
   */
  top_level_counter?: number;
  /** Path array (raw `parse_blocks()` indices). e.g. `[0, 2, 1]` = block 0 → innerBlock 2 → innerBlock 1. Used by `edit_block_tree`. */
  path?: number[];
  /**
   * Stable identity ref (e.g. "blk_a3f2c1q9"). Persisted in attrs.metadata.gk_ref.
   * Survives sibling shifts — pass `ref` to update_block/delete_block/edit_block_tree
   * instead of `index`/`path` to chain mutations without re-fetching the page.
   */
  ref?: string;
  /** Fully-qualified block name (e.g. "core/paragraph") */
  name: string;
  /** Block attributes (key-value pairs) */
  attributes: Record<string, unknown>;
  /** Raw inner HTML content of the block */
  innerHTML?: string;
  /** Whether the registered block type uses a server-side render callback. */
  dynamic?: boolean;
  /**
   * How this block stores content.
   * - `"static"`: innerHTML is the source of truth (most core/* blocks).
   * - `"dynamic"`: attributes is the source of truth; innerHTML is regenerated on render.
   * - `"dual"`: BOTH attributes and innerHTML carry the same data — sending one without
   *   the other corrupts the block (e.g., yoast/faq-block, yoast/how-to-block).
   */
  storage_mode?: 'static' | 'dynamic' | 'dual';
  /**
   * Per-block preference info attached by the server when the block is non-preferred.
   * Driven by the (admin-editable) Preferences config — no client-side namespace
   * hardcoding. Absent when tier === 'preferred' (the common case).
   */
  preference?: {
    tier: 'preferred' | 'acceptable' | 'avoid' | 'legacy';
    suggested_replacement?: string;
  };
  /**
   * Children of container blocks (core/group, core/columns, etc.). Each
   * child has the same Block shape, including its own ref / preference /
   * innerBlocks fields, so policy walks recurse cleanly.
   */
  innerBlocks?: Block[];
}

// ============================================
// Write Responses
// ============================================

/** Warning returned when a write operation uses a non-preferred block. */
export interface PreferenceWarning {
  /** Block name that triggered the warning */
  block: string;
  /** Human-readable warning message */
  message: string;
  /** Suggested replacement block name */
  suggested_replacement?: string;
}

/** Response from block update (PATCH) operations. */
export interface BlockUpdateResponse {
  success: boolean;
  /** The updated block */
  block: {
    index: number;
    name: string;
    attributes: Record<string, unknown>;
    /** Stable gk_ref UUID (present when the block had/has a ref). Use to chain follow-up mutations. */
    ref?: string;
  };
  /** WordPress revision ID of the pre-edit snapshot */
  before_revision_id: number;
  /** WordPress revision ID of the post-edit state */
  revision_id: number;
}

/** Single entry describing a block written by `insert_blocks` / `rewrite_post_blocks`. */
export interface InsertedBlockRef {
  /** Top-level visible index — matches `index` from get_page_blocks for top-level blocks. */
  index: number;
  /** Sequential top-level position. Same as `index` for inserts (always top-level). */
  top_level_counter?: number;
  /**
   * Path array consumable by `edit_block_tree`. e.g. `[12]` for the 13th
   * raw top-level slot. Returned by `insert_blocks` so callers can chain a
   * `edit_block_tree op: insert-child` without an extra get_page_blocks lookup.
   */
  path?: number[];
  /** Stable gk_ref. Returned so callers can chain mutations against it. */
  ref?: string;
  /** Fully-qualified block name. */
  name: string;
}

/** Response from block insert (POST) and replace (PUT) operations. */
export interface BlockWriteResponse {
  success: boolean;
  /** Inserted blocks with their new indices, top-level counters, and mutation paths. */
  inserted: InsertedBlockRef[];
  /** Preference warnings for non-preferred blocks */
  warnings: PreferenceWarning[];
  /** WordPress revision ID of the pre-edit snapshot */
  before_revision_id: number;
  /** WordPress revision ID of the post-edit state */
  revision_id: number;
}

/** Response from block delete (DELETE) operations. */
export interface BlockDeleteResponse {
  success: boolean;
  /** Number of blocks removed */
  removed: number;
  /** WordPress revision ID of the pre-edit snapshot */
  before_revision_id: number;
  /** WordPress revision ID of the post-edit state */
  revision_id: number;
}

/** Response from atomic `replace_block_range` (POST /posts/{id}/blocks/replace). */
export interface BlockReplaceRangeResponse {
  success: boolean;
  /** Number of blocks removed before the new shape was inserted. */
  removed: number;
  /** New block refs (same shape as `BlockWriteResponse.inserted`). */
  inserted: InsertedBlockRef[];
  warnings: PreferenceWarning[];
  before_revision_id: number;
  revision_id: number;
}

/** Response from URL resolution (GET /resolve). */
export interface ResolveUrlResponse {
  post_id: number;
  post_type: string;
  title: string;
  status: string;
  slug: string;
  edit_url: string;
}

/** Single post stub returned by GET /find-posts. */
export interface PostStub {
  post_id: number;
  title: string;
  slug: string;
  post_type: string;
  post_status: string;
  post_url: string;
  modified: string;
}

/** Response from GET /find-posts. */
export interface FindPostsResponse {
  posts: PostStub[];
  count: number;
  total: number;
  total_pages: number;
  page: number;
  per_page: number;
}

/** Query params for GET /find-posts. */
export interface FindPostsParams {
  search?: string;
  post_type?: string;
  post_status?: string;
  per_page?: number;
  page?: number;
}

/** Response from GET /post-info. */
export interface PostInfoResponse {
  post_id: number;
  title: string;
  slug: string;
  post_type: string;
  post_status: string;
  post_url: string;
  edit_url: string;
  modified: string;
  created: string;
  parent_id: number;
  author: {
    id: number;
    display_name: string;
  };
  mime_type: string;
  comment_count: number;
}

/** Query params for GET /post-info — provide one of {post_id, url, slug+post_type}. */
export interface PostInfoParams {
  post_id?: number;
  url?: string;
  slug?: string;
  post_type?: string;
}

/** Response from pattern insertion (POST /posts/{id}/insert-pattern). */
export interface PatternInsertResponse {
  success: boolean;
  /** Inserted block(s) — single object for synced, array for inline */
  inserted: {
    index: number;
    name: string;
    attributes?: Record<string, unknown>;
    pattern_name?: string;
    synced?: boolean;
  } | Array<{ index: number; name: string }>;
  /** Pattern display name */
  pattern_name?: string;
  /** Whether inserted as synced (core/block ref) or inline */
  synced?: boolean;
  /** WordPress revision ID of the pre-edit snapshot */
  before_revision_id: number;
  /** WordPress revision ID of the post-edit state */
  revision_id: number;
}

// ============================================
// Site Usage
// ============================================

/** Block usage entry in site-wide statistics. */
export interface BlockUsageEntry {
  count: number;
  post_count: number;
}

/** Pattern reference entry in site-wide statistics. */
export interface PatternReferenceEntry {
  name: string;
  refs: number;
}

/** Legacy pattern entry in site-wide statistics. */
export interface LegacyPatternEntry {
  id: number;
  name: string;
  refs: number;
  legacy_blocks: string[];
}

/** Result of `scan_storage_modes` — block_name → "static" | "dynamic" | "dual". */
export interface StorageModeScanResult {
  scanned_posts: number;
  unique_blocks: number;
  classification: Record<string, 'static' | 'dynamic' | 'dual'>;
  dual_count: number;
  dynamic_count: number;
  static_count: number;
}

/** Site-wide block and pattern usage statistics. */
export interface SiteUsage {
  /** Per-block-type usage counts */
  block_usage: Record<string, BlockUsageEntry>;
  /** Per-namespace total block counts */
  namespace_totals: Record<string, number>;
  /** Synced pattern reference counts keyed by pattern ID */
  pattern_references: Record<string, PatternReferenceEntry>;
  /** Patterns containing legacy blocks */
  legacy_patterns: LegacyPatternEntry[];
}

// ============================================
// API Parameter Types
// ============================================

/** Parameters for the list_block_types tool. */
export interface ListBlockTypesParams {
  namespace?: string;
  category?: string;
  preferred_only?: boolean;
}

/** Parameters for the list_patterns tool. */
export interface ListPatternsParams {
  search?: string;
  synced?: boolean;
  min_score?: number;
  limit?: number;
}

/** Parameters for the insert_blocks tool. */
export interface InsertBlocksParams {
  post_id: number;
  after?: number | 'start';
  before?: number;
  /** Insert after the top-level block with this gk_ref. Takes precedence over `after`. */
  after_ref?: string;
  /** Insert before the top-level block with this gk_ref. Takes precedence over `before`. */
  before_ref?: string;
  blocks: Array<{
    name: string;
    attributes?: Record<string, unknown>;
    innerHTML?: string;
  }>;
}

/** Parameters for the insert_pattern tool. */
export interface InsertPatternParams {
  post_id: number;
  pattern_id: number | string;
  after?: number;
  before?: number;
  synced?: boolean;
}

// ============================================
// Mutation Types
// ============================================

/** Valid mutation operation names. */
export type MutationOp =
  | 'update-attrs'
  | 'update-html'
  | 'replace-block'
  | 'remove-block'
  | 'wrap-in-group'
  | 'unwrap-group'
  | 'insert-child'
  | 'duplicate'
  | 'move';

/** Request body for the mutate endpoint. Provide either `path` or `ref`. */
export interface MutationRequest {
  op: MutationOp;
  /** Integer path to the target. Provide either this or `ref`. */
  path?: number[];
  /** Stable gk_ref of the target block. Survives sibling shifts. */
  ref?: string;
  attributes?: Record<string, unknown>;
  innerHTML?: string;
  block?: {
    name: string;
    attributes?: Record<string, unknown>;
    innerHTML?: string;
    innerBlocks?: Array<{
      name: string;
      attributes?: Record<string, unknown>;
      innerHTML?: string;
    }>;
  };
  wrapper?: {
    name?: string;
    attributes?: Record<string, unknown>;
  };
  position?: number | 'start' | 'end';
  destination?: number[];
  /** Alternative to `destination` — resolve from a ref instead of a path. */
  destination_ref?: string;
  /** Alias for destination — path of block to insert BEFORE (pre-move indexing). */
  before?: number[];
  /** Alternative to `before` — resolve from a ref instead of a path. */
  before_ref?: string;
  /** Number of consecutive blocks to move/operate on. Default: 1. */
  count?: number;
}

/** Warning about static block markup staleness. */
export interface StaticBlockWarning {
  type: 'static_markup_stale_risk';
  block_name: string;
  changed_attrs: string[];
  message: string;
}

/** Response from the mutate endpoint. */
export interface MutationResponse {
  success: boolean;
  op: MutationOp;
  path: number[];
  block?: {
    name: string;
    attributes: Record<string, unknown>;
    /** Set when the op produced a new block (replace, wrap, insert-child, duplicate). */
    ref?: string;
    /** Set on `duplicate` — path of the new clone. */
    new_path?: number[];
  };
  warnings: Array<PreferenceWarning | StaticBlockWarning>;
  before_revision_id: number;
  revision_id: number;
}

// ============================================
// v1.2 — Docs Lifecycle: Posts
// ============================================

/** A block in structured form, suitable for create_post's `blocks` input. */
export interface BlockInput {
  name: string;
  attributes?: Record<string, unknown>;
  innerBlocks?: BlockInput[];
  innerHTML?: string;
  innerContent?: unknown[];
}

export type CreatePostStatus = 'draft' | 'pending' | 'private' | 'publish' | 'future';
export type UpdatePostStatus = CreatePostStatus | 'trash';
export type CommentPingStatus = 'open' | 'closed';

/** Body of POST /posts. `content` and `blocks` are mutually exclusive. */
export interface CreatePostRequest {
  title: string;
  post_type?: string;
  status?: CreatePostStatus;
  content?: string;
  blocks?: BlockInput[];
  slug?: string;
  parent?: number;
  excerpt?: string;
  /** Attachment ID. Send 0 to leave unset. Validated as image MIME. */
  featured_media?: number;
  /** Term IDs in the `category` taxonomy. */
  categories?: number[];
  /** Term IDs in the `post_tag` taxonomy. */
  tags?: number[];
  /** Map of taxonomy slug → term IDs (for non-built-in taxonomies on CPTs). */
  terms?: Record<string, number[]>;
  date?: string;
  menu_order?: number;
  comment_status?: CommentPingStatus;
  ping_status?: CommentPingStatus;
  author?: number;
}

/**
 * Body of PATCH /posts/{id}. All fields optional. Send `[]` to clear
 * `categories`/`tags`. Send `0` to clear `featured_media`. Use `status: trash`
 * to trash; any non-trash status untrashes a trashed post.
 */
export interface UpdatePostRequest {
  title?: string;
  status?: UpdatePostStatus;
  slug?: string;
  parent?: number;
  excerpt?: string;
  featured_media?: number;
  categories?: number[];
  tags?: number[];
  terms?: Record<string, number[]>;
  date?: string;
  menu_order?: number;
  comment_status?: CommentPingStatus;
  ping_status?: CommentPingStatus;
  author?: number;
}

/** Common response shape for create_post and update_post. */
export interface PostMutationResponse {
  success: boolean;
  id: number;
  post_type: string;
  status: string;
  title: string;
  slug: string;
  permalink: string;
  edit_link: string;
  before_revision_id: number | null;
  revision_id: number | null;
  /** Set on update_post when the post moves into `publish` from a non-publish status. */
  transitioned_to_publish?: boolean;
  /** Set on update_post when the post moved out of `trash`. */
  untrashed?: boolean;
  /** Avoid-tier warnings emitted by Block_CRUD when blocks are passed at create time. */
  warnings: PreferenceWarning[];
}

// ============================================
// v1.2 — Docs Lifecycle: Terms
// ============================================

export type TermOrderBy = 'name' | 'count' | 'term_id' | 'slug';
export type SortOrder = 'asc' | 'desc';

export interface ListTermsRequest {
  taxonomy?: string;
  search?: string;
  parent?: number;
  hide_empty?: boolean;
  per_page?: number;
  page?: number;
  orderby?: TermOrderBy;
  order?: SortOrder;
  include?: number[];
  slug?: string;
}

export interface Term {
  id: number;
  name: string;
  slug: string;
  description: string;
  parent: number;
  count: number;
  taxonomy: string;
  link: string;
}

export interface ListTermsResponse {
  taxonomy: string;
  total: number;
  page: number;
  per_page: number;
  terms: Term[];
}

// ============================================
// v1.2 — Docs Lifecycle: Media
// ============================================

/** Exactly one of `path`, `url`, or `data_base64` is required. */
export interface UploadMediaRequest {
  /** Local filesystem path on the MCP host. Read and POSTed as multipart. */
  path?: string;
  /** Public URL the WordPress site can fetch. Server-side sideload. */
  url?: string;
  /** Base64-encoded file contents. Requires `filename`. */
  data_base64?: string;
  /** Override filename (required when using `data_base64`). */
  filename?: string;
  title?: string;
  /** Saved as `_wp_attachment_image_alt`. Critical for accessibility. */
  alt_text?: string;
  caption?: string;
  description?: string;
  /** Attach to a parent post (sets post_parent). */
  post_id?: number;
}

export interface AttachmentSize {
  url: string;
  width: number;
  height: number;
}

export interface UploadMediaResponse {
  success: boolean;
  id: number;
  title: string;
  filename: string;
  url: string;
  source_url: string;
  mime_type: string;
  alt_text: string;
  caption?: string;
  description?: string;
  post_parent: number;
  /** Image-only fields (absent for non-image attachments). */
  width?: number;
  height?: number;
  sizes?: Record<string, AttachmentSize>;
}

// ============================================
// v1.3 — Yoast SEO Metadata
//
// Backed by Yoast_Bridge inside gk-block-api (`gk-block-api/v1/yoast/*`).
// Routes only register when Yoast SEO is active on the target site.
// ============================================

export type YoastSchemaPageType =
  | 'WebPage' | 'ItemPage' | 'AboutPage' | 'FAQPage' | 'QAPage'
  | 'ProfilePage' | 'ContactPage' | 'MedicalWebPage' | 'CollectionPage'
  | 'CheckoutPage' | 'RealEstateListing' | 'SearchResultsPage';

export type YoastSchemaArticleType =
  | 'Article' | 'BlogPosting' | 'SocialMediaPosting' | 'NewsArticle'
  | 'AdvertiserContentArticle' | 'SatiricalArticle' | 'ScholarlyArticle'
  | 'TechArticle' | 'Report' | 'None';

export type YoastRobotsAdvanced = 'noimageindex' | 'noarchive' | 'nosnippet';

/**
 * Writable Yoast SEO fields. All optional in update payloads — only the
 * fields you include get written.
 *
 * NOTE: `noindex` is intentionally tri-state — `true` = noindex, `false` =
 * explicit index, `null` = post-type default (Yoast clears the meta).
 */
export interface YoastSEOFields {
  title?: string;
  description?: string;
  canonical?: string;
  focus_keyword?: string;
  noindex?: boolean | null;
  nofollow?: boolean;
  robots_advanced?: YoastRobotsAdvanced[];
  og_title?: string;
  og_description?: string;
  og_image?: string;
  og_image_id?: number;
  twitter_title?: string;
  twitter_description?: string;
  twitter_image?: string;
  twitter_image_id?: number;
  schema_page_type?: YoastSchemaPageType;
  schema_article_type?: YoastSchemaArticleType;
  is_cornerstone?: boolean;
  breadcrumb_title?: string;
  redirect?: string;
  primary_terms?: Record<string, number>;
}

/** Full Yoast SEO metadata returned by `yoast_get_seo`. Includes read-only scores. */
export interface YoastSEOMeta extends YoastSEOFields {
  post_id: number;
  /** Yoast SEO score (0-100), null if unscored. */
  seo_score?: number | null;
  /** Readability score (0-100), null if unscored. */
  readability_score?: number | null;
  /** Inclusive language score (0-100), null if unscored. */
  inclusive_language_score?: number | null;
}

export type YoastUpdateRequest = YoastSEOFields;

/** One entry in a `yoast_bulk_update_seo` request. */
export interface YoastBulkUpdateItem extends YoastSEOFields {
  post_id: number;
}

export type YoastBulkUpdateResponse = Array<
  | { post_id: number; success: true; seo: YoastSEOMeta }
  | { post_id: number; success: false; error: string }
>;
