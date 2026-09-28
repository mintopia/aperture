import { test, expect } from '@playwright/test';

const adminEmail = 'first-run-admin@example.test';
const adminPassword = 'first-run-password-123';

test.describe('first-run setup', () => {
    test.describe.configure({ mode: 'serial' });

    test('a fresh install redirects every page to /setup', async ({ page }) => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/setup$/);
        await expect(page.getByTestId('setup-title')).toBeVisible();

        await page.goto('/login');
        await expect(page).toHaveURL(/\/setup$/);
    });

    test('rejects a mismatched password confirmation', async ({ page }) => {
        await page.goto('/setup');
        await page.getByTestId('setup-email').fill(adminEmail);
        await page.getByTestId('setup-password').fill(adminPassword);
        await page.getByTestId('setup-password-confirmation').fill('something-else-entirely');
        await page.getByTestId('setup-submit').click();

        await expect(page).toHaveURL(/\/setup$/);
        await expect(page.getByTestId('setup-form')).toContainText(/confirmation does not match/i);
    });

    test('completing the form creates the admin and signs them in', async ({ page }) => {
        await page.goto('/setup');
        await page.getByTestId('setup-email').fill(adminEmail);
        await page.getByTestId('setup-password').fill(adminPassword);
        await page.getByTestId('setup-password-confirmation').fill(adminPassword);
        await page.getByTestId('setup-submit').click();

        await expect(page).toHaveURL(/\/admin(\/|$)/);
        await expect(page.getByTestId('page-title')).toBeVisible();

        await page.goto('/admin/switches');
        await expect(page).toHaveURL(/\/admin\/switches/);
    });

    test('setup is closed once an admin exists', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/setup');
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByTestId('login-form')).toBeVisible();
    });
});
