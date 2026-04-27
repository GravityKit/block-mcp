#!/usr/bin/env node

/**
 * GravityKit Block MCP Server
 *
 * Model Context Protocol server for WordPress block-level content management.
 * Gives AI agents full block-level CRUD with smart preference-aware guidance:
 *
 * - Discover available block types and patterns with preference scoring
 * - Read page blocks as structured JSON with legacy-block annotations
 * - Surgically edit single blocks without rewriting entire post_content
 * - Insert patterns (synced or inline) from the site's pattern library
 * - Full page rewrites with validation and revision tracking
 *
 * Connects to the gk-block-api WordPress plugin via REST API with
 * Application Password authentication.
 *
 * @see AGENTS.md and docs/specs/ for architecture and endpoint documentation
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { WordPressBlockClient } from './client.js';
import { DISCOVERY_TOOLS, handleDiscoveryTool } from './tools/discovery.js';
import { READ_TOOLS, handleReadTool } from './tools/read.js';
import { WRITE_TOOLS, handleWriteTool } from './tools/write.js';
import { PATTERN_TOOLS, handlePatternTool } from './tools/patterns.js';
import { MUTATE_TOOLS, handleMutateTool } from './tools/mutate.js';
import { POST_TOOLS, handlePostTool } from './tools/posts.js';
import { TERM_TOOLS, handleTermTool } from './tools/terms.js';
import { MEDIA_TOOLS, handleMediaTool } from './tools/media.js';
import { YOAST_TOOLS, handleYoastTool } from './tools/yoast.js';

// Environment variables are passed by the parent process (Claude Code, Hermes, etc.)
// No dotenv.config() needed — it breaks esbuild ESM bundles due to CJS dynamic require('fs').

// ============================================
// Initialize WordPress client
// ============================================

const GK_SITE_URL = process.env.GK_SITE_URL;
const GK_BLOCK_API_USER = process.env.GK_BLOCK_API_USER;
const GK_BLOCK_API_APP_PASSWORD = process.env.GK_BLOCK_API_APP_PASSWORD;

if (!GK_SITE_URL || !GK_BLOCK_API_USER || !GK_BLOCK_API_APP_PASSWORD) {
  console.error(
    'Missing required environment variables: GK_SITE_URL, GK_BLOCK_API_USER, GK_BLOCK_API_APP_PASSWORD'
  );
  process.exit(1);
}

const client = new WordPressBlockClient({
  wordpress_url: GK_SITE_URL,
  auth: {
    username: GK_BLOCK_API_USER,
    application_password: GK_BLOCK_API_APP_PASSWORD,
  },
});

// ============================================
// Create MCP server
// ============================================

const server = new McpServer(
  {
    name: 'block-mcp',
    version: '1.3.0',
  },
  {
    capabilities: {
      tools: {},
      resources: {},
    },
    instructions: `Block-level WordPress CRUD for gravitykit.com.

URL → post ID: when the user gives you a URL (e.g. https://www.gravitykit.com/products/gravityedit/), DO NOT shell out to curl, wp-json, the REST API, or any bash command to look up the post ID. Pass the URL directly:

- get_page_blocks accepts \`url\` as an alternative to \`post_id\` — the server resolves it internally.
- For explicit resolution (e.g. to surface title/post_type before editing), call \`resolve_url\`.

Editing workflow: given "change text X on URL Y", go straight to get_page_blocks({ url: Y, search: "keyword" }) → update_block / mutate_block_tree. Do not ask the user for a post ID, and do not look it up yourself via shell.

Block preferences (read the block-mcp://block-preferences resource for details): prefer \`filter/\` and \`core/\`; never insert \`stackable/\`, \`ugb/\`, or \`jetpack/\` blocks.`,
  }
);

// ============================================
// Aggregate all tool definitions
// ============================================

const ALL_TOOLS = [
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

/** Set of discovery tool names for routing. */
const DISCOVERY_TOOL_NAMES = new Set(DISCOVERY_TOOLS.map((t) => t.name));

/** Set of read tool names for routing. */
const READ_TOOL_NAMES = new Set(READ_TOOLS.map((t) => t.name));

/** Set of write tool names for routing. */
const WRITE_TOOL_NAMES = new Set(WRITE_TOOLS.map((t) => t.name));

/** Set of pattern tool names for routing. */
const PATTERN_TOOL_NAMES = new Set(PATTERN_TOOLS.map((t) => t.name));

/** Set of mutate tool names for routing. */
const MUTATE_TOOL_NAMES = new Set(MUTATE_TOOLS.map((t) => t.name));

/** v1.2 — post lifecycle tool names. */
const POST_TOOL_NAMES = new Set(POST_TOOLS.map((t) => t.name));

/** v1.2 — term tool names. */
const TERM_TOOL_NAMES = new Set(TERM_TOOLS.map((t) => t.name));

