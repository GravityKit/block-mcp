/**
 * WordPress Block API Client
 *
 * HTTP client for the gk-block-api WordPress REST plugin.
 * Handles authentication via Application Passwords and provides
 * typed methods for every REST endpoint.
 */

import axios, { AxiosInstance, AxiosError, AxiosRequestConfig } from 'axios';
import { translateWpError } from './error-translator.js';
import { restRouteUrl } from './rest-url.js';

/** Best-effort MIME type from a filename extension, for multipart uploads. */
function mimeForFilename(filename: string): string {
  const ext = filename.toLowerCase().split('.').pop() ?? '';
  const map: Record<string, string> = {
    png: 'image/png',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    gif: 'image/gif',
    webp: 'image/webp',
    svg: 'image/svg+xml',
    pdf: 'application/pdf',
  };
  return map[ext] ?? 'application/octet-stream';
}

/** Max retry attempts for transient server / network errors. */
const MAX_RETRIES = 2;

/**
 * Verbs safe to retry without risking duplicate or wrong work.
 *
 * DELETE is intentionally NOT here: `delete_block` deletes by flat index, so if
 * the server commits the delete but the response is lost (timeout / 502), a
 * replay removes the *next* block (indices shift after the first delete). The
 * delete_block tool mirrors this with `idempotentHint: false`.
 */
const IDEMPOTENT_METHODS = new Set(['get', 'head', 'options']);

/** Sleep for `ms` milliseconds. */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Backoff delay (ms) for retry `attempt` (1-indexed). Exponential with jitter:
 *   attempt 1 → ~500ms ± 25%
 *   attempt 2 → ~1500ms ± 25%
 * Jitter avoids thundering-herd retries from many MCP clients hitting the
 * same WP host simultaneously.
 */
function backoffMs(attempt: number): number {
  const base = 500 * 3 ** (attempt - 1);
  const jitter = base * 0.25 * (Math.random() * 2 - 1);
  return Math.round(base + jitter);
}

/**
 * Decide whether an axios error is retryable.
 *
 * Policy:
 *   - 429 (rate limited) → retry on any method. The WP plugin returns 429
 *     BEFORE doing any work, so a retry is safe even for writes.
 *   - 502 / 503 / 504 (server overload / gateway issues) → retry only
 *     idempotent methods. The server may or may not have processed the
 *     request; replaying a write could double-apply.
 *   - Network errors with no response (ECONNRESET, ETIMEDOUT, ENETUNREACH)
 *     → retry idempotent methods only.
 *
 * Anything else is a real error that the caller needs to see.
 */
