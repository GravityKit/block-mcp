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
 * Sub-commands:
 *   connect  — Browser-Approve handoff: opens WP admin authorize URL in the
 *              browser, receives credentials via loopback callback, writes the
 *              chosen AI client's MCP config. Credentials are never logged.
 *
 * @see AGENTS.md and docs/specs/ for architecture and endpoint documentation
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
// Read the version from package.json so npm/CI bumps automatically flow
// into the MCP handshake — keeps the runtime version in lockstep with the
// published release. esbuild inlines this at bundle time.
import pkg from '../package.json' with { type: 'json' };
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  ListResourcesRequestSchema,
  ReadResourceRequestSchema,
  ListPromptsRequestSchema,
  GetPromptRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { WordPressBlockClient } from './client.js';
import { getInstructions } from './instructions.js';
import { DISCOVERY_TOOLS, handleDiscoveryTool } from './tools/discovery.js';
import { READ_TOOLS, handleReadTool } from './tools/read.js';
import { WRITE_TOOLS, handleWriteTool } from './tools/write.js';
import { PATTERN_TOOLS, handlePatternTool } from './tools/patterns.js';
import { MUTATE_TOOLS, handleMutateTool } from './tools/mutate.js';
import { POST_TOOLS, handlePostTool } from './tools/posts.js';
import { TERM_TOOLS, handleTermTool } from './tools/terms.js';
import { MEDIA_TOOLS, handleMediaTool } from './tools/media.js';
import { YOAST_TOOLS, handleYoastTool } from './tools/yoast.js';
import { runConnect } from './connect.js';

// Environment variables are passed by the parent process (Claude Code, Hermes, etc.)
// No dotenv.config() needed — it breaks esbuild ESM bundles due to CJS dynamic require('fs').

// ============================================
// Initialize WordPress client
// ============================================

// Primary names (recommended): WORDPRESS_URL / WORDPRESS_USER / WORDPRESS_APP_PASSWORD.
// Fall back to the legacy GK_-prefixed names so existing configs keep working;
// emit a deprecation notice to stderr when they're used. The legacy names will
// be removed in a future minor release.
function readEnv(primary: string, legacy: string): string | undefined {
  const fromPrimary = process.env[primary];
  if (fromPrimary) return fromPrimary;
  const fromLegacy = process.env[legacy];
  if (fromLegacy) {
    console.error(`[block-mcp] DEPRECATED: ${legacy} is deprecated; rename to ${primary} in your MCP client config.`);
    return fromLegacy;
  }
  return undefined;
}

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

/**
 * Tool dispatch table — name → handler function.
 *
 * Built once at startup from each tool group's `*_TOOLS` array paired
 * with its `handle*Tool` dispatcher. A new tool added to any `*_TOOLS`
 * array is automatically routable; an unrouted tool name produces a
 * single point of failure (the Map lookup) instead of falling silently
 * through an `else if` chain to "Unknown tool".
 */
type ToolHandler = (
  name: string,
  args: Record<string, unknown>,
  client: WordPressBlockClient,
) => Promise<unknown>;

const TOOL_GROUPS: ReadonlyArray<{
  tools: ReadonlyArray<{ name: string }>;
  handle: ToolHandler;
}> = [
  { tools: DISCOVERY_TOOLS, handle: handleDiscoveryTool },
  { tools: READ_TOOLS,      handle: handleReadTool },
  { tools: WRITE_TOOLS,     handle: handleWriteTool },
  { tools: PATTERN_TOOLS,   handle: handlePatternTool },
  { tools: MUTATE_TOOLS,    handle: handleMutateTool },
  { tools: POST_TOOLS,      handle: handlePostTool },
  { tools: TERM_TOOLS,      handle: handleTermTool },
  { tools: MEDIA_TOOLS,     handle: handleMediaTool },
  { tools: YOAST_TOOLS,     handle: handleYoastTool },
];

const TOOL_DISPATCH = new Map<string, ToolHandler>();
for (const { tools, handle } of TOOL_GROUPS) {
  for (const tool of tools) {
    TOOL_DISPATCH.set(tool.name, handle);
  }
}

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

## Moving / reordering blocks

