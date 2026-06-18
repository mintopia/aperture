import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test.use({ storageState: { cookies: [], origins: [] } }); // override project storageState

    test('shows login form elements', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByTestId('login-email')).toBeVisible();
        await expect(page.getByTestId('login-password')).toBeVisible();
        await expect(page.getByTestId('login-submit')).toBeVisible();
    });

    test('shows passkey option', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByTestId('login-passkey')).toBeVisible();
    });

    test('shows login title', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByTestId('login-title')).toBeVisible();
    });

    test('shows error for invalid credentials', async ({ page }) => {
        await page.goto('/login');
        await page.getByTestId('login-email').fill('invalid@example.com');
        await page.getByTestId('login-password').fill('wrongpassword');
        await page.getByTestId('login-submit').click();
        await expect(page).toHaveURL(/\/login/);
    });

    test('login form is inside a form element', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByTestId('login-form')).toBeVisible();
    });

    test('passkey section is present', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByTestId('passkey-section')).toBeVisible();
    });
});
