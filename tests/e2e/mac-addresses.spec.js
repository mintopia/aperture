import { test, expect } from '@playwright/test';

test.describe('MAC Address Pages', () => {
    test('MAC list page loads', async ({ page }) => {
        await page.goto('/admin/macs');
        await expect(page.getByTestId('page-title')).toContainText('MAC Addresses');
    });

    test('MAC list renders layout', async ({ page }) => {
        await page.goto('/admin/macs');
        await expect(page.getByTestId('macs-index-layout')).toBeVisible();
    });

    test('MAC list renders filter bar', async ({ page }) => {
        await page.goto('/admin/macs');
        await expect(page.getByTestId('macs-filter-bar')).toBeVisible();
    });
});

test.describe('Audit Log Pages', () => {
    test('Audit log page loads', async ({ page }) => {
        await page.goto('/admin/audit-log');
        await expect(page.getByTestId('page-title')).toContainText('Audit Log');
    });

    test('Audit log renders layout', async ({ page }) => {
        await page.goto('/admin/audit-log');
        await expect(page.getByTestId('audit-log-index-layout')).toBeVisible();
    });

    test('Audit log renders table section', async ({ page }) => {
        await page.goto('/admin/audit-log');
        await expect(page.getByTestId('audit-log-table-section')).toBeVisible();
    });

    test('Audit log renders filter bar', async ({ page }) => {
        await page.goto('/admin/audit-log');
        await expect(page.getByTestId('audit-log-filter-bar')).toBeVisible();
    });
});
