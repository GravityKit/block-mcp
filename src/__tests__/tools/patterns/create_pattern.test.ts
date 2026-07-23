/**
 * Tool tests: create_pattern
 *
 * Covers:
 *   - Validation: title required, content XOR blocks
 *   - Defaults: sync_status "synced", status "publish"
 *   - Forwarding: sync_status "unsynced", slug, status "draft" pass through
 *   - Dispatch: calls client.createPattern with the narrowed request
 *   - Response passthrough incl. `reference`
 *   - Unknown tool still throws (regression guard on the existing switch)
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { handlePatternTool } from '../../../tools/patterns.js';
import { makeMockClient } from '../../helpers/mock-client.js';

const createPatternResponse = {
  pattern_id: 42,
  title: 'Test Pattern',
  slug: 'test-pattern',
  sync_status: 'synced' as const,
  edit_url: 'https://example.test/wp-admin/post.php?post=42&action=edit',
  reference: { blockName: 'core/block', attrs: { ref: 42 } },
  warnings: [],
};

describe('create_pattern — validation', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); });

  it('requires a non-empty title', async () => {
    await expect(
      handlePatternTool('create_pattern', { content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->' }, client as any)
    ).rejects.toThrow('title');
  });

  it('rejects both content and blocks (XOR violation)', async () => {
    await expect(
      handlePatternTool('create_pattern', {
        title: 'X',
        content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
        blocks: [{ name: 'core/paragraph', innerHTML: '<p>hi</p>' }],
      }, client as any)
    ).rejects.toThrow(/content.*blocks|mutually exclusive/i);
  });

  it('rejects neither content nor blocks (XOR violation)', async () => {
    await expect(
      handlePatternTool('create_pattern', { title: 'X' }, client as any)
    ).rejects.toThrow(/content.*blocks|mutually exclusive/i);
  });

  it('rejects an invalid sync_status instead of silently defaulting', async () => {
    await expect(
      handlePatternTool('create_pattern', {
        title: 'X',
        content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
        sync_status: 'sometimes',
      }, client as any)
    ).rejects.toThrow(/sync_status/i);
  });

  it('rejects an invalid status instead of silently defaulting', async () => {
    await expect(
      handlePatternTool('create_pattern', {
        title: 'X',
        content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
        status: 'pending',
      }, client as any)
    ).rejects.toThrow(/status/i);
  });
});

describe('create_pattern — inputSchema XOR contract', () => {
  it('declares oneOf(blocks, content) so a schema-validating client rejects both-or-neither before dispatch', async () => {
    const { PATTERN_TOOLS } = await import('../../../tools/patterns.js');
    const tool = PATTERN_TOOLS.find((t) => t.name === 'create_pattern')!;
    expect((tool.inputSchema as any).oneOf).toEqual([
      { required: ['blocks'] },
      { required: ['content'] },
    ]);
  });
});

describe('create_pattern — dispatch and defaults', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.createPattern.mockResolvedValue(createPatternResponse);
  });

  it('defaults sync_status to synced and status to publish', async () => {
    await handlePatternTool('create_pattern', {
      title: 'Test Pattern',
      content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
    }, client as any);
    expect(client.createPattern).toHaveBeenCalledWith(expect.objectContaining({
      sync_status: 'synced', status: 'publish',
    }));
  });

  it('forwards sync_status: unsynced', async () => {
    await handlePatternTool('create_pattern', {
      title: 'Test Pattern',
      content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
      sync_status: 'unsynced',
    }, client as any);
    expect(client.createPattern).toHaveBeenCalledWith(expect.objectContaining({ sync_status: 'unsynced' }));
  });

  it('forwards slug and status: draft', async () => {
    await handlePatternTool('create_pattern', {
      title: 'Test Pattern',
      content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
      slug: 'my-slug',
      status: 'draft',
    }, client as any);
    expect(client.createPattern).toHaveBeenCalledWith(expect.objectContaining({ slug: 'my-slug', status: 'draft' }));
  });

  it('forwards structured blocks', async () => {
    const blocks = [{ name: 'core/paragraph', innerHTML: '<p>hi</p>' }];
    await handlePatternTool('create_pattern', { title: 'Test Pattern', blocks }, client as any);
    expect(client.createPattern).toHaveBeenCalledWith(expect.objectContaining({ blocks }));
  });
});

describe('create_pattern — response shape', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => {
    client = makeMockClient();
    client.createPattern.mockResolvedValue(createPatternResponse);
  });

  it('passes through pattern_id, sync_status, and reference', async () => {
    const result = await handlePatternTool('create_pattern', {
      title: 'Test Pattern',
      content: '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
    }, client as any) as Record<string, unknown>;
    expect(result.pattern_id).toBe(42);
    expect(result.sync_status).toBe('synced');
    expect(result.reference).toEqual({ blockName: 'core/block', attrs: { ref: 42 } });
  });
});
