/**
 * Tool tests: get_page_blocks pagination (limit + cursor).
 *
 * The REST route and the client both support `limit`/`cursor` pagination,
 * but the MCP tool never exposed them — agents had no way to page through a
 * huge post. These pin the tool-layer contract:
 *   - limit/cursor are declared on the inputSchema (dispatch validation
 *     rejects undeclared keys, so omission = unusable);
 *   - both forward to client.getPageBlocks;
 *   - the response's `pagination` (incl. next_cursor) passes through so the
 *     agent can fetch the next page.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleReadTool, READ_TOOLS } from '../../../tools/read.js';
import { makeMockClient } from '../../helpers/mock-client.js';
import { pageBlocksResponse } from '../../fixtures/rest-responses.js';

describe('get_page_blocks — pagination', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
  });

  it('declares limit and cursor on the inputSchema', () => {
    const tool = READ_TOOLS.find((t) => t.name === 'get_page_blocks');
    const props = (tool?.inputSchema as { properties?: Record<string, unknown> })?.properties ?? {};
    expect(props.limit).toBeDefined();
    expect(props.cursor).toBeDefined();
  });

  it('forwards limit and cursor to the client', async () => {
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
    await handleReadTool(
      'get_page_blocks',
      { post_id: 42, limit: 2, cursor: 'idx_2' },
      client as never,
    );
    expect(client.getPageBlocks).toHaveBeenCalledWith(
      42,
      expect.objectContaining({ limit: 2, cursor: 'idx_2' }),
    );
  });

  it('passes the pagination meta (next_cursor) through to the agent', async () => {
    client.getPageBlocks.mockResolvedValue({
      ...pageBlocksResponse,
      pagination: { limit: 2, offset: 0, total: 4, next_cursor: 'idx_2' },
    });
    const result = (await handleReadTool(
      'get_page_blocks',
      { post_id: 42, limit: 2 },
      client as never,
    )) as { pagination?: { next_cursor?: string | null } };
    expect(result.pagination?.next_cursor).toBe('idx_2');
  });

  it('omits pagination from the response when not paginating', async () => {
    client.getPageBlocks.mockResolvedValue(pageBlocksResponse);
    const result = (await handleReadTool('get_page_blocks', { post_id: 42 }, client as never)) as {
      pagination?: unknown;
    };
    expect(result.pagination).toBeUndefined();
  });
});
