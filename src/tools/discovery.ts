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
      'List available WordPress block types with preference scores and tier guidance. ' +
      'Returns blocks grouped by preference tier (preferred, standard, acceptable, avoid, legacy). ' +
      'Use this to understand what blocks are available before inserting content.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        namespace: {
          type: 'string',
          description:
            'Filter by block namespace (e.g. "core", "filter" (theme), "stackable"). ' +
            'Omit to list all namespaces.',
        },
        category: {
          type: 'string',
          description: 'Filter by block category (e.g. "text", "media", "design").',
        },
        preferred_only: {
          type: 'boolean',
          description:
            'If true, only return blocks with preference score >= 50 ' +
            '(preferred and acceptable tiers).',
        },
      },
    },
  },
  {
    name: 'list_patterns',
    description:
      'Browse WordPress block patterns with preference scoring. ' +
      'Patterns are pre-sorted by preference score (best first). ' +
      'Includes a natural-language summary of recommended vs. legacy patterns. ' +
      'Always check patterns before building content from scratch.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        search: {
          type: 'string',
          description: 'Search patterns by name or keyword.',
        },
        synced: {
          type: 'boolean',
          description:
            'Filter by sync status. true = synced (wp_block) only, ' +
            'false = registered only, omit for all.',
        },
        min_score: {
          type: 'number',
          description:
            'Minimum preference score to include. Use positive values ' +
            'to filter out legacy patterns (e.g. 0 excludes negative scores).',
        },
        limit: {
          type: 'number',
          description: 'Maximum number of patterns to return (default: 20).',
        },
      },
    },
  },
  {
    name: 'get_pattern',
    description:
      "Get a single pattern's full block content, metadata, and preference details. " +
      'Use this after list_patterns to inspect a specific pattern before inserting it.',
    inputSchema: {
      type: 'object' as const,
      properties: {
        pattern_id: {
          type: ['number', 'string'],
          description:
            'Pattern ID — numeric post ID for synced patterns, ' +
            'or registered pattern name.',
        },
      },
      required: ['pattern_id'],
    },
  },
  {
    name: 'get_site_usage',
    description:
      'Get block and pattern usage statistics across the entire site. ' +
      'Shows which blocks are most used, namespace totals, pattern reference counts, ' +
      'and identifies legacy patterns with zero references (candidates for cleanup).',
    inputSchema: {
      type: 'object' as const,
      properties: {
        refresh: {
          type: 'boolean',
          description:
            'If true, bust the server-side cache and regenerate stats fresh. ' +
            'Stats are cached for 1 hour by default.',
        },
      },
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

    default:
      throw new Error(`Unknown discovery tool: ${toolName}`);
  }
}
