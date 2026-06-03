/**
 * Packaging / distribution guards.
 *
 * The connector writes client configs that launch the server with
 * `npx -y @gravitykit/block-mcp`, and the connect flow itself is run as
 * `npx -y @gravitykit/block-mcp connect …`. For npx to resolve an executable,
 * the package MUST declare a `bin`, ship the built `dist/`, and the built entry
 * must carry a node shebang. These guards pin that the package stays
 * npx-runnable so the distribution doesn't silently break.
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')) as {
  bin?: string | Record<string, string>;
  files?: string[];
  main?: string;
};

describe('package is runnable via npx', () => {
  it('declares a bin so `npx @gravitykit/block-mcp` resolves an executable', () => {
    expect(pkg.bin).toBeTruthy();
    const targets = typeof pkg.bin === 'string' ? [pkg.bin] : Object.values(pkg.bin ?? {});
    expect(targets).toContain('dist/index.cjs');
  });

  it('exposes a block-mcp command pointing at the built entry', () => {
    const bin = pkg.bin;
    expect(typeof bin === 'object' && bin !== null && bin['block-mcp']).toBe('dist/index.cjs');
  });

  it('ships dist/ in the published files allowlist', () => {
    expect(pkg.files).toContain('dist/');
  });

  it('the built entry starts with a node shebang so it runs as a bin', () => {
    const dist = fs.readFileSync(path.join(root, 'dist/index.cjs'), 'utf8');
    expect(dist.startsWith('#!/usr/bin/env node')).toBe(true);
  });
});

describe('plugin-embedded server bundle stays in sync with dist', () => {
  const distPath = path.join(root, 'dist/index.cjs');
  const assetPath = path.join(root, 'wordpress-plugin/gk-block-api/assets/mcp-server/index.cjs');

  it('the embedded connector bundle is byte-identical to dist/index.cjs', () => {
    // postbuild (scripts/copy-server-bundle.sh) copies dist/index.cjs into the
    // plugin so the .mcpb generator embeds the SAME server the npx path runs.
    // If the two drift, Claude Desktop one-click users get a stale connector
    // while npx users get the current one. Pin byte-equality so a build that
    // skipped the copy — or a hand-edit of either copy — fails here.
    expect(fs.existsSync(distPath), 'dist/index.cjs must be built and committed').toBe(true);
    expect(fs.existsSync(assetPath), 'assets/mcp-server/index.cjs must be committed').toBe(true);
    const dist = fs.readFileSync(distPath);
    const asset = fs.readFileSync(assetPath);
    expect(
      asset.equals(dist),
      'assets/mcp-server/index.cjs is out of sync with dist/index.cjs — run `npm run build`',
    ).toBe(true);
  });
});
