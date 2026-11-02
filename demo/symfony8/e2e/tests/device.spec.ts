import { test, expect } from '@playwright/test';

/**
 * Client API 1.9 device flow against the FrankenPHP demo: the desktop app asks for a code (device/code), the signed-in
 * demo user opens the default verification page (UiKit form → show scaffold), approves, and the device exchanges its
 * code once for a scoped session token.
 */
test('device flow: code → verification page → approve → single-use token', async ({ page, request }) => {
  const code = await request.post('/api/time-track/device/code', {
    data: { clientType: 'desktop', clientName: 'E2E laptop', scopes: ['timer:read', 'timer:write', 'tasks:read'] },
  });
  expect(code.status()).toBe(200);
  const grant = await code.json();
  expect(grant.userCode).toMatch(/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/);

  const pending = await request.post('/api/time-track/device/token', { data: { deviceCode: grant.deviceCode } });
  expect(pending.status()).toBe(400);
  expect((await pending.json()).error).toBe('authorization_pending');

  const complete = new URL(grant.verificationUriComplete);
  await page.goto(complete.pathname + complete.search);
  await expect(page.getByTestId('time-track-device')).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();

  const confirm = page.getByTestId('time-track-device-confirm');
  await expect(confirm).toContainText('E2E laptop');
  await expect(confirm).toContainText('timer:read, timer:write, tasks:read');
  await page.getByTestId('time-track-device-approve').click();
  await expect(page.getByText('The device is connected')).toBeVisible();

  const issued = await request.post('/api/time-track/device/token', { data: { deviceCode: grant.deviceCode } });
  expect(issued.status()).toBe(200);
  const token = await issued.json();
  expect(token.scopes).toEqual(['timer:read', 'timer:write', 'tasks:read']);

  const again = await request.post('/api/time-track/device/token', { data: { deviceCode: grant.deviceCode } });
  expect((await again.json()).error).toBe('expired_token');

  const me = await request.get('/api/time-track/me', { headers: { Authorization: `Bearer ${token.token}` } });
  expect(me.status()).toBe(200);
  const entries = await request.get('/api/time-track/entries', { headers: { Authorization: `Bearer ${token.token}` } });
  expect(entries.status()).toBe(403);
});
