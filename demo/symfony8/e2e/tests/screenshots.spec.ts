import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { REPORTS_PATH, startTimer, stopTimerIfRunning } from './helpers';

/**
 * REQ-DEMO-013 — README screenshots in full demo context: demo navigation + bundle UI + Symfony web debug toolbar.
 * Captured as PNG; `make demo-screenshots` converts them to WebP and deletes the PNGs.
 */
test.describe.configure({ mode: 'serial' });

const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;

/** Resizes the viewport to the bundle UI + the (fixed, bottom) web debug toolbar, then captures it. */
async function captureDemoFrame(page: Page, file: string): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible();
  const toolbarHeight = (await toolbar.boundingBox())?.height ?? 40;
  const contentBottom = await page
    .locator('.nowo-ui-host-page')
    .first()
    .evaluate((el) => el.getBoundingClientRect().bottom + window.scrollY);
  await page.setViewportSize({ width: WIDTH, height: Math.ceil(contentBottom + 48 + toolbarHeight) });
  await expect(toolbar).toBeVisible();
  await page.screenshot({ path: resolve(outDir, file) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('TimeTrack screenshots (full demo frame)', () => {
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
});
