/**
 * The agent-guide resource content (block-mcp://agent-guide).
 *
 * Workflow guidance served to MCP clients as a resource and prompt seed.
 * Lives in its own module (not index.ts, which boots the server on import)
 * so the discoverability contract is testable: everything an agent needs to
 * author content — including container nesting — must be learnable from the
 * tool surface + this guide, never from reading this repo's source.
 */

export const AGENT_GUIDE_CONTENT = `# Block MCP — Agent Guide

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

## Building container blocks (groups, columns, callouts)

Every block def accepted by \`insert_blocks\`, \`replace_block_range\`, and \`rewrite_post_blocks\` nests recursively via \`innerBlocks\` — build a whole container (and its children) in ONE call instead of inserting pieces and moving them around.

- The container's \`innerHTML\` is its wrapper element only — an empty wrapper; children render inside it. E.g. \`core/group\` → \`<div class="wp-block-group"></div>\`, \`core/list\` → \`<ul class="wp-block-list"></ul>\`.
- Each entry in \`innerBlocks\` is a full block def and may nest further (columns → column → content).
- Include any style class in BOTH the \`className\` attribute and the wrapper \`innerHTML\`.

Example — a styled callout (a \`core/group\` wrapping a paragraph):

\`\`\`json
{
  "name": "core/group",
  "attributes": { "className": "is-style-callout-info", "layout": { "type": "constrained" } },
  "innerHTML": "<div class=\\"wp-block-group is-style-callout-info\\"></div>",
  "innerBlocks": [
    { "name": "core/paragraph", "innerHTML": "<p>Tip text here.</p>" }
  ]
}
\`\`\`

Site-specific style conventions (e.g. callout class names) come from this site's instructions addendum — prefer those over inventing classes.

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

Before setting an \`is-style-*\` className, check \`list_block_types\` output's \`styles\` field for the valid variations on that block; respect \`parent\`/\`ancestor\`/\`allowed_blocks\` when nesting blocks so the insert doesn't land somewhere the editor would reject.

How to behave:

- Prefer the highest-tier blocks for new content. Defer to the server's classification rather than guessing from a namespace prefix.
- Reuse existing patterns before building from scratch — call \`list_patterns\` first.
- For patterns that need per-page customization, use \`synced: false\` to inline them.
- When you encounter legacy blocks on a page during a read, note them but do not replace unless asked.

## Templates (block themes)

\`list_templates\` and \`get_template\` are read-only. They browse a block theme's templates (page layouts) and template parts (reusable regions like header/footer), the same list the Site Editor shows. \`wp_id\` tells you whether a database override shadows the theme file: null means the id still resolves to the theme file itself; a number means a customization exists and identifies that override post. Templates are index-addressed only — the per-block write tools do not apply to template content.`;
