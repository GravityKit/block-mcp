import { test, expect, type Page, type Locator } from '@playwright/test';

/**
 * Block MCP settings — namespace tier-score table regression e2e.
 *
 * Pins the browser-only half of the tier-score table contract, the part PHPUnit
 * can't reach because it lives in the inline admin JavaScript and the real save
 * round-trip:
 *
 *  1. A namespace added through the table's "Add row" affordance survives a save
 *     and reload, sitting alongside the shipped defaults. This is the fix for
 *     the auto-grow row-drop defect, where a row a user added could fail to
 *     reach the form DOM and be lost on save with no warning.
 *  2. A score entered with no block-family name surfaces a warning on save
 *     rather than vanishing silently.
 *
 * The PHPUnit suite (tests/Connect/SettingsPagePreferencesTest.php) owns the
 * server-side merge contract (render + sanitize layering over defaults); this
 * spec owns what only a real browser can prove.
 *
 * Prereqs: a running WordPress with gk-block-mcp active + admin creds. The
 * fastest path is a Siteminter mint. See tests/e2e/playwright.config.ts.
 */

const SETTINGS_POLICY =
  '/wp-admin/options-general.php?page=gk-block-mcp-settings&tab=policy';

const USER = process.env.GK_E2E_USER || 'admin';
const PASS = process.env.GK_E2E_PASS || 'admin';

const NS_TABLE =
  'table.gk-block-mcp-growable[data-row-prefix="gk_block_api_preferences[namespace_rows]"]';

async function login(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(USER);
  await page.locator('#user_pass').fill(PASS);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
}

/** Open the policy tab and expand the Advanced disclosure that holds the table. */
async function openPolicyTable(page: Page): Promise<Locator> {
  await page.goto(SETTINGS_POLICY);
  await page.locator('details.gk-block-mcp-advanced').evaluate((d) => {
    (d as HTMLDetailsElement).open = true;
  });
  const table = page.locator(NS_TABLE);
  await expect(table).toBeVisible();
  return table;
}

/** Read the non-empty namespace-name values currently in the table. */
async function namespaceNames(table: Locator): Promise<string[]> {
  const values = await table
    .locator('input[name*="[name]"]')
    .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
  return values.filter((v) => v !== '');
}

test('a namespace added via the Add row button persists across save', async ({
  page,
}) => {
  await login(page);
  const table = await openPolicyTable(page);

  // The Add row affordance is the deterministic replacement for the racy
  // type-to-grow behaviour — it must exist and append a fillable row.
  const addRow = page.locator('.gk-block-mcp-add-row[data-target-table="namespace"]');
  await expect(addRow).toBeVisible();
  await addRow.click();

  await table.locator('input[name*="[name]"]').last().fill('spectra');
  await table.locator('input[name*="[score]"]').last().fill('75');

  await page.locator('#gk-block-mcp-save').click();
  await page.waitForLoadState('networkidle');

  // Reload the table from storage and confirm the addition survived alongside
  // the shipped defaults.
  const reloaded = await openPolicyTable(page);
  const names = await namespaceNames(reloaded);
  expect(names).toContain('spectra');
  expect(names).toContain('jetpack');

  const spectraScore = reloaded
    .locator('tr', { has: page.locator('input[value="spectra"]') })
    .locator('input[type="number"]');
  await expect(spectraScore).toHaveValue('75');
});

test('a score entered without a name warns on save instead of dropping silently', async ({
  page,
}) => {
  await login(page);
  const table = await openPolicyTable(page);

  // Fill the trailing blank row's score but leave its name empty — the classic
  // "entered a score, never named it" half-finished edit.
  await table.locator('#gk-ns-score-new').fill('33');

  await page.locator('#gk-block-mcp-save').click();
  await page.waitForLoadState('networkidle');

  await expect(
    page.getByText(/score was entered without a name/i)
  ).toBeVisible();
});
