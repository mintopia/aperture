import { test, expect } from '@playwright/test';

test.describe('Settings Pages (S8)', () => {
    test('settings nav renders on integrations page', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('settings-nav')).toBeVisible();
    });

    test('all settings nav items visible', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('settings-nav-integrations')).toBeVisible();
        await expect(page.getByTestId('settings-nav-theme')).toBeVisible();
        await expect(page.getByTestId('settings-nav-event')).toBeVisible();
        await expect(page.getByTestId('settings-nav-portal')).toBeVisible();
    });

    test('theme page has visual picker', async ({ page }) => {
        await page.goto('/admin/settings/theme');
        await expect(page.getByTestId('page-title')).toContainText('Theme');
    });

    test('theme page has theme options with data-testid', async ({ page }) => {
        await page.goto('/admin/settings/theme');
        await expect(page.getByTestId('theme-option-cool-neon')).toBeVisible();
        await expect(page.getByTestId('theme-option-default')).toBeVisible();
    });

    test('theme page has mode options with data-testid', async ({ page }) => {
        await page.goto('/admin/settings/theme');
        await expect(page.getByTestId('mode-option-light')).toBeVisible();
        await expect(page.getByTestId('mode-option-dark')).toBeVisible();
    });

    test('event page has form fields', async ({ page }) => {
        await page.goto('/admin/settings/event');
        const fields = page.locator('[data-testid^="form-field-"]');
        await expect(fields.first()).toBeVisible();
    });

    test('event page has event name and description fields', async ({ page }) => {
        await page.goto('/admin/settings/event');
        await expect(page.getByTestId('form-field-event_name')).toBeVisible();
        await expect(page.getByTestId('form-field-event_description')).toBeVisible();
    });

    test('portal page has session timeout and redirect URL', async ({ page }) => {
        await page.goto('/admin/settings/portal');
        await expect(page.getByTestId('form-field-portal_session_timeout')).toBeVisible();
        await expect(page.getByTestId('form-field-portal_redirect_url')).toBeVisible();
    });

    test('save button exists on all settings pages', async ({ page }) => {
        for (const sub of ['integrations', 'theme', 'event', 'portal']) {
            await page.goto(`/admin/settings/${sub}`);
            await expect(page.getByTestId('action-save')).toBeVisible();
        }
    });

    test('integrations page has form fields for endpoints', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('form-field-opnsense_endpoint')).toBeVisible();
        await expect(page.getByTestId('form-field-ntopng_endpoint')).toBeVisible();
    });
});
