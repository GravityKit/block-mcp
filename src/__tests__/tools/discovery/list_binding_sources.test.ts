/**
 * Tool tests: list_binding_sources
 *
 * Covers:
 *   - Dispatch: no args, calls client.getBindingSources()
 *   - Response passthrough: sources[] / note
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handleDiscoveryTool } from '../../../tools/discovery.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('list_binding_sources — dispatch', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
  });

  it('calls client.getBindingSources() with no args', async () => {
    await handleDiscoveryTool('list_binding_sources', {}, client as any);
    expect(client.getBindingSources).toHaveBeenCalledWith();
  });

  it('passes through the sources array', async () => {
    const sources = [
      { name: 'core/post-meta', label: 'Post Meta', uses_context: ['postId', 'postType'] },
      { name: 'core/pattern-overrides', label: 'Pattern Overrides' },
    ];
    client.getBindingSources.mockResolvedValueOnce({ sources });
    const result = await handleDiscoveryTool('list_binding_sources', {}, client as any) as Record<string, unknown>;
    expect(result.sources).toEqual(sources);
  });

  it('passes through the pre-6.5 fallback note', async () => {
    client.getBindingSources.mockResolvedValueOnce({
      sources: [],
      note: 'Block bindings require WordPress 6.5+.',
    });
    const result = await handleDiscoveryTool('list_binding_sources', {}, client as any) as Record<string, unknown>;
    expect(result.sources).toEqual([]);
    expect(result.note).toBe('Block bindings require WordPress 6.5+.');
  });
});

describe('list_binding_sources — tool definition', () => {
  it('has no required args and read-only annotations', async () => {
    const { DISCOVERY_TOOLS } = await import('../../../tools/discovery.js');
    const tool = DISCOVERY_TOOLS.find((t) => t.name === 'list_binding_sources')!;
    expect(tool).toBeDefined();
    expect(tool.inputSchema.properties).toEqual({});
    expect(tool.annotations.readOnlyHint).toBe(true);
    expect(tool.annotations.destructiveHint).toBe(false);
  });
});
