#!/usr/bin/env bash
# Copies the built MCP server bundle into the plugin so the .mcpb generator can embed it.
# Run from the repo root after `npm run build`.
set -euo pipefail
SRC="dist/index.cjs"
DEST="wordpress-plugin/gk-block-mcp/assets/mcp-server/index.cjs"
mkdir -p "$(dirname "$DEST")"
cp "$SRC" "$DEST"
echo "Copied $SRC -> $DEST"
