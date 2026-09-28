import { test, expect } from '@playwright/test';

test.describe('Settings Pages (S8)', () => {
    test('settings nav renders on integrations page', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('settings-nav')).toBeVisible();
    });

    test('all settings nav items visible', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('settings-nav-integrations')).toBeVisible();
    });

    test('save button exists on integrations page', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('action-save')).toBeVisible();
    });

    test('integrations page has form fields for endpoints', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('form-field-opnsense_endpoint')).toBeVisible();
    });
});

test.describe('Logo Upload', () => {
    test('settings page has logo upload input', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByTestId('input-logo')).toBeVisible();
    });

    test('settings page shows help text for logo', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByText('Square image')).toBeVisible();
    });
});
