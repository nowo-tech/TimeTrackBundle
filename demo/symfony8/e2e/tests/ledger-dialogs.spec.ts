import { test, expect } from '@playwright/test';

/**
 * TimeTrack 1.38 (symfony-beacon rule "every dialog loads its content on demand"): the team ledger page renders links
 * and one empty remote dialog, never a form or a dialog body per row. Opening a dialog fetches its form (X-Nowo-Dialog),
 * an invalid post is answered in place (422), a saved one closes the dialog and reloads the page (204 +
 * X-Nowo-Redirect), and without JavaScript the same links open the full page with the same form.
 */
test.describe.configure({ mode: 'serial' });

const LEDGER_PATH = '/team/ledger';

/** Today as the date input expects it (Y-m-d). */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

test.describe('TimeTrack ledger dialogs on demand', () => {
  test('the page renders no form and no dialog body', async ({ page }) => {
    await page.goto(LEDGER_PATH);
    const ledger = page.getByTestId('time-track-ledger');
    await expect(ledger).toBeVisible();
    await expect(ledger.locator('form')).toHaveCount(0);
    await expect(ledger.locator('textarea, input')).toHaveCount(0);
    await expect(ledger.locator('dialog')).toHaveCount(1);
    await expect(ledger.locator('dialog [data-nowo-ui-dialog-body]')).toBeEmpty();
    await expect(ledger.getByTestId('time-track-ledger-add')).toHaveAttribute('href', '/team/ledger/adjust');
  });

  test('the add dialog loads its form, answers 422 in place and saves with 204 + redirect', async ({ page }) => {
    await page.goto(LEDGER_PATH);
    const fragment = page.waitForResponse((r) => r.url().endsWith('/team/ledger/adjust') && r.request().method() === 'GET');
    await page.getByTestId('time-track-ledger-add').click();
    const loaded = await fragment;
    expect(loaded.status()).toBe(200);
    expect(await loaded.request().headerValue('x-nowo-dialog')).toBe('1');

    const dialog = page.locator('dialog#time-track-ledger-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Kind')).toBeFocused();
    await dialog.getByLabel('Kind').selectOption({ label: 'Adjustment' });
    await dialog.getByLabel('Minutes').fill('15');
    await dialog.getByLabel('Effective date').fill(today());
    await dialog.getByLabel('Reason').fill('   ');

    const invalid = page.waitForResponse((r) => r.url().endsWith('/team/ledger/adjust') && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Add adjustment' }).click();
    expect((await invalid).status()).toBe(422);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByTestId('time-track-dialog-error')).toContainText('A reason is required');
    await expect(dialog.getByLabel('Minutes')).toHaveValue('15');

    const reason = `E2E adjustment ${Date.now()}`;
    await dialog.getByLabel('Reason').fill(reason);
    const saved = page.waitForResponse((r) => r.url().endsWith('/team/ledger/adjust') && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Add adjustment' }).click();
    const answer = await saved;
    expect(answer.status()).toBe(204);
    expect(answer.headers()['x-nowo-redirect']).toBe(LEDGER_PATH);
    await expect(page.getByTestId('time-track-ledger')).toContainText(reason);
    await expect(page.locator('dialog#time-track-ledger-dialog')).toBeHidden();
  });

  test('a row reversal is loaded on demand and marks the row reversed', async ({ page }) => {
    await page.goto(LEDGER_PATH);
    const row = page.getByTestId('time-track-ledger').locator('tbody tr:not([data-reversed])', { hasText: 'Customer call after hours' });
    const opener = row.getByRole('link', { name: /Reverse/ });
    if ((await opener.count()) === 0) {
      test.skip(true, 'already reversed by an earlier run');
    }
    await opener.click();
    const dialog = page.locator('dialog#time-track-ledger-dialog');
    await expect(dialog.getByLabel('Reason')).toBeVisible();
    await dialog.getByLabel('Effective date').fill(today());
    await dialog.getByLabel('Reason').fill('Booked twice');
    await dialog.getByRole('button', { name: 'Reverse' }).click();
    await expect(page.getByTestId('time-track-ledger').locator('tr[data-reversed]', { hasText: 'Customer call after hours' })).toHaveCount(1);
  });

  test('without JavaScript the link opens the full page with the same form', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto(LEDGER_PATH);
    await page.getByTestId('time-track-ledger-add').click();
    await expect(page).toHaveURL(/\/team\/ledger\/adjust$/);
    await expect(page.getByTestId('time-track-dialog-page')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', LEDGER_PATH);

    const reason = `E2E no-JS ${Date.now()}`;
    await page.getByLabel('Kind').selectOption({ label: 'Adjustment' });
    await page.getByLabel('Minutes').fill('5');
    await page.getByLabel('Effective date').fill(today());
    await page.getByLabel('Reason').fill(reason);
    await page.getByRole('button', { name: 'Add adjustment' }).click();
    await expect(page).toHaveURL(new RegExp(`${LEDGER_PATH}$`));
    await expect(page.getByTestId('time-track-ledger')).toContainText(reason);
    await context.close();
  });
});
