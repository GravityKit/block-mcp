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

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(
  __dirname,
  '../wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json',
);

/** @type {ReadonlySet<string>} */
const MANAGE_OPTIONS = new Set(['scan_storage_modes']);

/** @type {ReadonlySet<string>} */
const UPLOAD = new Set(['upload_media']);

/** @type {ReadonlySet<string>} */
const CREATE = new Set(['create_post']);

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

function permissionFor(name, annotations) {
  if (MANAGE_OPTIONS.has(name)) return 'manage_options';
  if (UPLOAD.has(name)) return 'upload_files';
  if (CREATE.has(name)) return 'create_post';
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

const ALL = [
  ...DISCOVERY_TOOLS,
  ...READ_TOOLS,
  ...WRITE_TOOLS,
  ...PATTERN_TOOLS,
  ...MUTATE_TOOLS,
  ...POST_TOOLS,
  ...TERM_TOOLS,
  ...MEDIA_TOOLS,
  ...YOAST_TOOLS,
];

const manifest = {
  version: 1,
  namespace: 'gk-block-mcp',
  tools: ALL.map((tool) => {
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

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, `${JSON.stringify(manifest, null, 2)}\n`);
console.error(`Wrote ${manifest.tools.length} tools to ${OUT}`);
