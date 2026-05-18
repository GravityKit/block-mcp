/**
 * Tool tests: get_page_blocks — persist_refs option
 *
 * Covers:
 *   - persist_refs not forwarded when not specified (server default applies)
 *   - persist_refs:false forwarded when explicitly set
 *   - persist_refs:true forwarded when explicitly set
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleReadTool } from '../../../tools/read.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('get_page_blocks — persist_refs option', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('does not pass persist_refs when not specified (server default applies)', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1 }, client as any);
    const opts = client.getPageBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(opts).not.toHaveProperty('persist_refs');
  });

  it('forwards persist_refs:false when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: false }, client as any);
    const opts = client.getPageBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(opts.persist_refs).toBe(false);
  });

  it('forwards persist_refs:true when explicitly set', async () => {
    await handleReadTool('get_page_blocks', { post_id: 1, persist_refs: true }, client as any);
    const opts = client.getPageBlocks.mock.calls[0]![1] as Record<string, unknown>;
    expect(opts.persist_refs).toBe(true);
  });
});
