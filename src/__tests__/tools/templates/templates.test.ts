/**
 * Tool tests: list_templates, get_template
 *
 * Covers:
 *   - Schema: both tools exposed with readOnlyHint annotations
 *   - Filter forwarding: list_templates (type, area, post_type, slug, source)
 *   - get_template: id required, type forwarded, response passthrough
 *   - Unknown tool throws
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { TEMPLATE_TOOLS, handleTemplateTool } from '../../../tools/templates.js';
import { makeMockClient } from '../../helpers/mock-client.js';

describe('template tools — schema', () => {
  it('exposes list_templates and get_template', () => {
    expect(TEMPLATE_TOOLS.map((t) => t.name)).toEqual(
      expect.arrayContaining(['list_templates', 'get_template'])
    );
  });

  it('both tools are read-only, non-destructive, idempotent', () => {
    for (const tool of TEMPLATE_TOOLS) {
      expect(tool.annotations.readOnlyHint).toBe(true);
      expect(tool.annotations.destructiveHint).toBe(false);
      expect(tool.annotations.idempotentHint).toBe(true);
    }
  });

  it('get_template requires id', () => {
    const tool = TEMPLATE_TOOLS.find((t) => t.name === 'get_template')!;
    expect(tool.inputSchema.required).toEqual(['id']);
  });
});

describe('list_templates', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('passes empty args through (server defaults type to wp_template)', async () => {
    await handleTemplateTool('list_templates', {}, client as any);
    expect(client.getTemplates).toHaveBeenCalledWith({
      type: undefined, area: undefined, post_type: undefined, slug: undefined, source: undefined,
    });
  });

  it('forwards type + area for template parts', async () => {
    await handleTemplateTool('list_templates', { type: 'wp_template_part', area: 'header' }, client as any);
    expect(client.getTemplates).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'wp_template_part', area: 'header' })
    );
  });

  it('forwards post_type, slug, and source filters', async () => {
    await handleTemplateTool('list_templates', {
      post_type: 'page', slug: 'single,page', source: 'custom',
    }, client as any);
    expect(client.getTemplates).toHaveBeenCalledWith(
      expect.objectContaining({ post_type: 'page', slug: 'single,page', source: 'custom' })
    );
  });

  it('returns the client response verbatim', async () => {
    client.getTemplates.mockResolvedValue({
      templates: [{ id: 't//index', slug: 'index', wp_id: null }],
      count: 1,
    });
    const result = await handleTemplateTool('list_templates', {}, client as any) as any;
    expect(result.count).toBe(1);
    expect(result.templates[0].slug).toBe('index');
  });

  it('surfaces the classic-theme note untouched', async () => {
    client.getTemplates.mockResolvedValue({
      templates: [],
      count: 0,
      note: 'Active theme is not a block theme; no block templates exist.',
    });
    const result = await handleTemplateTool('list_templates', {}, client as any) as any;
    expect(result.note).toBe('Active theme is not a block theme; no block templates exist.');
  });
});

describe('get_template', () => {
  let client: ReturnType<typeof makeMockClient>;
  beforeEach(() => { client = makeMockClient(); vi.clearAllMocks(); });

  it('rejects a missing id before calling the client', async () => {
    await expect(handleTemplateTool('get_template', {}, client as any)).rejects.toThrow(/id/i);
    expect(client.getTemplate).not.toHaveBeenCalled();
  });

  it('rejects an empty-string id', async () => {
    await expect(handleTemplateTool('get_template', { id: '' }, client as any)).rejects.toThrow(/id/i);
  });

  it('forwards id and type to the client', async () => {
    await handleTemplateTool('get_template', { id: 'twentytwentyfive//single', type: 'wp_template' }, client as any);
    expect(client.getTemplate).toHaveBeenCalledWith('twentytwentyfive//single', 'wp_template');
  });

  it('omits type when not provided', async () => {
    await handleTemplateTool('get_template', { id: 'twentytwentyfive//index' }, client as any);
    expect(client.getTemplate).toHaveBeenCalledWith('twentytwentyfive//index', undefined);
  });

  it('returns content and blocks from the client response', async () => {
    client.getTemplate.mockResolvedValue({
      id: 't//index', slug: 'index', theme: 't', type: 'wp_template',
      title: 'Index', description: '', source: 'theme', origin: null, status: 'publish',
      has_theme_file: true, is_custom: true, wp_id: null,
      content: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
      blocks: [{ index: 0, name: 'core/paragraph', attributes: {} }],
    });
    const result = await handleTemplateTool('get_template', { id: 't//index' }, client as any) as any;
    expect(result.content).toContain('Hi');
    expect(result.blocks).toHaveLength(1);
  });
});

describe('dispatch', () => {
  it('throws on an unknown template tool name', async () => {
    const client = makeMockClient();
    await expect(handleTemplateTool('not_a_real_tool', {}, client as any)).rejects.toThrow(/Unknown template tool/);
  });
});
