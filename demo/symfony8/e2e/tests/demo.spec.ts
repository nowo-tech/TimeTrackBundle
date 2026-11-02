import { test, expect } from '@playwright/test';
import { REPORTS_PATH, TIMER_PATH, startTimer, stopTimerIfRunning } from './helpers';

/**
 * REQ-DEMO-013 — time tracking happy path against the FrankenPHP demo: timer page (UiKit list scaffold) →
 * start a task → running status + Stop → stop → the entry is listed on the reports page. The active timer is shared
 * demo state, so the flow runs serially and always ends with no timer running.
 */
test.describe.configure({ mode: 'serial' });

const TASK = 'Write unit tests';

test.describe('TimeTrack demo', () => {
  test.beforeEach(async ({ page }) => {
    await stopTimerIfRunning(page);
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await stopTimerIfRunning(page);
    await page.close();
  });

  test('root redirects to the timer page, which lists the trackable tasks', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`${TIMER_PATH}$`));
    await expect(page).toHaveTitle(/Time tracker/);
    await expect(page.getByRole('navigation', { name: 'Demo' })).toBeVisible();

    const timer = page.getByTestId('time-track-timer');
    await expect(timer).toBeVisible();
    await expect(page.getByText('No active timer')).toBeVisible();
    for (const title of ['Implement login API', 'Write unit tests', 'Review pull requests']) {
      await expect(timer.locator('tbody')).toContainText(title);
    }
    await expect(timer.getByRole('button', { name: 'Start' })).toHaveCount(3);
  });

  test('starting and stopping a timer records a time entry', async ({ page }) => {
    await startTimer(page, TASK);
    await expect(page.getByText('Timer started.')).toBeVisible();
    await expect(page.getByText(`Running: ${TASK}`)).toBeVisible();
    await expect(page.getByTestId('time-track-timer').getByRole('button', { name: 'Start' })).toHaveCount(0);

    await page.getByTestId('time-track-stop').click();
    await expect(page.getByText('Timer stopped.')).toBeVisible();
    await expect(page.getByText('No active timer')).toBeVisible();

    await page.getByRole('navigation', { name: 'Demo' }).getByRole('link', { name: 'Reports' }).click();
    await expect(page).toHaveURL(new RegExp(`${REPORTS_PATH}$`));
    const reports = page.getByTestId('time-track-reports');
    await expect(reports).toBeVisible();
    await expect(reports.locator('tbody tr').first()).toContainText(TASK);
    await expect(reports.locator('tbody tr').first()).toContainText('Web');
  });

  test('a POST without the CSRF token is refused and changes nothing', async ({ page, request }) => {
    await startTimer(page, TASK);
    const forged = await request.post(TIMER_PATH, { form: { action: 'stop' }, maxRedirects: 0 });
    expect([302, 401, 403]).toContain(forged.status());
    await page.goto(TIMER_PATH);
    await expect(page.getByTestId('time-track-stop')).toBeVisible();
    await expect(page.getByText(`Running: ${TASK}`)).toBeVisible();
  });
});