NEVER do a move as separate \`insert_blocks\` + \`delete_block\` calls — if the delete is skipped or fails, the page ends up with an orphaned clone of the original. The atomic primitive is the \`move\` op on \`edit_block_tree\`:

- Target the source with \`ref\` (the \`gk_ref\` from \`get_page_blocks\`) or \`path\`. Prefer \`ref\` — it survives sibling shifts; paths go stale the moment any earlier block is inserted or removed.
- Express the destination with \`destination_ref\` or \`destination\` (path). For path destinations, use **pre-move** indexing — write the path as if the source were still in place; the server adjusts indices after the removal.
- Use \`count\` to move N consecutive siblings in a single op.
- The server rejects moves into the source itself or any of its descendants.
- The whole \`edit_block_tree\` call is one revision, reversible via \`revert_to_revision\`.

If you must fall back to the flat-index tools, do \`insert_blocks\` + \`delete_block\` in the same turn and re-fetch \`get_page_blocks\` afterward to confirm exactly one copy remains.

## Verifying writes

Every write echoes the canonical post-save snapshot. Use it. Do not fetch the public page to verify what saved.

- \`update_block\` always returns \`saved.inner_html\` + \`saved.attributes\` — the exact content that just landed in post_content. The write call IS the verification round-trip.
- \`update_blocks\` returns per-result \`saved\` only when called with \`verbose: true\` (default false to keep batch responses compact). Pass \`verbose: true\` if you need to confirm each item without a re-read.
- For after-the-fact re-reads of a single known block, use \`get_block({ post_id, ref })\` — returns the same \`saved\` shape, lighter than \`get_page_blocks\`.

For dynamic blocks (\`saved.is_dynamic: true\`, e.g. shortcodes, query loops, latest-posts), \`saved.inner_html\` is the stored template that runs at render time — not the rendered HTML the visitor sees. That's expected; the canonical state is the template.

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
// Handler registration
//
// All request handlers run on the server passed in by main(). Keeping
// this in a function instead of running at module scope means the
// server can be constructed AFTER fetching the per-site instructions
// addendum (otherwise we'd have to mutate the SDK's private
// `_instructions` field — see the construction note above).
// ============================================

function registerHandlers(server: McpServer, client: WordPressBlockClient): void {

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
    const handle = TOOL_DISPATCH.get(name);
    if (!handle) {
      throw new Error(`Unknown tool: ${name}`);
    }
    const result = await handle(name, toolArgs, client);

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
    ? `Editing target: ${url}\n\nFirst tool call: get_page_blocks({ url: ${JSON.stringify(url)}, summary_only: true }) for cheap orientation, then re-fetch with search/block_name filters as needed.\n\n`
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

} // end registerHandlers

// ============================================
// Start the server
// ============================================

async function main(): Promise<void> {
  // ── Node version preflight ──────────────────────────────────────────────
  // engines.node already warns at install time, but a non-technical user who
  // runs the connector anyway should get a clear, actionable message rather than
  // a cryptic runtime crash on an unsupported Node.
  const nodeMajor = Number(process.versions.node.split('.')[0]);
  if (Number.isFinite(nodeMajor) && nodeMajor < 20) {
    console.error(
      `Block MCP requires Node.js 20 or newer — you are running ${process.version}. ` +
        'Please upgrade Node.js and try again: https://nodejs.org/'
    );
    process.exit(1);
  }

  // ── connect sub-command ─────────────────────────────────────────────────
  // Checked first so `npx @gravitykit/block-mcp connect` works without the
  // WORDPRESS_* env vars that the MCP server requires.
  if (process.argv[2] === 'connect') {
    await runConnect(process.argv.slice(3));
    process.exit(0);
  }

  // ── env-var validation ──────────────────────────────────────────────────
  const WORDPRESS_URL = readEnv('WORDPRESS_URL', 'GK_SITE_URL');
  const WORDPRESS_USER = readEnv('WORDPRESS_USER', 'GK_BLOCK_API_USER');
  const WORDPRESS_APP_PASSWORD = readEnv('WORDPRESS_APP_PASSWORD', 'GK_BLOCK_API_APP_PASSWORD');

  if (!WORDPRESS_URL || !WORDPRESS_USER || !WORDPRESS_APP_PASSWORD) {
    console.error(
      'Missing required environment variables: WORDPRESS_URL, WORDPRESS_USER, WORDPRESS_APP_PASSWORD'
    );
    process.exit(1);
  }

  const client = new WordPressBlockClient({
    wordpress_url: WORDPRESS_URL,
    auth: {
      username: WORDPRESS_USER,
      application_password: WORDPRESS_APP_PASSWORD,
    },
  });

  // Fetch the per-site instructions addendum BEFORE constructing the
  // server so the initialize handshake includes the combined string
  // from the start — no post-construction mutation of SDK internals.
  // `getInstructions` never throws: on any failure it logs to stderr
  // and returns the baseline only.
  const instructions = await getInstructions(WORDPRESS_URL);

  const server = new McpServer(
    {
      name: 'block-mcp',
      version: pkg.version,
    },
    {
      capabilities: {
        tools: {},
        resources: {},
        prompts: {},
      },
      instructions,
    }
  );

  registerHandlers(server, client);

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('Block MCP Server running on stdio');
}

main().catch((error) => {
  console.error('Fatal error starting Block MCP Server:', error);
  process.exit(1);
});
