#!/usr/bin/env node
/**
 * Export MCP tool definitions to a JSON manifest consumed by the WordPress
 * plugin's Abilities API registration. Keeps input schemas in sync with the
 * npm MCP server without duplicating them by hand in PHP.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { DISCOVERY_TOOLS } from '../src/tools/discovery.js';
import { READ_TOOLS } from '../src/tools/read.js';
import { WRITE_TOOLS } from '../src/tools/write.js';
import { PATTERN_TOOLS } from '../src/tools/patterns.js';
import { MUTATE_TOOLS } from '../src/tools/mutate.js';
import { POST_TOOLS } from '../src/tools/posts.js';
import { TERM_TOOLS } from '../src/tools/terms.js';
import { MEDIA_TOOLS } from '../src/tools/media.js';
import { YOAST_TOOLS } from '../src/tools/yoast.js';
import { TEMPLATE_TOOLS } from '../src/tools/templates.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/** Path to the committed manifest this script writes (and the drift-guard test reads). */
export const MANIFEST_PATH = path.join(
  __dirname,
  '../wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json',
);

/** @type {ReadonlySet<string>} */
const MANAGE_OPTIONS = new Set(['scan_storage_modes']);

/** @type {ReadonlySet<string>} */
const UPLOAD = new Set(['upload_media']);

/** @type {ReadonlySet<string>} */
const CREATE = new Set(['create_post']);

/**
 * create_pattern's REST twin has a dedicated permission callback
 * (REST_Controller::check_create_pattern_permissions()) that checks the base
 * edit_posts capability AND the wp_block post type's create_posts capability
 * (which core maps to publish_posts, not edit_posts) — a Contributor has the
 * former but not the latter. Mapped to its own permission key so
 * Abilities_Registry::check_tool_permission() routes to that exact callback
 * instead of the weaker default 'edit_post' branch, which checks only
 * edit_posts (plus an optional per-post check that doesn't apply here, since
 * create_pattern has no target post_id).
 *
 * @type {ReadonlySet<string>}
 */
const CREATE_PATTERN = new Set(['create_pattern']);

/**
 * Template writes, gated by the site's own toggle (Template_Manager::edits_enabled(),
 * option gk_block_api_template_edits + filter gk/block-mcp/templates/allow-edits)
 * in addition to a capability check. Mapped to their own permission key so
 * Abilities_Registry::check_tool_permission() can route them to
 * check_template_edit_permissions() instead of the ungated default 'edit_post'
 * branch — the same gate their REST twins (POST /template, POST /template/reset)
 * enforce.
 *
 * @type {ReadonlySet<string>}
 */
const TEMPLATE_EDIT = new Set(['update_template', 'reset_template']);

/** @type {ReadonlySet<string>} */
const READ = new Set([
  'list_block_types',
  'list_patterns',
  'get_pattern',
  'get_site_usage',
  'resolve_url',
  'list_posts',
  'get_post_info',
  'get_page_blocks',
  'get_block',
  'list_terms',
  'yoast_get_seo',
]);

/**
 * Read-only tools whose data is scoped to a single post, so the blanket
 * global `read` permission (any caller with edit_posts) is too broad — the
 * REST twin these abilities delegate to enforces a per-post edit_post check
 * instead. Checked before the READ/readOnlyHint branch so membership here
 * overrides it.
 *
 * @type {ReadonlySet<string>}
 */
const PER_POST_READ = new Set(['yoast_get_seo']);

function permissionFor(name, annotations) {
  if (MANAGE_OPTIONS.has(name)) return 'manage_options';
  if (UPLOAD.has(name)) return 'upload_files';
  if (CREATE.has(name)) return 'create_post';
  if (CREATE_PATTERN.has(name)) return 'create_pattern';
  if (TEMPLATE_EDIT.has(name)) return 'template_edit';
  if (PER_POST_READ.has(name)) return 'edit_post';
  if (READ.has(name) || annotations?.readOnlyHint === true) return 'read';
  return 'edit_post';
}

function toAbilitySlug(name) {
  return name.replace(/_/g, '-');
}

function humanLabel(name) {
  return name
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

/**
 * Plugin-only abilities with no npm MCP server equivalent. Defined here
 * (rather than sourced from src/tools/*.ts) so a manifest regenerate does
 * not drop them.
 *
 * @type {ReadonlyArray<{name: string, description: string, annotations: Record<string, unknown>, inputSchema: Record<string, unknown>}>}
 */
const EXTRA_TOOLS = [
  {
    name: 'site_editor_context',
    description:
      "Get the site's design tokens (theme name plus the color, gradient, font-size, and spacing presets) so block markup references theme-aligned preset slugs (e.g. has-primary-color) rather than hard-coded values.",
    annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, title: 'Site editor context' },
    inputSchema: { type: 'object', default: {} },
  },
];

/**
 * Build the abilities manifest object from the current npm tool definitions
 * plus the plugin-only EXTRA_TOOLS. Shared by the CLI export below and the
 * manifest drift-guard test (tests/abilities-manifest.test.ts) so both walk
 * the identical generation logic — a test that duplicated this mapping by
 * hand could drift from it independently of the committed JSON.
 *
 * @returns {{version: number, namespace: string, tools: Array<Record<string, unknown>>}}
 */
export function buildManifest() {
  const all = [
    ...DISCOVERY_TOOLS,
    ...READ_TOOLS,
    ...WRITE_TOOLS,
    ...PATTERN_TOOLS,
    ...MUTATE_TOOLS,
    ...POST_TOOLS,
    ...TERM_TOOLS,
    ...MEDIA_TOOLS,
    ...YOAST_TOOLS,
    ...TEMPLATE_TOOLS,
    ...EXTRA_TOOLS,
  ];

  return {
    version: 1,
    namespace: 'gk-block-mcp',
    tools: all.map((tool) => {
      const annotations = tool.annotations ?? {};
      return {
        name: tool.name,
        ability: `gk-block-mcp/${toAbilitySlug(tool.name)}`,
        label: annotations.title ?? humanLabel(tool.name),
        description: tool.description,
        input_schema: tool.inputSchema ?? { type: 'object', properties: {} },
        output_schema: tool.outputSchema ?? { type: 'object' },
        permission: permissionFor(tool.name, annotations),
        annotations: {
          readonly: annotations.readOnlyHint === true,
          destructive: annotations.destructiveHint === true,
          idempotent: annotations.idempotentHint === true,
        },
      };
    }),
  };
}

// CLI entry point only — guarded so importing this module (e.g. from the
// drift-guard test) doesn't have the side effect of overwriting the
// committed manifest.
const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  const manifest = buildManifest();
  fs.mkdirSync(path.dirname(MANIFEST_PATH), { recursive: true });
  fs.writeFileSync(MANIFEST_PATH, `${JSON.stringify(manifest, null, 2)}\n`);
  console.error(`Wrote ${manifest.tools.length} tools to ${MANIFEST_PATH}`);
}