/** v1.2 — media tool names. */
const MEDIA_TOOL_NAMES = new Set(MEDIA_TOOLS.map((t) => t.name));

/** v1.2 — yoast tool names. */
const YOAST_TOOL_NAMES = new Set(YOAST_TOOLS.map((t) => t.name));

// ============================================
// System prompt context resource
// ============================================

const BLOCK_PREFERENCES_RESOURCE_URI = 'block-mcp://block-preferences';

const BLOCK_PREFERENCES_CONTENT = `# GravityKit Block MCP — Agent Guide

## URL → post ID resolution

NEVER run curl, wget, or any bash/shell command to hit wp-json or resolve a URL to a post ID.
The MCP does this for you:

- \`get_page_blocks\` accepts \`url\` as an alternative to \`post_id\`. Pass the full URL or path; the server resolves it via \`url_to_postid\`.
- For explicit resolution (title, post_type, edit_url before editing), call \`resolve_url\`.

If the user says "change X on https://www.gravitykit.com/products/gravityedit/", your first tool call should be \`get_page_blocks({ url: "...", search: "keyword" })\` or \`resolve_url({ url: "..." })\` — not a shell command.

## Block preferences

When editing pages on gravitykit.com:

1. ALWAYS prefer \`filter/\` (theme) blocks over alternatives
2. Use \`core/\` blocks for standard content (headings, paragraphs, images, buttons)
3. NEVER use \`stackable/\`, \`ugb/\`, or \`jetpack/\` blocks — they are legacy
4. When inserting content, check patterns first — reuse existing patterns before building from scratch
5. Prefer synced patterns to keep content consistent across pages
6. When inserting a pattern that needs per-page customization, use \`synced: false\` to inline it
7. When you encounter legacy blocks on a page, note them but do not replace unless asked`;

// ============================================
// Handler: List tools
// ============================================

server.server.setRequestHandler(ListToolsRequestSchema, async () => {
  return { tools: ALL_TOOLS };
});

// ============================================
// Handler: Call tool
// ============================================

server.server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const toolArgs = (args ?? {}) as Record<string, unknown>;

  try {
    let result: unknown;

    if (DISCOVERY_TOOL_NAMES.has(name)) {
      result = await handleDiscoveryTool(name, toolArgs, client);
    } else if (READ_TOOL_NAMES.has(name)) {
      result = await handleReadTool(name, toolArgs, client);
    } else if (WRITE_TOOL_NAMES.has(name)) {
      result = await handleWriteTool(name, toolArgs, client);
    } else if (PATTERN_TOOL_NAMES.has(name)) {
      result = await handlePatternTool(name, toolArgs, client);
    } else if (MUTATE_TOOL_NAMES.has(name)) {
      result = await handleMutateTool(name, toolArgs, client);
    } else if (POST_TOOL_NAMES.has(name)) {
      result = await handlePostTool(name, toolArgs, client);
    } else if (TERM_TOOL_NAMES.has(name)) {
      result = await handleTermTool(name, toolArgs, client);
    } else if (MEDIA_TOOL_NAMES.has(name)) {
      result = await handleMediaTool(name, toolArgs, client);
    } else if (YOAST_TOOL_NAMES.has(name)) {
      result = await handleYoastTool(name, toolArgs, client);
    } else {
      throw new Error(`Unknown tool: ${name}`);
    }

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(result, null, 2),
        },
      ],
    };
  } catch (error) {
    const err = error as Error & { response?: { status?: number; data?: unknown } };
    const errorMessage = err.message || 'Unknown error occurred';
    const statusCode = err.response?.status;

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(
            {
              error: true,
              message: errorMessage,
              statusCode: statusCode,
              tool: name,
            },
            null,
            2
          ),
        },
      ],
      isError: true,
    };
  }
});

// ============================================
// Handler: List resources
// ============================================

server.server.setRequestHandler(ListResourcesRequestSchema, async () => {
  return {
    resources: [
      {
        uri: BLOCK_PREFERENCES_RESOURCE_URI,
        name: 'GravityKit Block Preferences',
        description:
          'System prompt context describing block preference rules for gravitykit.com. ' +
          'Read this before editing any pages to understand which blocks to use and avoid.',
        mimeType: 'text/plain',
      },
    ],
  };
});

// ============================================
// Handler: Read resource
// ============================================

server.server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
  const { uri } = request.params;

  if (uri === BLOCK_PREFERENCES_RESOURCE_URI) {
    return {
      contents: [
        {
          uri: BLOCK_PREFERENCES_RESOURCE_URI,
          mimeType: 'text/plain',
          text: BLOCK_PREFERENCES_CONTENT,
        },
      ],
    };
  }

  throw new Error(`Unknown resource: ${uri}`);
});

// ============================================
// Start the server
// ============================================

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('Block MCP Server running on stdio');
}

main().catch((error) => {
  console.error('Fatal error starting Block MCP Server:', error);
  process.exit(1);
});
