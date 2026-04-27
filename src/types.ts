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
  /** Block attribute definitions */
  attributes?: Record<string, { type: string; default?: unknown }>;
  /** Preference scoring metadata */
  preference: BlockPreference;
  /** Site-wide usage statistics */
  usage?: BlockTypeUsage;
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
  /** Zero-based position in the flat block array */
  index: number;
  /** Fully-qualified block name (e.g. "core/paragraph") */
  name: string;
  /** Block attributes (key-value pairs) */
  attributes: Record<string, unknown>;
  /** Raw inner HTML content of the block */
  innerHTML?: string;
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
  };
  /** WordPress revision ID of the pre-edit snapshot */
  before_revision_id: number;
  /** WordPress revision ID of the post-edit state */
  revision_id: number;
}

/** Response from block insert (POST) and replace (PUT) operations. */
export interface BlockWriteResponse {
  success: boolean;
  /** Inserted blocks with their new indices */
  inserted: Array<{ index: number; name: string }>;
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

/** Request body for the mutate endpoint. */
export interface MutationRequest {
  op: MutationOp;
  path: number[];
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
  /** Alias for destination — path of block to insert BEFORE (pre-move indexing). */
  before?: number[];
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
