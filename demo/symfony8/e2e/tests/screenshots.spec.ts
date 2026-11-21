import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { REPORTS_PATH, startTimer, stopTimerIfRunning } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots in the demo platform shell (top bar, aside, bundle UI); the Symfony web debug toolbar is hidden.
 * Captured as PNG; `make demo-screenshots` converts them to WebP and deletes the PNGs.
 */
test.describe.configure({ mode: 'serial' });

const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;

/** Hides the Symfony Web Debug Toolbar, resizes the viewport to the whole shell, then captures it. */
async function captureDemoFrame(page: Page, file: string): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar, .sf-minitoolbar, [id^="sfwdt"] { display: none !important; }' });
  const contentBottom = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
  await page.setViewportSize({ width: WIDTH, height: Math.max(720, contentBottom) });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: resolve(outDir, file) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('TimeTrack screenshots (demo platform shell)', () => {
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await stopTimerIfRunning(page);
    await page.close();
  });

  test('timer page — a running timer', async ({ page }) => {
    await page.setViewportSize({ width: WIDTH, height: 900 });
    await stopTimerIfRunning(page);
    await startTimer(page, 'Implement login API');
    await captureDemoFrame(page, 'timer.png');
  });

  test('reports page — the entries of the period', async ({ page }) => {
    await page.setViewportSize({ width: WIDTH, height: 900 });
    await stopTimerIfRunning(page);
    // The seeded entries of the last days (TimeTrackFixtures); `to` is today at 00:00, so today's e2e entries stay out.
    const day = (offset: number): string => new Date(Date.now() - offset * 86_400_000).toISOString().slice(0, 10);
    await page.goto(`${REPORTS_PATH}?from=${day(6)}&to=${day(0)}`);
    await expect(page.getByTestId('time-track-reports').locator('tbody tr').first()).toBeVisible();
    await captureDemoFrame(page, 'reports.png');
  });

  test('device verification page — a desktop app asks for access', async ({ page, request }) => {
    await page.setViewportSize({ width: WIDTH, height: 900 });
    const code = await request.post('/api/time-track/device/code', {
      data: { clientType: 'desktop', clientName: 'Work laptop', scopes: ['timer:read', 'timer:write', 'tasks:read'] },
    });
    const grant = await code.json();
    const complete = new URL(grant.verificationUriComplete);
    await page.goto(complete.pathname + complete.search);
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByTestId('time-track-device-confirm')).toContainText('Work laptop');
    await captureDemoFrame(page, 'device.png');
  });
});
