import { test, expect } from '@playwright/test';

test.describe('Admin IP Index (extended)', () => {
    test('index page has create IP button', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('action-create-ip')).toBeVisible();
    });

    test('index page has filter bar', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('ip-filter-bar')).toBeVisible();
    });

    test('filter bar contains search input', async ({ page }) => {
        await page.goto('/admin/ips');
        // The FilterBar component renders data-testid="filter-search-input"
        await expect(page.getByTestId('filter-search-input')).toBeVisible();
    });

    test('create IP button links to create page', async ({ page }) => {
        await page.goto('/admin/ips');
        await page.getByTestId('action-create-ip').click();
        await expect(page).toHaveURL(/\/admin\/ips\/create/);
    });
});

test.describe('Admin IP Show Page', () => {
    test('show page is reachable from list when IPs exist', async ({ page }) => {
        await page.goto('/admin/ips');
        const firstRow = page.locator('[data-testid="data-table-row"]').first();
        const hasRows = await firstRow.isVisible().catch(() => false);

        if (!hasRows) {
            // No IPs seeded — skip show page tests gracefully
            test.skip();
            return;
        }

        await firstRow.click();
        await expect(page).toHaveURL(/\/admin\/ips\//);
        await expect(page.getByTestId('page-title')).toBeVisible();
    });

    test('show page renders IP sections when IP exists', async ({ page }) => {
        await page.goto('/admin/ips');
        const firstRow = page.locator('[data-testid="data-table-row"]').first();
        const hasRows = await firstRow.isVisible().catch(() => false);

        if (!hasRows) {
            test.skip();
            return;
        }

        await firstRow.click();
        await expect(page.getByTestId('ip-macs-section')).toBeVisible();
        await expect(page.getByTestId('ip-dhcp-section')).toBeVisible();
        await expect(page.getByTestId('ip-audit-section')).toBeVisible();
    });
});
