/**
 * Manifest drift guard.
 *
 * wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json is a
 * generated, committed file: PHP reads it as static JSON at runtime and never
 * regenerates it (see scripts/export-abilities-manifest.mjs). A tool added,
 * removed, or re-annotated in src/tools/* without re-running the export
 * script leaves the manifest silently stale — a new tool never becomes a
 * WordPress ability, or a changed annotation (e.g. destructiveHint) ships
 * wrong to the Abilities API. This test fails CI in that case instead of
 * letting it ship quietly.
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import { buildManifest, MANIFEST_PATH } from '../scripts/export-abilities-manifest.mjs';

describe('tools.manifest.json matches the current npm tool definitions', () => {
  it('is exactly what scripts/export-abilities-manifest.mjs generates right now', () => {
    const generated = buildManifest();
    const committed = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
    expect(
      committed,
      'wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json is out of sync with src/tools/* — run `npx tsx scripts/export-abilities-manifest.mjs` and commit the result',
    ).toEqual(generated);
  });

  it('scopes yoast_get_seo to a per-post permission, not the blanket read permission', () => {
    const generated = buildManifest();
    const tool = generated.tools.find((t) => t.name === 'yoast_get_seo');
    expect(
      tool?.permission,
      'yoast_get_seo returns single-post SEO data; its REST twin (Yoast_Bridge::check_permissions) requires edit_post on the target post, not only the global read permission',
    ).toBe('edit_post');
  });

  it('includes the templates tool group', () => {
    const generated = buildManifest();
    const names = generated.tools.map((t) => t.name);
    expect(names).toEqual(
      expect.arrayContaining(['list_templates', 'get_template', 'update_template', 'reset_template']),
    );
  });

  it('scopes list_templates and get_template to the read permission', () => {
    const generated = buildManifest();
    for (const name of ['list_templates', 'get_template']) {
      const tool = generated.tools.find((t) => t.name === name);
      expect(tool?.permission, `${name} should use the blanket read permission`).toBe('read');
    }
  });

  it('scopes update_template and reset_template to a gated template_edit permission, not plain edit_post', () => {
    const generated = buildManifest();
    for (const name of ['update_template', 'reset_template']) {
      const tool = generated.tools.find((t) => t.name === name);
      expect(
        tool?.permission,
        `${name}'s REST twin is gated by the gk_block_api_template_edits toggle (Template_Manager::edits_enabled()) in addition to a capability check — the manifest permission must route Abilities_Registry::check_tool_permission() to that gate, not the ungated default 'edit_post' branch`,
      ).toBe('template_edit');
    }
  });
});
