/**
 * Discovery Tools
 *
 * MCP tools for exploring the block type registry, browsing patterns,
 * and viewing site-wide usage statistics. These are read-only tools
 * that help AI agents understand what blocks and patterns are available
 * before making edits.
 */

import type { WordPressBlockClient } from '../client.js';
import { enrichBlockTypes, enrichPatternList } from '../preferences.js';

/**
 * Tool definitions for the discovery category.
 * Each definition follows the MCP inputSchema format.
 */
export const DISCOVERY_TOOLS = [
  {
    name: 'list_block_types',
    description:
      'List registered block types grouped by preference tier (preferred/standard/acceptable/avoid/legacy). Call before inserting content.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        namespace: {
          type: 'string',
          description: 'Filter by namespace (e.g. "core", "filter", "stackable").',
        },
        category: {
          type: 'string',
          description: 'Filter by category (e.g. "text", "media", "design").',
        },
        preferred_only: {
          type: 'boolean',
          description: 'If true, only blocks with score >= 50.',
        },
      },
    },
  },
  {
    name: 'list_patterns',
    description:
      'List block patterns sorted by preference score. Check before building from scratch.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        search: {
          type: 'string',
          description: 'Search by name or keyword.',
        },
        synced: {
          type: 'boolean',
          description: 'true = synced only, false = registered only, omit for all.',
        },
        min_score: {
          type: 'number',
          description: 'Minimum score; use 0 to exclude legacy.',
        },
        limit: {
          type: 'number',
          description: 'Max results (default 20).',
        },
      },
    },
  },
  {
    name: 'get_pattern',
    description:
      "Get a pattern's full block content and metadata. Use after list_patterns.",
    inputSchema: {
      type: 'object' as const,
      properties: {
        pattern_id: {
          type: ['number', 'string'],
          description: 'Numeric post ID (synced) or registered pattern name.',
        },
      },
      required: ['pattern_id'],
    },
  },
  {
    name: 'get_site_usage',
    description:
      'Get site-wide block/pattern usage stats. Identifies legacy patterns with zero references.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        refresh: {
          type: 'boolean',
          description: 'Bust the 1-hour cache and regenerate.',
        },
      },
    },
  },
  {
    name: 'resolve_url',
    description:
      'Resolve a URL or path to a WordPress post ID. Accepts full URLs (https://site.com/path/) or paths (/path/). Handles all post types and pretty permalinks. Use this before any get_page_blocks / update / mutate call when you only have a URL.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        url: {
          type: 'string',
          description: 'Full URL or site-relative path (e.g. "/products/gravityedit/").',
        },
      },
      required: ['url'],
    },
  },
];

/**
 * Handle a discovery tool call.
 *
 * @param toolName - The name of the tool being called
 * @param args - Tool arguments from the AI agent
 * @param client - WordPress Block API client instance
 * @returns Tool result ready for MCP response
 */
export async function handleDiscoveryTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient
): Promise<unknown> {
  switch (toolName) {
    case 'list_block_types': {
      const response = await client.getBlockTypes({
        namespace: args.namespace as string | undefined,
        category: args.category as string | undefined,
        preferred: args.preferred_only as boolean | undefined,
      });

      const enriched = enrichBlockTypes(response.block_types);

      return {
        block_types: enriched.block_types,
        count: enriched.block_types.length,
        guidance: enriched.guidance,
      };
    }

    case 'list_patterns': {
      const response = await client.getPatterns({
        q: args.search as string | undefined,
        synced: args.synced as boolean | undefined,
        min_score: args.min_score as number | undefined,
        limit: args.limit as number | undefined,
      });

      const enriched = enrichPatternList(response.patterns);

      return {
        patterns: enriched.patterns,
        count: enriched.patterns.length,
        summary: enriched.summary,
      };
    }

    case 'get_pattern': {
      const patternId = args.pattern_id;
      if (patternId === undefined || patternId === null) {
        throw new Error('pattern_id is required');
      }
      const pattern = await client.getPattern(patternId as number | string);
      return pattern;
    }

    case 'get_site_usage': {
      const usage = await client.getSiteUsage(args.refresh as boolean | undefined);
      return usage;
    }

    case 'resolve_url': {
      const url = args.url;
      if (typeof url !== 'string' || url.length === 0) {
        throw new Error('url is required');
      }
      return await client.resolveUrl(url);
    }

    default:
      throw new Error(`Unknown discovery tool: ${toolName}`);
  }
}
