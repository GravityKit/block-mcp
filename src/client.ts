/**
 * WordPress Block API Client
 *
 * HTTP client for the gk-block-api WordPress REST plugin.
 * Handles authentication via Application Passwords and provides
 * typed methods for every REST endpoint.
 */

import axios, { AxiosInstance, AxiosError } from 'axios';
import type {
  BlockMCPConfig,
  BlockType,
  Pattern,
  Block,
  SiteUsage,
  BlockUpdateResponse,
  BlockWriteResponse,
  BlockDeleteResponse,
  BlockReplaceRangeResponse,
  StorageModeScanResult,
  PatternInsertResponse,
  MutationRequest,
  MutationResponse,
  ResolveUrlResponse,
  CreatePostRequest,
  UpdatePostRequest,
  PostMutationResponse,
  ListTermsRequest,
  ListTermsResponse,
  UploadMediaRequest,
  UploadMediaResponse,
  YoastSEOMeta,
  YoastUpdateRequest,
  YoastBulkUpdateItem,
  YoastBulkUpdateResponse,
} from './types.js';

/** Response wrapper for block type listing. */
interface BlockTypesResponse {
  block_types: BlockType[];
}

/** Response wrapper for pattern listing. */
interface PatternsResponse {
  patterns: Pattern[];
}

/** Response wrapper for page blocks. */
interface PageBlocksResponse {
  blocks: Block[];
}

/**
 * WordPress Block API client.
 *
 * Wraps the gk-block-api/v1 REST endpoints with typed methods,
 * Basic Auth via Application Passwords, and meaningful error handling.
 */
export class WordPressBlockClient {
  private client: AxiosInstance;
  /**
   * Sibling axios instance pointed at `gravitykit/v1` for Yoast SEO meta.
   * Same auth as the main client; different REST namespace because Yoast
   * endpoints live in the Block-Theme mu-plugin, not the gk-block-api plugin.
   */
  private yoastClient: AxiosInstance;

