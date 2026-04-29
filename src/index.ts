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
  ListPromptsRequestSchema,
  GetPromptRequestSchema,
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
    version: '1.4.0',
  },
  {
    capabilities: {
      tools: {},
      resources: {},
      prompts: {},
    },
    instructions:
      `Block-level WordPress CRUD. URL → post_id is resolved server-side — pass URLs directly to get_page_blocks / resolve_url; never shell out to curl or wp-json.

Tier policy is per-site config, surfaced inline (block.preference) and via list_block_types. Read block-mcp://agent-guide for the editing workflow.`,
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

// Renamed from `block-mcp://block-preferences` — the content is workflow
// guidance, not the live policy itself (which lives in wp_options and is
// surfaced via list_block_types + per-block `preference` annotations).
const AGENT_GUIDE_RESOURCE_URI = 'block-mcp://agent-guide';
// Legacy alias — kept in the resources list for one release so existing
// integrations resolving the old URI don't 404.
const LEGACY_PREFERENCES_RESOURCE_URI = 'block-mcp://block-preferences';

const AGENT_GUIDE_CONTENT = `# Block MCP — Agent Guide

## URL → post ID resolution

NEVER run curl, wget, or any bash/shell command to hit wp-json or resolve a URL to a post ID.
The MCP does this for you:

- \`get_page_blocks\` accepts \`url\` as an alternative to \`post_id\`. Pass the full URL or path; the server resolves it via \`url_to_postid\`.
- For explicit resolution (title, post_type, edit_url before editing), call \`resolve_url\`.

If the user says "change X on https://example.com/some-page/", your first tool call should be \`get_page_blocks({ url: "...", search: "keyword" })\` or \`resolve_url({ url: "..." })\` — not a shell command.

## Block preferences (site-defined)

Block preference policy is configured per-site in the WordPress admin (the
gk-block-api Preferences option) and exposed dynamically. There is no
client-side hardcoded list of "good" vs "bad" namespaces.

How to discover the policy at runtime:

1. \`list_block_types\` returns blocks grouped by tier (PREFERRED / ACCEPTABLE / AVOID / LEGACY) for the current site. Use this when you need the full picture.
2. \`get_page_blocks\` annotates non-preferred blocks inline with \`preference.tier\` and (when configured) \`preference.suggested_replacement\`. Trust those fields — they reflect the live config.
3. \`insert_blocks\` rejects legacy-tier blocks with a \`legacy_block\` error that includes the rejected namespace, the suggested replacement, and a pointer back to this resource.

How to behave:

- Prefer the highest-tier blocks for new content. Defer to the server's classification rather than guessing from a namespace prefix.
- Reuse existing patterns before building from scratch — call \`list_patterns\` first.
- For patterns that need per-page customization, use \`synced: false\` to inline them.
- When you encounter legacy blocks on a page during a read, note them but do not replace unless asked.`;

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

    // Emit `structuredContent` alongside the text fallback when the tool
    // declared an `outputSchema`. Clients that parse structured output
    // (MCP Inspector, programmatic agents) get typed data; LLM clients
    // still see the same JSON-stringified text.
    const toolDef = ALL_TOOLS.find((t) => t.name === name) as { outputSchema?: unknown } | undefined;
    const response: {
      content: Array<{ type: string; text: string }>;
      structuredContent?: unknown;
    } = {
      content: [
        { type: 'text', text: JSON.stringify(result, null, 2) },
      ],
    };
    if (toolDef && toolDef.outputSchema !== undefined && result !== null && typeof result === 'object') {
      response.structuredContent = result;
    }
    return response;
  } catch (error) {
    // Surface the full WordPress error envelope (code, data, status) when
    // the client decorated it. Critical for AI agents — `legacy_block`
    // carries `suggested_replacement`, `dual_storage_requires_both`
    // carries the policy_resource pointer, etc. Without forwarding `data`
    // the agent only sees the prose message and re-prompts blindly.
    const err = error as Error & {
      wpCode?: string;
      wpData?: unknown;
      wpStatus?: number;
      response?: { status?: number; data?: unknown };
    };

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(
            {
              error: true,
              tool: name,
              message: err.message || 'Unknown error occurred',
              code: err.wpCode,
              statusCode: err.wpStatus ?? err.response?.status,
              hint: err.wpData ?? null,
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
        uri: AGENT_GUIDE_RESOURCE_URI,
        name: 'Block MCP — Agent Guide',
        description:
          'Editing workflow + how to discover the live block-preference policy on this site. Read this before editing pages.',
        mimeType: 'text/plain',
      },
      // Legacy alias kept for one release; resolves to the same content.
      {
        uri: LEGACY_PREFERENCES_RESOURCE_URI,
        name: 'Block MCP — Agent Guide (legacy URI)',
        description: 'Renamed to block-mcp://agent-guide. Same content; kept for backwards compatibility.',
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

  if (uri === AGENT_GUIDE_RESOURCE_URI || uri === LEGACY_PREFERENCES_RESOURCE_URI) {
    return {
      contents: [
        { uri, mimeType: 'text/plain', text: AGENT_GUIDE_CONTENT },
      ],
    };
  }

  throw new Error(`Unknown resource: ${uri}`);
});

// ============================================
// Handler: Prompts
// ============================================
//
// Single canonical prompt — `edit-block-page` — that bundles the editing
// workflow + a one-shot reminder to call get_page_blocks first. Saves a
// tool call per session for clients that surface prompts in the UI.

const PROMPTS = [
  {
    name: 'edit-block-page',
    description:
      'Bundle: workflow guidance + reminder to call get_page_blocks first. Pass `url` to seed a specific page.',
    arguments: [
      {
        name: 'url',
        description: 'Optional. Full URL or path of the page being edited.',
        required: false,
      },
    ],
  },
];

server.server.setRequestHandler(ListPromptsRequestSchema, async () => ({
  prompts: PROMPTS,
}));

server.server.setRequestHandler(GetPromptRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  if (name !== 'edit-block-page') {
    throw new Error(`Unknown prompt: ${name}`);
  }
  const url = (args?.url as string | undefined) ?? '';
  const seed = url
    ? `Editing target: ${url}\n\nFirst tool call: get_page_blocks({ url: "${url}", summary_only: true }) for cheap orientation, then re-fetch with search/block_name filters as needed.\n\n`
    : '';
  return {
    description: 'Workflow primer for editing a WordPress page via block-mcp.',
    messages: [
      {
        role: 'user',
        content: {
          type: 'text',
          text: `${seed}${AGENT_GUIDE_CONTENT}`,
        },
      },
    ],
  };
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
