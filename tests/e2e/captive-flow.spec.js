import { test, expect } from '@playwright/test';

test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Captive device flow', () => {
    test('shows a device code, waits, then grants access and redirects after approval', async ({ page, request }) => {
        await page.goto('/captive');

        const userCode = (await page.getByTestId('captive-user-code').getAttribute('aria-label')).trim();
        expect(userCode).toMatch(/^[A-Z0-9]{4}-\d{4}$/);
        await expect(page.getByTestId('captive-status-pending')).toBeVisible();
        await expect(page.getByTestId('captive-status-complete')).toBeHidden();

        const approval = await request.post('/api/e2e/device/approve', { data: { user_code: userCode } });
        expect(approval.ok()).toBe(true);

        await expect(page).not.toHaveURL(/\/captive/, { timeout: 20_000 });
        await expect(page).toHaveURL(/\/$/);
        await expect(page.getByTestId('captive-user-code')).toHaveCount(0);
    });

    test('approval of an unknown code is rejected and the portal keeps waiting', async ({ page, request }) => {
        await page.goto('/captive');
        const approval = await request.post('/api/e2e/device/approve', { data: { user_code: 'NOPE-0000' } });
        expect(approval.status()).toBe(404);
        await expect(page.getByTestId('captive-status-pending')).toBeVisible();
    });

    test('poll endpoint reports expired for an unknown device code', async ({ request }) => {
        const response = await request.get('/captive/poll/not-a-real-code');
        expect(response.status()).toBe(410);
        expect(await response.json()).toEqual({ status: 'expired' });
    });

    test('poll endpoint stays pending until approval', async ({ page, request }) => {
        await page.goto('/captive');
        const html = await page.content();
        const deviceCode = html.match(/e2e-[A-Za-z0-9]{24}/)[0];

        const pending = await page.request.get(`/captive/poll/${deviceCode}`);
        expect(await pending.json()).toEqual({ status: 'pending' });

        const userCode = (await page.getByTestId('captive-user-code').getAttribute('aria-label')).trim();
        await request.post('/api/e2e/device/approve', { data: { user_code: userCode } });

        const complete = await page.request.get(`/captive/poll/${deviceCode}`);
        const body = await complete.json();
        expect(body.status).toBe('complete');
    });
});
