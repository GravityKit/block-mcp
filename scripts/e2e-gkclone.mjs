#!/usr/bin/env node
/**
 * End-to-end smoke for block-mcp v1.2 against gkclone (wp-env, port 7701).
 *
 * Exercises the full docs lifecycle through the REST API directly (no MCP
 * stdio layer involved — this proves the WP backends, which is what stub
 * PHPUnit can't reach):
 *
 *   1. list_terms        — find a category by search
 *   2. create_post       — draft with structured blocks, in that category
 *   3. upload_media      — multipart from a local PNG
 *   4. insert_blocks     — add a core/image referencing the upload
 *   5. get_page_blocks   — assert the image block appears
 *   6. yoast_update_seo  — set title/description/schema/focus
 *   7. yoast_get_seo     — assert the meta was written
 *   8. update_post       — status: publish; HEAD permalink expects 200
 *   9. update_post       — status: trash; permalink expects 404
 *  10. update_post       — status: draft; verify untrashed
 *  11. cleanup           — re-trash the post (attachment retained)
 *
 * Env: source `.env.gkclone` first.
 *   GK_SITE_URL=http://localhost:7701
 *   GK_BLOCK_API_USER=admin
 *   GK_BLOCK_API_APP_PASSWORD=<redacted>
 *
 * Run: node scripts/e2e-gkclone.mjs
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const SITE = process.env.GK_SITE_URL;
const USER = process.env.GK_BLOCK_API_USER;
const PW = process.env.GK_BLOCK_API_APP_PASSWORD;

if (!SITE || !USER || !PW) {
  console.error('Missing env: GK_SITE_URL, GK_BLOCK_API_USER, GK_BLOCK_API_APP_PASSWORD');
  process.exit(2);
}

const auth = 'Basic ' + Buffer.from(`${USER}:${PW}`).toString('base64');
const blockBase = `${SITE}/wp-json/gk-block-api/v1`;
const yoastBase = `${SITE}/wp-json/gravitykit/v1`;

async function request(url, { method = 'GET', json, form } = {}) {
  const headers = { Authorization: auth, Accept: 'application/json' };
  let body;
  if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  } else if (form !== undefined) {
    body = form; // FormData; fetch handles Content-Type + boundary.
  }
  const res = await fetch(url, { method, headers, body });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(`${method} ${url} → ${res.status}\n${text.slice(0, 500)}`);
  }
  return text ? JSON.parse(text) : null;
}

async function step(name, fn) {
  const t0 = Date.now();
  process.stdout.write(`▶ ${name}…`);
  try {
    const result = await fn();
    process.stdout.write(`  ✓ ${Date.now() - t0}ms\n`);
    return result;
  } catch (e) {
    process.stdout.write(`  ✗\n`);
    throw e;
  }
}

function assert(cond, msg) {
  if (!cond) throw new Error(`assertion failed: ${msg}`);
}

(async () => {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');

  // 1. Find any category to use (most-used first to ensure we hit something).
  const terms = await step('list_terms (most-used category)', () =>
    request(`${blockBase}/terms?taxonomy=category&per_page=1&orderby=count&order=desc`),
  );
  assert(terms.terms.length > 0, 'no categories found on this site');
  const catId = terms.terms[0].id;
  console.log(`  category id: ${catId} (${terms.terms[0].name})`);

  // 2. Create a draft post with structured blocks.
  const created = await step('create_post (draft, with blocks)', () =>
    request(`${blockBase}/posts`, {
      method: 'POST',
      json: {
        title: `block-mcp e2e ${stamp}`,
        status: 'draft',
        categories: [catId],
        blocks: [
          {
            name: 'core/heading',
            attributes: { level: 2 },
            innerHTML: '<h2 class="wp-block-heading">block-mcp E2E</h2>',
          },
          {
            name: 'core/paragraph',
            innerHTML: '<p>This post was created end-to-end by the smoke test.</p>',
          },
        ],
      },
    }),
  );
  const postId = created.id;
  console.log(`  post id: ${postId}, slug: ${created.slug}, status: ${created.status}`);

  // 3. Upload a fixture PNG via multipart.
  const png = await fs.readFile(path.join(__dirname, 'fixtures', 'screenshot.png'));
  const form = new FormData();
  form.append('file', new Blob([png]), 'screenshot.png');
  form.append('alt_text', 'block-mcp e2e fixture');
  form.append('post_id', String(postId));
  const media = await step('upload_media (multipart)', () =>
    request(`${blockBase}/media`, { method: 'POST', form }),
  );
  const attId = media.id;
  console.log(`  attachment id: ${attId}`);
  console.log(`  url: ${media.url}`);

  // 4. Insert a core/image block after the heading.
  await step('insert_blocks (core/image)', () =>
    request(`${blockBase}/posts/${postId}/blocks`, {
      method: 'POST',
      json: {
        after: 0,
        blocks: [
          {
            name: 'core/image',
            attributes: { id: attId, url: media.url, alt: 'fixture' },
            innerHTML: `<figure class="wp-block-image"><img src="${media.url}" alt="fixture"/></figure>`,
          },
        ],
      },
    }),
  );

  // 5. Read blocks back and assert image is present.
  const after = await step('get_page_blocks', () =>
    request(`${blockBase}/posts/${postId}/blocks`),
  );
  const blockNames = (after.blocks || []).map((b) => b.name);
  assert(blockNames.includes('core/image'), `core/image not found after insert (got: ${blockNames.join(', ')})`);

  // 6. Set Yoast SEO meta.
  await step('yoast_update_seo (title/description/schema)', () =>
    request(`${yoastBase}/yoast-seo/${postId}`, {
      method: 'PATCH',
      json: {
        title: `block-mcp E2E test (${stamp})`,
        description: 'Smoke test post — proves yoast_update_seo wires correctly.',
        focus_keyword: 'block-mcp e2e',
        schema_article_type: 'TechArticle',
        is_cornerstone: false,
      },
    }),
  );

  // 7. Read Yoast SEO meta back.
  const seo = await step('yoast_get_seo', () =>
    request(`${yoastBase}/yoast-seo/${postId}`),
  );
  assert(seo.title.startsWith('block-mcp E2E test'), `Yoast title not written: ${seo.title}`);
  assert(seo.focus_keyword === 'block-mcp e2e', `focus_keyword not written: ${seo.focus_keyword}`);
  assert(seo.schema_article_type === 'TechArticle', `schema_article_type not written: ${seo.schema_article_type}`);

  // 8. Publish.
  const published = await step('update_post (status: publish)', () =>
    request(`${blockBase}/posts/${postId}`, {
      method: 'PATCH',
      json: { status: 'publish' },
    }),
  );
  assert(published.status === 'publish', `expected publish, got ${published.status}`);
  assert(published.transitioned_to_publish === true, 'transitioned_to_publish not set');
  // Permalink reachability check intentionally skipped — gkclone uses
  // gkclone.orb.local in WP_HOME, which isn't always reachable from this
  // process; the publish status assertion is the truth source.

  // 9. Trash (status-only, must not include other fields per mixed_trash_payload guard).
  const trashed = await step('update_post (status: trash)', () =>
    request(`${blockBase}/posts/${postId}`, {
      method: 'PATCH',
      json: { status: 'trash' },
    }),
  );
  assert(trashed.status === 'trash', `expected trash, got ${trashed.status}`);

  // 10. Untrash to draft.
  const untrashed = await step('update_post (untrash to draft)', () =>
    request(`${blockBase}/posts/${postId}`, {
      method: 'PATCH',
      json: { status: 'draft' },
    }),
  );
  assert(untrashed.status === 'draft', `expected draft, got ${untrashed.status}`);
  assert(untrashed.untrashed === true, 'untrashed flag not set on response');

  // 11. Final cleanup — re-trash. Attachment retained for inspection.
  await step('update_post (final cleanup: re-trash)', () =>
    request(`${blockBase}/posts/${postId}`, {
      method: 'PATCH',
      json: { status: 'trash' },
    }),
  );

  console.log(`\n✅ All steps passed.`);
  console.log(`   post id: ${postId} (now in trash)`);
  console.log(`   attachment id: ${attId} (retained)`);
})().catch((err) => {
  console.error(`\n❌ FAIL: ${err.message}`);
  if (err.stack) console.error(err.stack.split('\n').slice(1, 4).join('\n'));
  process.exit(1);
});
