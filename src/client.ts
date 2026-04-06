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
  PatternInsertResponse,
  MutationRequest,
  MutationResponse,
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

    const baseURL = `${wordpress_url.replace(/\/+$/, '')}/wp-json/gk-block-api/v1`;

    this.client = axios.create({
      baseURL,
      headers: {
        Authorization: `Basic ${credentials}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'User-Agent': 'MonoKit Block MCP Server (https://github.com/GravityKit)',
      },
      timeout: 30000,
    });

    // Response interceptor for consistent error formatting
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
   * @param fields - Optional comma-separated list of fields to include (e.g. "path,name,attributes")
   * @returns Array of parsed blocks
   */
  async getPageBlocks(postId: number, fields?: string): Promise<PageBlocksResponse> {
    if (postId === undefined || postId === null) {
      throw new Error('Post ID is required');
    }

    const params: Record<string, string> = {};
    if (fields) {
      params.fields = fields;
    }

    const response = await this.client.get<PageBlocksResponse>(
      `/posts/${postId}/blocks`,
      { params }
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
}
