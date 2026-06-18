import { test, expect } from '@playwright/test';

const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'playwright-password';

// These tests submit the clear-mappings form using the shared admin session.
// Running them in parallel makes their session-flashed errors/success messages
// bleed into each other's redirects, so keep them sequential.
test.describe.configure({ mode: 'default' });

test.describe('Network Settings Maintenance — Clear Stale IP to MAC Mappings', () => {
    test('admin can clear stale mappings after confirming with their password', async ({ page }) => {
        await page.goto('/admin/settings/network');

        const daysInput = page.getByTestId('clear-ip-mac-days-input');
        await expect(daysInput).toBeVisible();
        await daysInput.fill('45');

        await page.getByTestId('clear-ip-mac-button').click();

        const modal = page.getByTestId('confirm-modal');
        await expect(modal).toBeVisible();
        await expect(page.getByTestId('confirm-modal-title')).toContainText('Clear Stale IP to MAC Mappings');
        await expect(page.getByTestId('confirm-modal-message')).toContainText('not seen in the last 45 days');

        await page.getByTestId('clear-ip-mac-password-input').fill(adminPassword);
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(modal).toBeHidden();
        const flash = page.getByTestId('flash-message-success');
        await expect(flash).toBeVisible();
        await expect(flash).toContainText('45 days');
    });

    test('wrong password shows an error and keeps the dialog open', async ({ page }) => {
        await page.goto('/admin/settings/network');

        await page.getByTestId('clear-ip-mac-button').click();

        const modal = page.getByTestId('confirm-modal');
        await expect(modal).toBeVisible();

        await page.getByTestId('clear-ip-mac-password-input').fill('definitely-not-the-password');
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(page.getByTestId('clear-ip-mac-password-error')).toContainText('incorrect');
        await expect(modal).toBeVisible();
        await expect(page.getByTestId('flash-message-success')).toHaveCount(0);
    });

    test('destructive button is disabled while the days value is empty', async ({ page }) => {
        await page.goto('/admin/settings/network');

        const daysInput = page.getByTestId('clear-ip-mac-days-input');
        await daysInput.fill('');

        await expect(page.getByTestId('clear-ip-mac-button')).toBeDisabled();

        await daysInput.fill('30');
        await expect(page.getByTestId('clear-ip-mac-button')).toBeEnabled();
    });
});