export function isRetryable(error: AxiosError): boolean {
  const method = (error.config?.method ?? 'get').toLowerCase();
  const idempotent = IDEMPOTENT_METHODS.has(method);

  if (error.response) {
    const status = error.response.status;
    if (status === 429) return true;
    if ((status === 502 || status === 503 || status === 504) && idempotent) return true;
    return false;
  }

  // No response — network-level failure.
  const code = error.code;
  if (code === 'ECONNREFUSED') return false; // wrong URL / WP down — don't retry blindly
  if (code === 'ECONNRESET' || code === 'ETIMEDOUT' || code === 'ENETUNREACH' || code === 'EAI_AGAIN') {
    return idempotent;
  }
  return false;
}
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
  BlockBatchUpdateItem,
  BlockBatchUpdateResponse,
  GetBlockResponse,
  StorageModeScanResult,
  PatternInsertResponse,
  MutationRequest,
  MutationResponse,
  ResolveUrlResponse,
  BlockInput,
  BlockPatch,
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
  post_id?: number;
  summary?: Record<string, unknown>;
  blocks: Block[];
  /** Present when `limit` pagination is in play. */
  pagination?: {
    limit?: number;
    offset?: number;
    total?: number;
    /** Pass back as `cursor` to fetch the next page; null on the last page. */
    next_cursor?: string | null;
  };
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
   * Create a new WordPress Block API client.
   *
   * @param config - MCP server configuration with URL and credentials
   */
  constructor(config: BlockMCPConfig) {
    const { wordpress_url, auth } = config;

    if (!wordpress_url) {
      throw new Error('WordPress site URL is required (WORDPRESS_URL)');
    }
    if (!auth) {
      throw new Error('WordPress authentication credentials are required (WORDPRESS_USER, WORDPRESS_APP_PASSWORD)');
    }
    if (!auth.username) {
      throw new Error('WordPress API username is required (WORDPRESS_USER)');
    }
    if (!auth.application_password) {
      throw new Error('WordPress Application Password is required (WORDPRESS_APP_PASSWORD)');
    }

    // Build base64-encoded Basic Auth header
    const credentials = Buffer.from(
      `${auth.username}:${auth.application_password}`
    ).toString('base64');

    // Permalink-independent ?rest_route= form so tool calls don't 404 on a
    // plain-permalink site after the connector completes.
    const baseURL = restRouteUrl(wordpress_url);

    this.client = axios.create({
      baseURL,
      headers: {
        Authorization: `Basic ${credentials}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'User-Agent': 'GravityKit Block MCP Server (https://github.com/GravityKit/block-mcp)',
      },
      timeout: 30000,
    });

    // Response interceptor: retry transient errors with exponential backoff,
    // then format any final error so it carries wpCode/wpData/wpStatus for
    // the server-level catch in src/index.ts.
    this.client.interceptors.response.use(
      (r) => r,
      async (error: AxiosError) => {
        const config = error.config as (AxiosRequestConfig & { __retryCount?: number }) | undefined;
        if (config && isRetryable(error)) {
          const attempt = (config.__retryCount ?? 0) + 1;
          if (attempt <= MAX_RETRIES) {
            config.__retryCount = attempt;
            await sleep(backoffMs(attempt));
            return this.client.request(config);
          }
        }
        throw this.formatError(error);
      },
    );
  }

  /**
   * Format an Axios error into a thrown Error that carries the full
   * WordPress `{ code, message, data }` payload, not just the message.
   *
   * Without this, agents lose the actionable hints the PHP plugin attaches
   * to errors (e.g. `legacy_block` carries `suggested_replacement`,
   * `policy_resource`, `namespace`; `dual_storage_requires_both` carries
   * `block`, `storage_mode`). The Error message is human-readable; the
   * `wpCode` and `wpData` properties expose the structured payload to the
   * server-level catch in src/index.ts so it can surface them in the
   * tool response.
   *
   * @param error - The Axios error to format
   * @returns Error to throw
   */
  private formatError(error: AxiosError): Error {
    if (error.response) {
      const { status, data } = error.response;
      const body = data as Record<string, unknown> | string | undefined;

      let detail = 'Unknown error';
      let code: string | undefined;
      let wpData: unknown = null;

      if (body && typeof body === 'object') {
        if ('message' in body) detail = String(body.message);
        else if ('error' in body) detail = String(body.error);
        if ('code' in body && typeof body.code === 'string') code = body.code;
        if ('data' in body) wpData = body.data;
      } else if (typeof body === 'string') {
        detail = body;
      }

      // Replace HTTP-shaped detail with an agent-actionable hint when we
      // recognize the code. Original code/data still flow through wpCode /
      // wpData so callers can pattern-match the raw form if they want to.
      const hint = translateWpError(code, wpData);
      const message = hint
        ? `Block API Error (${status}): ${hint}${code ? ` (${code})` : ''}`
        : `Block API Error (${status}): ${detail}`;

      const err = new Error(message) as Error & {
        wpCode?: string;
        wpData?: unknown;
        wpStatus?: number;
      };
      err.wpCode = code;
      err.wpData = wpData;
      err.wpStatus = status;
      return err;
    }

    if (error.code === 'ECONNREFUSED') {
      return new Error('Block API Error: Connection refused. Is the WordPress site reachable?');
    }
    if (error.code === 'ETIMEDOUT') {
      return new Error('Block API Error: Request timed out after 30 seconds.');
    }
    return new Error(`Block API Error: ${error.message}`);
  }

  // ============================================
  // Registry & Discovery
  // ============================================

  /**
   * Get registered block types with preference + storage_mode metadata.
   *
   * @param params - Optional filters (namespace, category, tier, storage_mode,
   *                 preferred_only, search, usage_only).
   * @returns Array of block types
   */
  async getBlockTypes(params?: {
    namespace?: string;
    category?: string;
    preferred_only?: boolean;
    tier?: 'preferred' | 'acceptable' | 'avoid' | 'legacy';
    storage_mode?: 'static' | 'dynamic' | 'dual';
    search?: string;
    usage_only?: boolean;
  }): Promise<BlockTypesResponse> {
    const queryParams: Record<string, string> = {};
    if (params?.namespace)      queryParams.namespace      = params.namespace;
    if (params?.category)       queryParams.category       = params.category;
    if (params?.preferred_only) queryParams.preferred_only = 'true';
    if (params?.tier)           queryParams.tier           = params.tier;
    if (params?.storage_mode)   queryParams.storage_mode   = params.storage_mode;
    if (params?.search)         queryParams.search         = params.search;
    if (params?.usage_only)     queryParams.usage_only     = 'true';

    const response = await this.client.get<BlockTypesResponse>('/block-types', {
      params: queryParams,
    });
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
    refresh?: boolean;
  }): Promise<PatternsResponse> {
    const queryParams: Record<string, string> = {};
    if (params?.q) queryParams.q = params.q;
    if (params?.synced !== undefined) queryParams.synced = String(params.synced);
    if (params?.min_score !== undefined) queryParams.min_score = String(params.min_score);
    if (params?.category) queryParams.category = params.category;
    if (params?.limit !== undefined) queryParams.limit = String(params.limit);
    if (params?.order_by) queryParams.order_by = params.order_by;
    if (params?.refresh) queryParams.refresh = 'true';

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
      /** When true (default), missing gk_refs are assigned and persisted. Pass false to skip the silent write side effect. */
      persist_refs?: boolean;
      /** Page size: top-level blocks per response. Pairs with `cursor`. */
      limit?: number;
      /** Opaque pagination cursor from a prior response's `next_cursor`. */
      cursor?: string;
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
    // Forward both true and false explicitly when set, so tool-layer intent
    // matches what reaches the server. The server default (true) only kicks in
    // when the param is omitted entirely.
    if (params?.persist_refs === false) queryParams.persist_refs = 'false';
    else if (params?.persist_refs === true) queryParams.persist_refs = 'true';
    if (typeof params?.limit === 'number') queryParams.limit = String(params.limit);
    if (params?.cursor) queryParams.cursor = params.cursor;

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
    data: BlockPatch
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
   * Update a single block by its stable gk_ref instead of a flat index.
   * Refs survive sibling shifts so chained mutations don't go stale.
   *
   * @param postId - WordPress post/page ID
   * @param ref    - Stable ref (e.g. "blk_a3f2c1q9") from get_page_blocks
   * @param data   - Partial attributes and/or innerHTML
   * @returns 404 ref_stale if the ref no longer matches any block.
   */
  async updateBlockByRef(
    postId: number,
    ref: string,
    data: BlockPatch
  ): Promise<BlockUpdateResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (!ref || typeof ref !== 'string') throw new Error('Ref is required');
    if (!data.attributes && !data.innerHTML) {
      throw new Error('At least one of attributes or innerHTML must be provided');
    }

    const response = await this.client.patch<BlockUpdateResponse>(
      `/posts/${postId}/blocks/by-ref/${encodeURIComponent(ref)}`,
      data
    );
    return response.data;
  }

  /**
   * Apply N independent block updates atomically in ONE WordPress revision.
   *
   * Each item targets one block by stable `ref` (recommended) or `flat_index`,
   * with `attributes` and/or `innerHTML` to apply. Validation is all-or-nothing:
   * if any item is invalid (stale ref, out-of-range index, dual-storage
   * rejection, duplicate target), the whole batch fails with HTTP 400 and an
   * itemized `errors` payload — no partial writes ever hit disk.
   *
   * Counts as ONE write against the per-post rate limit. Server caps batch
   * size to prevent the rate-limit exemption from being abused.
   *
   * @param postId  WordPress post/page ID.
   * @param updates Update items (1..MAX_BATCH_SIZE).
   * @returns       Per-item results plus the single revision ID.
   */
  async updateBlocksBatch(
    postId: number,
    updates: BlockBatchUpdateItem[],
    options: { verbose?: boolean } = {}
  ): Promise<BlockBatchUpdateResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (!Array.isArray(updates) || updates.length === 0) {
      throw new Error('updates must be a non-empty array');
    }

    const body: { updates: BlockBatchUpdateItem[]; verbose?: boolean } = { updates };
    if (options.verbose) body.verbose = true;

    const response = await this.client.post<BlockBatchUpdateResponse>(
      `/posts/${postId}/blocks/batch-update`,
      body
    );
    return response.data;
  }

  /**
   * Fetch a single block by stable ref or flat index. Returns the canonical
   * `saved` snapshot — same shape that write endpoints echo, so verification
   * reads use the identical contract as the writes that produced them.
   *
   * Lighter than getPageBlocks() when you only need one block. Use this when
   * you want to confirm the current state of a known ref before chaining an
   * edit, not to discover what's on the page.
   *
   * @param postId  WordPress post/page ID.
   * @param target  Either `{ ref }` or `{ flatIndex }`. Exactly one required.
   * @returns       { success, saved } where `saved` mirrors update_block's saved.
   */
  async getBlock(
    postId: number,
    target: { ref?: string; flatIndex?: number }
  ): Promise<GetBlockResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    const hasRef = typeof target.ref === 'string' && target.ref !== '';
    const hasIdx = typeof target.flatIndex === 'number';
    if (hasRef === hasIdx) {
      throw new Error('Provide exactly one of ref or flatIndex');
    }

    const params: Record<string, string | number> = {};
    if (hasRef) params.ref = target.ref!;
    else params.flat_index = target.flatIndex!;

    const response = await this.client.get<GetBlockResponse>(`/posts/${postId}/block`, { params });
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
      after_ref?: string;
      before_ref?: string;
      // Recursive: containers (groups/columns) nest children via innerBlocks.
      blocks: BlockInput[];
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
   * Delete one or more blocks identified by the leading block's stable gk_ref.
   *
   * @param postId - WordPress post/page ID
   * @param ref    - Stable ref of the first block to remove
   * @param count  - Consecutive blocks to remove (default 1)
   */
  async deleteBlockByRef(
    postId: number,
    ref: string,
    count?: number
  ): Promise<BlockDeleteResponse> {
    if (postId === undefined || postId === null) throw new Error('Post ID is required');
    if (!ref || typeof ref !== 'string') throw new Error('Ref is required');

    const params: Record<string, string> = {};
    if (count && count > 1) params.count = String(count);

    const response = await this.client.delete<BlockDeleteResponse>(
      `/posts/${postId}/blocks/by-ref/${encodeURIComponent(ref)}`,
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
      // Recursive: containers (groups/columns) nest children via innerBlocks.
      blocks: BlockInput[];
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
    blocks: BlockInput[]
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
      const nodePath = await import('node:path');
      const { default: FormData } = await import('form-data');
      const data = await fs.readFile(args.path);
      const filename = args.filename ?? nodePath.basename(args.path);
      const form = new FormData();
      form.append('file', data, { filename, contentType: mimeForFilename(filename) });
      if (args.title) form.append('title', args.title);
      if (args.alt_text) form.append('alt_text', args.alt_text);
      if (args.caption) form.append('caption', args.caption);
      if (args.description) form.append('description', args.description);
      if (typeof args.post_id === 'number') form.append('post_id', String(args.post_id));

      // The shared axios instance defaults Content-Type to application/json,
      // which makes axios JSON-serialize the form and send NO file. The
      // form-data package's getHeaders() supplies multipart/form-data WITH the
      // boundary, overriding that default so this is a real multipart upload.
      const response = await this.client.post<UploadMediaResponse>('/media', form, {
        headers: form.getHeaders(),
      });
      return response.data;
    }

    // url or data_base64 ride as JSON.
    const response = await this.client.post<UploadMediaResponse>('/media', args);
    return response.data;
  }

  // ──────────────────────────────────────────────────────────
  // v1.3 — Yoast SEO metadata (gk-block-api/v1/yoast/...)
  //
  // Backed by Yoast_Bridge inside gk-block-api itself. Routes register only
  // when Yoast SEO is active; absent Yoast you'll get 404 rest_no_route.
  // ──────────────────────────────────────────────────────────

  /** Read all Yoast SEO metadata for a post. */
  async getYoastSEO(postId: number): Promise<YoastSEOMeta> {
    if (postId === undefined || postId === null) {
      throw new Error('yoast_get_seo: post_id is required');
    }
    const response = await this.client.get<YoastSEOMeta>(`/yoast/${postId}`);
    return response.data;
  }

  /** Partial update of Yoast SEO fields on a single post. */
  async updateYoastSEO(postId: number, fields: YoastUpdateRequest): Promise<YoastSEOMeta> {
    if (postId === undefined || postId === null) {
      throw new Error('yoast_update_seo: post_id is required');
    }
    const response = await this.client.patch<YoastSEOMeta>(`/yoast/${postId}`, fields);
    return response.data;
  }

  /** Batch-update Yoast SEO fields on multiple posts. Order preserved in response. */
  async bulkUpdateYoastSEO(posts: YoastBulkUpdateItem[]): Promise<YoastBulkUpdateResponse> {
    if (!Array.isArray(posts) || posts.length === 0) {
      throw new Error('yoast_bulk_update_seo: non-empty `posts` array is required');
    }
    const response = await this.client.patch<YoastBulkUpdateResponse>('/yoast/bulk', { posts });
    return response.data;
  }
}
