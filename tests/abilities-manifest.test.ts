/**
 * Manifest drift guard.
 *
 * wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json is a
 * generated, committed file: PHP reads it as static JSON at runtime and never
 * regenerates it (see scripts/export-abilities-manifest.mjs). A tool added,
 * removed, or re-annotated in src/tools/* without re-running the export
 * script leaves the manifest silently stale — a new tool never becomes a
 * WordPress ability, or a changed annotation (e.g. destructiveHint) ships
 * wrong to the Abilities API. This test fails CI in that case instead of
 * letting it ship quietly.
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import { buildManifest, MANIFEST_PATH } from '../scripts/export-abilities-manifest.mjs';

describe('tools.manifest.json matches the current npm tool definitions', () => {
  it('is exactly what scripts/export-abilities-manifest.mjs generates right now', () => {
    const generated = buildManifest();
    const committed = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
    expect(
      committed,
      'wordpress-plugin/gk-block-mcp/includes/abilities/tools.manifest.json is out of sync with src/tools/* — run `npx tsx scripts/export-abilities-manifest.mjs` and commit the result',
    ).toEqual(generated);
  });
});
