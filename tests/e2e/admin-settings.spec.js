import { test, expect } from './support/csp-guard.js';

test.describe('Integration Settings', () => {
    test('integrations page lists services and the capabilities reference', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('page-title')).toBeVisible();
        await expect(page.getByTestId('integrations-table')).toBeVisible();
        await expect(page.getByTestId('integration-row-opnsense')).toBeVisible();
        await expect(page.getByTestId('capabilities-reference')).toBeVisible();
    });

    test('integration detail page has config form and actions', async ({ page }) => {
        await page.goto('/admin/settings/integrations/opnsense');
        await expect(page.getByTestId('config-form')).toBeVisible();
        await expect(page.getByTestId('action-save')).toBeVisible();
        await expect(page.getByTestId('action-test-connection')).toBeVisible();
        await expect(page.getByTestId('field-input-endpoint')).toBeVisible();
    });
});

test.describe('Site Settings', () => {
    test('site settings page has branding, accent and mode controls', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByTestId('input-site-title')).toBeVisible();
        await expect(page.getByTestId('accent-hue-slider')).toBeVisible();
        await expect(page.getByTestId('mode-option-light')).toBeVisible();
        await expect(page.getByTestId('mode-option-dark')).toBeVisible();
    });
});

test.describe('Logo Upload', () => {
    test('settings page has logo upload input', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByTestId('input-logo')).toBeAttached();
    });

    test('settings page shows help text for logo', async ({ page }) => {
        await page.goto('/admin/content/settings');
        await expect(page.getByText('Square image')).toBeVisible();
    });
});
