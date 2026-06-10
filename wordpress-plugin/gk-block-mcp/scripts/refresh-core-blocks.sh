#!/usr/bin/env bash
#
# Refresh tests/fixtures/core-blocks/ from the WordPress/gutenberg repo.
#
# The conformance test in tests/Block/CoreBlocksConformanceTest.php iterates
# every block.json under tests/fixtures/core-blocks/ and asserts the
# insert_blocks source-bound guard behaves correctly for each. The fixtures
# are tracked in git so the test runs offline; this script rebuilds them
# from the upstream Gutenberg repo when you want to pick up new blocks or
# schema changes.
#
# Usage:
#   ./scripts/refresh-core-blocks.sh           # pull from trunk (default)
#   ./scripts/refresh-core-blocks.sh <ref>     # pull from a specific tag/sha
#
# Composer alias: `composer refresh-core-blocks`.

set -euo pipefail

REF="${1:-trunk}"
REPO_URL="https://github.com/WordPress/gutenberg.git"

# Resolve directory of this script regardless of cwd.
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"
FIXTURES_DIR="$PLUGIN_DIR/tests/fixtures/core-blocks"

# Use a tmp dir that survives only the run; remove on exit.
TMP_DIR="$( mktemp -d -t gk-core-blocks-XXXX )"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "==> Refreshing core-blocks fixtures from $REF"
echo "    Repo:     $REPO_URL"
echo "    Target:   $FIXTURES_DIR"

# Sparse + blobless clone so we transfer only what we need.
git clone \
    --depth 1 \
    --branch "$REF" \
    --filter=blob:none \
    --sparse \
    "$REPO_URL" \
    "$TMP_DIR/gutenberg" >/dev/null 2>&1

(
    cd "$TMP_DIR/gutenberg"
    git sparse-checkout set packages/block-library/src >/dev/null
)

# Record the commit sha so failures can be reproduced against the same tree.
GIT_SHA=$( cd "$TMP_DIR/gutenberg" && git rev-parse HEAD )

# Wipe the existing fixtures and copy only block.json files into a
# per-block directory matching the upstream layout.
rm -rf "$FIXTURES_DIR"
mkdir -p "$FIXTURES_DIR"

count=0
while IFS= read -r src; do
    block_dir=$(dirname "$src")
    block_name=$(basename "$block_dir")
    target="$FIXTURES_DIR/$block_name"
    mkdir -p "$target"
    cp "$src" "$target/block.json"
    count=$((count + 1))
done < <(find "$TMP_DIR/gutenberg/packages/block-library/src" -name "block.json" -type f)

# Stamp the snapshot we pulled so reviewers know what's in the fixtures.
cat > "$FIXTURES_DIR/README.md" <<README
# Core block fixtures

Mirror of \`packages/block-library/src/*/block.json\` from the
[\`WordPress/gutenberg\`](https://github.com/WordPress/gutenberg) repo.

- **Snapshot ref:** \`$REF\`
- **Commit:** \`$GIT_SHA\`
- **Block count:** $count
- **Refreshed by:** \`scripts/refresh-core-blocks.sh\`

Used by \`tests/Block/CoreBlocksConformanceTest.php\` to assert that the
\`insert_blocks\` source-bound guard behaves correctly for every core block.

Do not edit by hand. Re-run the script to update.
README

echo "==> Wrote $count block.json files."
echo "==> Snapshot: $REF ($GIT_SHA)"

# Pull the block.json meta-schema (the spec block.json files conform to)
# so the suite has an offline copy. Used by future structural validation
# and as a reference for the source-value enum that drives the guard's
# allow-list. The schema is hosted on schemas.wp.org and is the
# canonical source of truth.
SCHEMA_DEST="$FIXTURES_DIR/block-schema.json"
echo "==> Fetching block.json meta-schema → $SCHEMA_DEST"
if command -v curl >/dev/null 2>&1; then
    curl -fsSL "https://schemas.wp.org/trunk/block.json" -o "$SCHEMA_DEST" || \
        echo "    WARNING: meta-schema fetch failed; continuing without it."
elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$SCHEMA_DEST" "https://schemas.wp.org/trunk/block.json" || \
        echo "    WARNING: meta-schema fetch failed; continuing without it."
else
    echo "    WARNING: neither curl nor wget available; skipping meta-schema fetch."
fi
