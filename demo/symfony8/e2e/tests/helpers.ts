import { expect, type Page } from '@playwright/test';

/** Timer page of the demo (the demo user signs in automatically). */
export const TIMER_PATH = '/tools/time-track';
export const REPORTS_PATH = '/tools/time-track/reports';

/** Stops the running timer, if any, so every test starts from "no active timer". */
export async function stopTimerIfRunning(page: Page): Promise<void> {
  await page.goto(TIMER_PATH);
  const stop = page.getByTestId('time-track-stop');
  if ((await stop.count()) > 0) {
    await stop.click();
    await expect(page.getByTestId('time-track-stop')).toHaveCount(0);
  }
}

/** Starts the timer on the task with the given title from the timer page. */
export async function startTimer(page: Page, taskTitle: string): Promise<void> {
  await page.goto(TIMER_PATH);
  const row = page.getByTestId('time-track-timer').locator('tbody tr', { hasText: taskTitle });
  await row.getByRole('button', { name: 'Start' }).click();
  await expect(page.getByTestId('time-track-stop')).toBeVisible();
}
