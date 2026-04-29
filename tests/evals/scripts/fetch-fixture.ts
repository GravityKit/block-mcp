#!/usr/bin/env -S node --import=tsx
/**
 * One-time fixture fetcher.
 *
 * Pulls `get_page_blocks` JSON for a list of pages from the live block-mcp
 * REST endpoint, then writes them to tests/evals/fixtures/<slug>.json.
 *
 * After this runs, the eval harness reads from disk only — no live connection
 * is needed at eval time.
 *
 * Usage:
 *   GK_SITE_URL=... GK_BLOCK_API_USER=... GK_BLOCK_API_APP_PASSWORD=... \
 *     tsx tests/evals/scripts/fetch-fixture.ts
 */

import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { WordPressBlockClient } from '../../../src/client.js';

const FIXTURES = [
  {
    slug: 'gravitycalendar-creating-a-calendar',
    url: 'https://www.gravitykit.com/docs/gravitycalendar/creating-a-calendar/',
  },
];

async function main(): Promise<void> {
  const { GK_SITE_URL, GK_BLOCK_API_USER, GK_BLOCK_API_APP_PASSWORD } = process.env;
  if (!GK_SITE_URL || !GK_BLOCK_API_USER || !GK_BLOCK_API_APP_PASSWORD) {
    console.error('Missing GK_SITE_URL, GK_BLOCK_API_USER, or GK_BLOCK_API_APP_PASSWORD');
    process.exit(1);
  }

  const client = new WordPressBlockClient({
    wordpress_url: GK_SITE_URL,
    auth: { username: GK_BLOCK_API_USER, application_password: GK_BLOCK_API_APP_PASSWORD },
  });

  const fixturesDir = join(import.meta.dirname, '..', 'fixtures');

  for (const fx of FIXTURES) {
    process.stderr.write(`Fetching ${fx.url} ... `);
    const resolved = await client.resolveUrl(fx.url);
    const blocks = await client.getPageBlocks(resolved.post_id);
    const payload = {
      source_url: fx.url,
      post_id: resolved.post_id,
      post_type: resolved.post_type,
      title: resolved.title,
      slug: resolved.slug,
      fetched_at: new Date().toISOString(),
      response: blocks,
    };
    const outPath = join(fixturesDir, `${fx.slug}.json`);
    writeFileSync(outPath, JSON.stringify(payload, null, 2) + '\n');
    process.stderr.write(`saved → ${outPath}\n`);
  }
}

main().catch((err) => {
  console.error('fetch-fixture failed:', err);
  process.exit(1);
});
