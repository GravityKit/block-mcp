/**
 * Term tools — taxonomy term discovery (read-only).
 */

import type { WordPressBlockClient } from '../client.js';
import type { ListTermsRequest } from '../types.js';

export const TERM_TOOLS = [
  {
    name: 'list_terms',
    description:
      'List terms in a taxonomy (default: category). Useful for discovering category and tag IDs to pass to create_post or update_post.',
    annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'List terms' },
    inputSchema: {
      type: 'object' as const,
      properties: {
        taxonomy: { type: 'string', description: 'Taxonomy slug. Default: category.' },
        search: { type: 'string', description: 'LIKE match against term name.' },
        parent: { type: 'number' },
        hide_empty: { type: 'boolean', description: 'Default: false.' },
        per_page: { type: 'number', description: 'Default 100, max 200.' },
        page: { type: 'number', description: '1-indexed.' },
        orderby: { type: 'string', enum: ['name', 'count', 'term_id', 'slug'] },
        order: { type: 'string', enum: ['asc', 'desc'] },
        include: { type: 'array', items: { type: 'number' } },
        slug: { type: 'string' },
      },
    },
  },
];

export async function handleTermTool(
  toolName: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
): Promise<unknown> {
  switch (toolName) {
    case 'list_terms': {
      return client.listTerms(args as ListTermsRequest);
    }
    default:
      throw new Error(`Unknown term tool: ${toolName}`);
  }
}