  /**
   * Create a new WordPress Block API client.
   *
   * @param config - MCP server configuration with URL and credentials
   */
  constructor(config: BlockMCPConfig) {
    const { wordpress_url, auth } = config;

    if (!wordpress_url) {
      throw new Error('WordPress site URL is required (GK_SITE_URL)');
    }
    if (!auth) {
      throw new Error('WordPress authentication credentials are required (GK_BLOCK_API_USER, GK_BLOCK_API_APP_PASSWORD)');
    }
    if (!auth.username) {
      throw new Error('WordPress API username is required (GK_BLOCK_API_USER)');
    }
    if (!auth.application_password) {
      throw new Error('WordPress Application Password is required (GK_BLOCK_API_APP_PASSWORD)');
    }

    // Build base64-encoded Basic Auth header
    const credentials = Buffer.from(
      `${auth.username}:${auth.application_password}`
    ).toString('base64');

    const trimmed = wordpress_url.replace(/\/+$/, '');
    const baseURL = `${trimmed}/wp-json/gk-block-api/v1`;
    const yoastBaseURL = `${trimmed}/wp-json/gravitykit/v1`;

    const sharedHeaders = {
      Authorization: `Basic ${credentials}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'User-Agent': 'MonoKit Block MCP Server (https://github.com/GravityKit)',
    };

    this.client = axios.create({
      baseURL,
      headers: sharedHeaders,
      timeout: 30000,
    });

    this.yoastClient = axios.create({
      baseURL: yoastBaseURL,
      headers: sharedHeaders,
      timeout: 30000,
    });

    // Response interceptor for consistent error formatting (applied to both clients).
    const errorInterceptor = (error: AxiosError) => {
      throw new Error(this.formatError(error));
    };
    this.yoastClient.interceptors.response.use((r) => r, errorInterceptor);
    this.client.interceptors.response.use(
      (response) => response,
      (error: AxiosError) => {
        throw new Error(this.formatError(error));
      }
    );
  }

  /**
   * Format an Axios error into a human-readable message.
   *
   * @param error - The Axios error to format
   * @returns Formatted error string
   */
  private formatError(error: AxiosError): string {
    if (error.response) {
      const { status, data } = error.response;
      const body = data as Record<string, unknown> | string | undefined;

      let detail = 'Unknown error';
      if (body && typeof body === 'object' && 'message' in body) {
        detail = String(body.message);
      } else if (body && typeof body === 'object' && 'error' in body) {
        detail = String(body.error);
      } else if (typeof body === 'string') {
        detail = body;
      }

      return `Block API Error (${status}): ${detail}`;
    }

    if (error.code === 'ECONNREFUSED') {
      return 'Block API Error: Connection refused. Is the WordPress site reachable?';
    }

    if (error.code === 'ETIMEDOUT') {
      return 'Block API Error: Request timed out after 30 seconds.';
    }

    return `Block API Error: ${error.message}`;
  }

  // ============================================
  // Registry & Discovery
  // ============================================

  /**
   * Get all registered block types with preference metadata.
   *
   * @param params - Optional filters (namespace, category, preferred)
   * @returns Array of block types
   */
  async getBlockTypes(params?: {
    namespace?: string;
    category?: string;
    preferred?: boolean;
  }): Promise<BlockTypesResponse> {
    const queryParams: Record<string, string> = {};
    if (params?.namespace) queryParams.namespace = params.namespace;
    if (params?.category) queryParams.category = params.category;
    if (params?.preferred) queryParams.preferred = 'true';

    const response = await this.client.get<BlockTypesResponse>('/block-types', {
      params: queryParams,
    });
    return response.data;
  }

  /**
   * Get block types filtered by namespace.
   *
   * @param namespace - Block namespace (e.g. "filter", "core", "stackable")
   * @returns Array of block types in the namespace
   */
  async getBlockTypesByNamespace(namespace: string): Promise<BlockTypesResponse> {
    if (!namespace) {
      throw new Error('Namespace is required');
    }

    const response = await this.client.get<BlockTypesResponse>(
      `/block-types/${encodeURIComponent(namespace)}`
    );
    return response.data;
  }

  /**
   * Get all patterns with preference scoring.
   *
   * @param params - Optional filters and pagination
   * @returns Array of patterns
   */
  async getPatterns(params?: {
    q?: string;
    synced?: boolean;
    min_score?: number;
    category?: string;
    limit?: number;
    order_by?: string;
  }): Promise<PatternsResponse> {
    const queryParams: Record<string, string> = {};
    if (params?.q) queryParams.q = params.q;
    if (params?.synced !== undefined) queryParams.synced = String(params.synced);
    if (params?.min_score !== undefined) queryParams.min_score = String(params.min_score);
    if (params?.category) queryParams.category = params.category;
    if (params?.limit !== undefined) queryParams.limit = String(params.limit);
    if (params?.order_by) queryParams.order_by = params.order_by;

    const response = await this.client.get<PatternsResponse>('/patterns', {
      params: queryParams,
    });
    return response.data;
  }

  /**
   * Get a single pattern by ID with its full parsed block content.
   *
   * @param id - Pattern ID (post ID for synced, name for registered)
   * @returns Pattern details
   */
  async getPattern(id: number | string): Promise<Pattern> {
    const response = await this.client.get<Pattern>(
      `/patterns/${encodeURIComponent(String(id))}`
    );
    return response.data;
  }

  /**
   * Search patterns by name or keyword.
   *
   * @param query - Search term
   * @returns Matching patterns
   */
  async searchPatterns(query: string): Promise<PatternsResponse> {
    if (!query) {
      throw new Error('Search query is required');
    }

    const response = await this.client.get<PatternsResponse>('/patterns/search', {
      params: { q: query },
    });
    return response.data;
  }

  /**
   * Resolve a URL or path to a WordPress post ID.
   *
   * Accepts any URL on the site (full URL or path). Handles all post types,
   * permalinks, and pretty URLs via url_to_postid().
   *
   * @param url - Full URL or path (e.g. "/products/gravityedit/")
   * @returns Post ID, type, title, status, slug, and edit URL
   */
  async resolveUrl(url: string): Promise<ResolveUrlResponse> {
    if (!url) {
      throw new Error('URL is required');
    }

    const response = await this.client.get<ResolveUrlResponse>('/resolve', {
      params: { url },
    });
    return response.data;
  }

  /**
   * Scan all published content and classify every distinct block name as
   * static / dynamic / dual. Persists results to a WP option so subsequent
   * `get_page_blocks` annotations and dual-storage enforcement use the
   * live classification instead of the filter defaults.
   *
   * Slow (walks every published post). Run once after install or when
   * significantly changing the site's block-using content.
   */
  async scanStorageModes(): Promise<StorageModeScanResult> {
    const response = await this.client.post<StorageModeScanResult>('/storage-modes/scan', {});
    return response.data;
  }

  /**
   * Get site-wide block and pattern usage statistics.
   *
   * @param refresh - If true, bust the transient cache and regenerate stats
   * @returns Usage statistics
   */
  async getSiteUsage(refresh?: boolean): Promise<SiteUsage> {
    const params: Record<string, string> = {};
    if (refresh) params.refresh = 'true';

    const response = await this.client.get<SiteUsage>('/site-usage', { params });
    return response.data;
  }

  // ============================================
  // Page Block CRUD
  // ============================================

  /**
   * Get all blocks on a page as structured JSON.
   *
   * @param postId - WordPress post/page ID
   * @param params - Optional query parameters (fields, render, search, block_name)
   * @returns Array of parsed blocks
   */
  async getPageBlocks(
    postId: number,
    params?: {
      fields?: string;
      render?: boolean;
      search?: string;
      block_name?: string;
      outline?: boolean;
      summary_only?: boolean;
      include_legacy_paths?: boolean;
    }
  ): Promise<PageBlocksResponse> {
    if (postId === undefined || postId === null) {
      throw new Error('Post ID is required');
    }

    const queryParams: Record<string, string> = {};
    if (params?.fields) queryParams.fields = params.fields;
    if (params?.render) queryParams.render = 'true';
    if (params?.search) queryParams.search = params.search;
    if (params?.block_name) queryParams.block_name = params.block_name;
    if (params?.outline) queryParams.outline = 'true';
    if (params?.summary_only) queryParams.summary_only = 'true';
    if (params?.include_legacy_paths) queryParams.include_legacy_paths = 'true';

    const response = await this.client.get<PageBlocksResponse>(
      `/posts/${postId}/blocks`,
      { params: queryParams }
    );
    return response.data;
  }

  /**
   * Search posts by title/content with filters. Cheap WP_Query lookup —
   * returns post stubs with no block parsing.
   */
  async findPosts(
    params?: import('./types.js').FindPostsParams
  ): Promise<import('./types.js').FindPostsResponse> {
    const queryParams: Record<string, string> = {};
    if (params?.search) queryParams.search = params.search;
    if (params?.post_type) queryParams.post_type = params.post_type;
    if (params?.post_status) queryParams.post_status = params.post_status;
    if (params?.per_page !== undefined) queryParams.per_page = String(params.per_page);
    if (params?.page !== undefined) queryParams.page = String(params.page);

    const response = await this.client.get<import('./types.js').FindPostsResponse>(
      '/find-posts',
      { params: queryParams }
    );
    return response.data;
  }

  /**
   * Look up a single post's metadata by post_id, url, or slug+post_type.
   * Returns title, status, permalink, modified, parent, author, etc.
   * No block parsing — cheap.
   */
  async getPostInfo(
    params: import('./types.js').PostInfoParams
  ): Promise<import('./types.js').PostInfoResponse> {
    if (
      (params.post_id === undefined || params.post_id === null) &&
      !params.url &&
      !params.slug
    ) {
      throw new Error('post_info requires one of: post_id, url, or slug');
    }

    const queryParams: Record<string, string> = {};
    if (params.post_id !== undefined && params.post_id !== null) {
      queryParams.post_id = String(params.post_id);
    }
    if (params.url) queryParams.url = params.url;
    if (params.slug) queryParams.slug = params.slug;
    if (params.post_type) queryParams.post_type = params.post_type;

    const response = await this.client.get<import('./types.js').PostInfoResponse>(
      '/post-info',
      { params: queryParams }
    );
    return response.data;
  }

  /**
   * Update a single block's attributes and/or innerHTML.
   *
   * @param postId - WordPress post/page ID
   * @param index - Zero-based block index
   * @param data - Partial attributes and/or innerHTML to update
   * @returns Updated block details with revision ID
   */
  async updateBlock(
    postId: number,
    index: number,
    data: { attributes?: Record<string, unknown>; innerHTML?: string }
  ): Promise<BlockUpdateResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (index < 0) throw new Error('Block index must be non-negative');
    if (!data.attributes && !data.innerHTML) {
      throw new Error('At least one of attributes or innerHTML must be provided');
    }

    const response = await this.client.patch<BlockUpdateResponse>(
      `/posts/${postId}/blocks/${index}`,
      data
    );
    return response.data;
  }

  /**
   * Insert one or more blocks at a specific position.
   *
   * @param postId - WordPress post/page ID
   * @param data - Insertion position and blocks to insert
   * @returns Inserted blocks with new indices, warnings, and revision ID
   */
  async insertBlocks(
    postId: number,
    data: {
      after?: number | 'start';
      before?: number;
      blocks: Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;
    }
  ): Promise<BlockWriteResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (!data.blocks || data.blocks.length === 0) {
      throw new Error('At least one block is required');
    }

    const response = await this.client.post<BlockWriteResponse>(
      `/posts/${postId}/blocks`,
      data
    );
    return response.data;
  }

  /**
   * Remove a block (or consecutive blocks) at a position.
   *
   * @param postId - WordPress post/page ID
   * @param index - Zero-based block index to remove
   * @param count - Number of consecutive blocks to remove (default 1)
   * @returns Deletion confirmation with revision ID
   */
  async deleteBlock(
    postId: number,
    index: number,
    count?: number
  ): Promise<BlockDeleteResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (index < 0) throw new Error('Block index must be non-negative');

    const params: Record<string, string> = {};
    if (count && count > 1) params.count = String(count);

    const response = await this.client.delete<BlockDeleteResponse>(
      `/posts/${postId}/blocks/${index}`,
      { params }
    );
    return response.data;
  }

  /**
   * Atomically replace a range of top-level blocks with a new shape, in a
   * single revision. Distinct from `replaceAllBlocks` (which rewrites the
   * entire post).
   *
   * @param postId - WordPress post/page ID
   * @param data   - { start, count, blocks } range descriptor
   * @returns Result with `removed`, `inserted[]`, warnings, revision IDs
   */
  async replaceBlocksRange(
    postId: number,
    data: {
      start: number;
      count: number;
      blocks: Array<{
        name: string;
        attributes?: Record<string, unknown>;
        innerHTML?: string;
      }>;
    }
  ): Promise<BlockReplaceRangeResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (typeof data.start !== 'number' || data.start < 0) {
      throw new Error('start must be a non-negative integer');
    }
    if (typeof data.count !== 'number' || data.count < 0) {
      throw new Error('count must be a non-negative integer');
    }
    if (!Array.isArray(data.blocks)) {
      throw new Error('blocks must be an array (may be empty for a pure delete)');
    }

    const response = await this.client.post<BlockReplaceRangeResponse>(
      `/posts/${postId}/blocks/replace`,
      data
    );
    return response.data;
  }

  /**
   * Replace all blocks on a page (full rewrite).
   *
   * Creates a revision before overwriting. Validates all block names and
   * warns on legacy/avoid-tier blocks.
   *
   * @param postId - WordPress post/page ID
   * @param blocks - Complete array of blocks for the page
   * @returns Written blocks with revision ID
   */
  async replaceAllBlocks(
    postId: number,
    blocks: Array<{
      name: string;
      attributes?: Record<string, unknown>;
      innerHTML?: string;
    }>
  ): Promise<BlockWriteResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (!blocks || blocks.length === 0) {
      throw new Error('At least one block is required for a full rewrite');
    }

    const response = await this.client.put<BlockWriteResponse>(
      `/posts/${postId}/blocks`,
      { blocks }
    );
    return response.data;
  }

  // ============================================
  // Block Tree Mutation
  // ============================================

  /**
   * Perform a structural mutation on a nested block tree.
   *
   * @param postId - WordPress post/page ID
   * @param data - Mutation request with operation, path, and operation-specific fields
   * @returns Mutation result with revision IDs and optional warnings
   */
  async mutateBlockTree(postId: number, data: MutationRequest): Promise<MutationResponse> {
    if (postId === undefined || postId === null) {
      throw new Error('Post ID is required');
    }

    const response = await this.client.post<MutationResponse>(
      `/posts/${postId}/mutate`,
      data
    );
    return response.data;
  }

  // ============================================
  // Revert Operations
  // ============================================

  /**
   * Revert a post to a specific revision.
   *
   * @param postId - WordPress post/page ID
   * @param revisionId - Revision ID to restore
   * @returns Revert result with revision IDs
   */
  async revertToRevision(postId: number, revisionId: number): Promise<unknown> {
    if (postId === undefined || postId === null) {
      throw new Error('Post ID is required');
    }
    const response = await this.client.post(`/posts/${postId}/revert`, { revision_id: revisionId });
    return response.data;
  }

  // ============================================
  // Pattern Operations
  // ============================================

  /**
   * Insert a pattern at a position on a page.
   *
   * @param postId - WordPress post/page ID
   * @param data - Pattern ID, insertion position, and sync mode
   * @returns Inserted pattern details with revision ID
   */
  async insertPattern(
    postId: number,
    data: {
      pattern_id: number | string;
      after?: number;
      before?: number;
      synced?: boolean;
    }
  ): Promise<PatternInsertResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (data.pattern_id === undefined || data.pattern_id === null) throw new Error('Pattern ID is required');

    const response = await this.client.post<PatternInsertResponse>(
      `/posts/${postId}/insert-pattern`,
      data
    );
    return response.data;
  }

  // ──────────────────────────────────────────────────────────
  // v1.2 — Docs lifecycle
  // ──────────────────────────────────────────────────────────

  /**
   * Create a new post or page.
   *
   * @param data - Title (required), plus optional status, content/blocks,
   *               terms, parent, slug, etc.
   * @returns The created post's ID, slug, permalink, edit link, revision.
   */
  async createPost(data: CreatePostRequest): Promise<PostMutationResponse> {
    if (!data.title || data.title.trim() === '') {
      throw new Error('create_post: a non-empty "title" is required');
    }
    if (data.content !== undefined && Array.isArray(data.blocks)) {
      throw new Error('create_post: "content" and "blocks" are mutually exclusive');
    }
    const response = await this.client.post<PostMutationResponse>('/posts', data);
    return response.data;
  }

  /**
   * Update post metadata, status, or terms. Block content edits stay on the
   * per-block tools (update_block / mutate_block_tree / replace_all_blocks).
   *
   * Use `status: trash` to trash; any non-trash status untrashes a trashed post.
   */
  async updatePost(postId: number, data: UpdatePostRequest): Promise<PostMutationResponse> {
    if (postId === undefined || postId === null) {
      throw new Error('update_post: post_id is required');
    }
    const response = await this.client.patch<PostMutationResponse>(`/posts/${postId}`, data);
    return response.data;
  }

  /** List terms in a taxonomy (default: category). */
  async listTerms(args: ListTermsRequest = {}): Promise<ListTermsResponse> {
    const response = await this.client.get<ListTermsResponse>('/terms', { params: args });
    return response.data;
  }

  /**
   * Upload an item to the WordPress media library.
   *
   * Three input modes (exactly one of `path`, `url`, or `data_base64`):
   *  - `path`: local filesystem path on the MCP host. Read and POSTed as
   *    multipart/form-data. The MCP process must have read access.
   *  - `url`: WordPress fetches the URL server-side (sideload, 25 MB cap).
   *  - `data_base64`: base64-encoded contents. Requires `filename`.
   */
  async uploadMedia(args: UploadMediaRequest): Promise<UploadMediaResponse> {
    const modes = (['path', 'url', 'data_base64'] as const).filter(
      (k) => typeof args[k] === 'string' && (args[k] as string).length > 0,
    );
    if (modes.length === 0) {
      throw new Error('upload_media: provide one of "path", "url", or "data_base64"');
    }
    if (modes.length > 1) {
      throw new Error(`upload_media: only one of path/url/data_base64 (got ${modes.join(', ')})`);
    }
    if (args.data_base64 && !args.filename) {
      throw new Error('upload_media: "filename" is required with "data_base64"');
    }

    if (args.path) {
      const fs = await import('node:fs/promises');
      const path = await import('node:path');
      const data = await fs.readFile(args.path);
      const filename = args.filename ?? path.basename(args.path);
      const form = new FormData();
      form.append('file', new Blob([new Uint8Array(data)]), filename);
      if (args.title) form.append('title', args.title);
      if (args.alt_text) form.append('alt_text', args.alt_text);
      if (args.caption) form.append('caption', args.caption);
      if (args.description) form.append('description', args.description);
      if (typeof args.post_id === 'number') form.append('post_id', String(args.post_id));

      // axios sets the multipart Content-Type and boundary automatically.
      const response = await this.client.post<UploadMediaResponse>('/media', form);
      return response.data;
    }

    // url or data_base64 ride as JSON.
    const response = await this.client.post<UploadMediaResponse>('/media', args);
    return response.data;
  }

  // ──────────────────────────────────────────────────────────
  // v1.2 — Yoast SEO metadata (separate REST namespace)
  //
  // Endpoints: gravitykit/v1/yoast-seo/{post_id}, gravitykit/v1/yoast-seo/bulk
  // Backed by the Block-Theme mu-plugin, not gk-block-api.
  // ──────────────────────────────────────────────────────────

  /** Read all Yoast SEO metadata for a post. */
  async getYoastSEO(postId: number): Promise<YoastSEOMeta> {
    if (postId === undefined || postId === null) {
      throw new Error('yoast_get_seo: post_id is required');
    }
    const response = await this.yoastClient.get<YoastSEOMeta>(`/yoast-seo/${postId}`);
    return response.data;
  }

  /** Partial update of Yoast SEO fields on a single post. */
  async updateYoastSEO(postId: number, fields: YoastUpdateRequest): Promise<YoastSEOMeta> {
    if (postId === undefined || postId === null) {
      throw new Error('yoast_update_seo: post_id is required');
    }
    const response = await this.yoastClient.patch<YoastSEOMeta>(`/yoast-seo/${postId}`, fields);
    return response.data;
  }

  /** Batch-update Yoast SEO fields on multiple posts. Order preserved in response. */
  async bulkUpdateYoastSEO(posts: YoastBulkUpdateItem[]): Promise<YoastBulkUpdateResponse> {
    if (!Array.isArray(posts) || posts.length === 0) {
      throw new Error('yoast_bulk_update_seo: non-empty `posts` array is required');
    }
    const response = await this.yoastClient.patch<YoastBulkUpdateResponse>('/yoast-seo/bulk', { posts });
    return response.data;
  }
}
