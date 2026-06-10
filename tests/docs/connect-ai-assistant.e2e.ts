import { test, expect, type Page, type Locator } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import * as path from 'node:path';
import * as fs from 'node:fs';
import sharp from 'sharp';

/**
 * Connect-doc e2e + screenshot generator.
 *
 * Drives the Block MCP "Connect an AI Assistant" flow on a running WordPress
 * site and does two jobs at once:
 *
 *  1. Asserts the current UI contract — the five supported clients (Claude
 *     Desktop, Claude Code, Cursor, "Let my AI set it up", "Configure it
 *     myself") and NO ChatGPT; the per-client setup artifact; and the Approve
 *     consent screen with its dedicated-vs-own-account choice. So a regression
 *     (e.g. a re-introduced ChatGPT option) fails CI-style here.
 *  2. Captures the screenshots the published doc embeds, into ./screenshots,
 *     plus a docs.json manifest for the publish step.
 *
 * Re-run whenever the Connect UI changes so the doc never drifts — drift is
 * exactly why the first version of this doc went stale.
 *
 * Prereqs: a WordPress site with gk-block-mcp active + admin creds. See README.md.
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SHOTS = path.join(HERE, 'screenshots');
const shot = (name: string): string => path.join(SHOTS, name);

const SETTINGS =
  '/wp-admin/options-general.php?page=gk-block-mcp-settings&tab=connect';

const USER = process.env.GK_DOCS_USER || 'admin';
const PASS = process.env.GK_DOCS_PASS || '';

// gk:screenshot composition — keep each target IN-SITU: clip the live page
// around it so the surrounding admin context (page background, adjacent UI)
// frames the shot, rather than isolating the element on synthetic whitespace.
// CONTEXT_PAD is how much real page we pull in around the target; MAX_PX caps
// the longest side (Claude Code / docs-site ceiling). Capture DPR is set in
// playwright.config.ts.
const CONTEXT_PAD = 64;
const MAX_PX = 2000;

interface Manifest {
  flow: string;
  steps: { name: string; file: string; caption: string }[];
}
const manifest: Manifest = { flow: 'connect-ai-assistant', steps: [] };

test.beforeAll(() => {
  if (!PASS) {
    throw new Error(
      'Set GK_DOCS_PASS (and optionally GK_DOCS_USER / GK_DOCS_BASE_URL) before running. See tests/docs/README.md.'
    );
  }
  fs.mkdirSync(SHOTS, { recursive: true });
});

test.afterAll(() => {
  fs.writeFileSync(
    path.join(SHOTS, 'docs.json'),
    `${JSON.stringify(manifest, null, 2)}\n`
  );
});

async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(USER);
  await page.locator('#user_pass').fill(PASS);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
}

/**
 * Capture a target in-situ: clip the live page to the target's bounding box
 * plus CONTEXT_PAD of surrounding page, then cap the longest side at MAX_PX.
 * The clip is clamped to the viewport so we never request off-screen pixels.
 */
async function capture(
  page: Page,
  target: Locator,
  name: string,
  caption: string
): Promise<void> {
  await target.scrollIntoViewIfNeeded();
  const box = await target.boundingBox();
  if (!box) throw new Error(`capture("${name}"): target has no bounding box`);

  const vp = page.viewportSize() ?? { width: 1340, height: 1200 };
  const x = Math.max(0, box.x - CONTEXT_PAD);
  const y = Math.max(0, box.y - CONTEXT_PAD);
  const clip = {
    x,
    y,
    width: Math.min(vp.width - x, box.width + CONTEXT_PAD * 2),
    height: Math.min(vp.height - y, box.height + CONTEXT_PAD * 2),
  };

  const raw = await page.screenshot({ clip });
  await sharp(raw)
    .resize({ width: MAX_PX, height: MAX_PX, fit: 'inside', withoutEnlargement: true })
    .toFile(shot(name));
  manifest.steps.push({ name, file: `screenshots/${name}`, caption });
}

test('Connect flow renders correctly and captures the doc screenshots', async ({
  page,
}) => {
  await login(page);

  await test.step('Connect screen — five clients, no ChatGPT', async () => {
    await page.goto(SETTINGS);
    await expect(
      page.getByRole('heading', { name: /Connect an AI Assistant to Your Site/i })
    ).toBeVisible();

    const values = await page
      .locator('input[name="client"]')
      .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(values).toEqual([
      'claude-desktop',
      'claude-code',
      'cursor',
      'ai-prompt',
      'other',
    ]);
    expect(values).not.toContain('chatgpt-desktop');

    // Claude Desktop is the recommended default selection.
    await expect(
      page.locator('input[name="client"][value="claude-desktop"]')
    ).toBeChecked();

    await capture(
      page,
      page.locator('.gk-block-mcp-connect__card').first(),
      'connect-screen.png',
      'The Block MCP Connect screen with the five supported apps.'
    );
  });

  await test.step('Claude Code — reveals an npx setup command', async () => {
    await page.locator('input[name="client"][value="claude-code"]').check();
    const card = page.locator('.gk-block-mcp-connect__artifact-card:visible');
    await expect(card).toBeVisible();
    await expect(
      card.locator('.gk-block-mcp-connect__artifact-textarea')
    ).toContainText('npx');
    await capture(
      page,
      card,
      'command-artifact.png',
      'The setup command shown for Claude Code and Cursor.'
    );
  });

  await test.step('"Let my AI set it up" — reveals a copyable prompt', async () => {
    await page.locator('input[name="client"][value="ai-prompt"]').check();
    const card = page.locator('.gk-block-mcp-connect__artifact-card:visible');
    await expect(card).toBeVisible();
    await capture(
      page,
      card,
      'ai-prompt-artifact.png',
      'The copyable prompt for your AI assistant.'
    );
  });

  await test.step('Approve screen — dedicated vs. own-account choice', async () => {
    const callback = encodeURIComponent('http://127.0.0.1:51234/callback');
    await page.goto(
      `${SETTINGS}&gk_authorize=1&callback=${callback}&state=doc-demo&client=claude-code`
    );
    const card = page.locator('.gk-block-mcp-connect__card').first();
    await expect(card).toBeVisible();
    // The consent screen offers the two identities (agent + self) by default.
    await expect(page.locator('.gk-block-mcp-connect__identity')).toBeVisible();
    await capture(
      page,
      card,
      'approve-screen.png',
      'The Approve screen with the dedicated-account and own-account choices.'
    );
  });

  await test.step('Active connections list', async () => {
    await page.goto(SETTINGS);
    const box = page.locator('.gk-block-mcp-connect__connections-box');
    if (await box.count()) {
      await capture(
        page,
        box.first(),
        'connected-state.png',
        'The Active connections list.'
      );
    } else {
      test.info().annotations.push({
        type: 'note',
        description:
          'No active connections on this site — connected-state.png not captured.',
      });
    }
  });
});
