/**
 * Tool definitions handed to the AI for the eval harness.
 *
 * These mirror the real block-mcp tool descriptions verbatim (so the model
 * sees the same prose it would see in production). The handlers in
 * `runner.ts` dispatch tool_use calls to the in-memory FixtureStore.
 *
 * Subset only: `get_page_blocks`, `update_block`, `insert_blocks`,
 * `delete_block`. Add more here as scenarios require them.
 */

import { READ_TOOLS } from '../../../src/tools/read.js';
import { WRITE_TOOLS } from '../../../src/tools/write.js';

const KEEP = new Set([
  'get_page_blocks',
  'update_block',
  'insert_blocks',
  'delete_block',
  'replace_blocks',
]);

const SOURCE_TOOLS = [...READ_TOOLS, ...WRITE_TOOLS].filter((t) => KEEP.has(t.name));

/** Anthropic SDK tool definition shape. */
export const EVAL_TOOLS = SOURCE_TOOLS.map((t) => ({
  name: t.name,
  description: t.description,
  input_schema: t.inputSchema,
}));
