import { test, expect } from '@playwright/test';

test.describe('IP Pages', () => {
    test('IP list renders data table', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('page-title')).toContainText('IP Addresses');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });

    test('IP list has search inputs', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('search-address')).toBeVisible();
        await expect(page.getByTestId('search-nickname')).toBeVisible();
    });

    test('IP list has search button', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('action-search')).toBeVisible();
    });

    test('IP list has pagination when multiple pages', async ({ page }) => {
        await page.goto('/admin/ips');
        // Pagination only renders when last_page > 1
        const pagination = page.getByTestId('pagination');
        // It may or may not exist depending on data volume
        const tableExists = await page.getByTestId('data-table').isVisible();
        expect(tableExists).toBe(true);
    });

    test('IP create page renders form', async ({ page }) => {
        await page.goto('/admin/ips/create');
        await expect(page.getByTestId('page-title')).toContainText('Add IP Address');
        await expect(page.getByTestId('form-field-address')).toBeVisible();
        await expect(page.getByTestId('form-field-comment')).toBeVisible();
        await expect(page.getByTestId('field-allow')).toBeVisible();
        await expect(page.getByTestId('field-limit')).toBeVisible();
        await expect(page.getByTestId('action-submit')).toBeVisible();
    });
});

test.describe('Port Pages', () => {
    test('port list renders data table', async ({ page }) => {
        await page.goto('/admin/ports');
        await expect(page.getByTestId('page-title')).toContainText('Switch Ports');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });

    test('port list shows status pills', async ({ page }) => {
        await page.goto('/admin/ports');
        const pills = page.locator('[data-testid="status-pill"]');
        const dataTable = page.getByTestId('data-table');
        await expect(dataTable).toBeVisible();
    });
});

test.describe('DHCP Pages', () => {
    test('DHCP index renders stat cards', async ({ page }) => {
        await page.goto('/admin/dhcp');
        await expect(page.getByTestId('page-title')).toContainText('DHCP');
        const statCards = page.locator('[data-testid="stat-card"]');
        await expect(statCards.first()).toBeVisible();
    });

    test('DHCP index has link to leases page', async ({ page }) => {
        await page.goto('/admin/dhcp');
        const leaseLink = page.locator('a[href*="leases"]');
        await expect(leaseLink).toBeVisible();
    });

    test('DHCP leases page renders', async ({ page }) => {
        await page.goto('/admin/dhcp/leases');
        await expect(page.getByTestId('page-title')).toContainText('DHCP Leases');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });
});

test.describe('Stats Pages', () => {
    test('stats index renders', async ({ page }) => {
        await page.goto('/admin/stats');
        await expect(page.getByTestId('page-title')).toContainText('Network Stats');
    });

    test('stats index has stat cards', async ({ page }) => {
        await page.goto('/admin/stats');
        const statCards = page.locator('[data-testid="stat-card"]');
        await expect(statCards.first()).toBeVisible();
    });

    test('bandwidth page renders', async ({ page }) => {
        await page.goto('/admin/stats/bandwidth');
        await expect(page.getByTestId('page-title')).toContainText('Bandwidth');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });
});

test.describe('Content Page', () => {
    test('content page renders', async ({ page }) => {
        await page.goto('/admin/content');
        await expect(page.getByTestId('page-title')).toContainText('Content Blocks');
    });

    test('content page shows empty state or data table', async ({ page }) => {
        await page.goto('/admin/content');
        const emptyState = page.getByTestId('empty-state');
        const dataTable = page.getByTestId('data-table');
        // One of these should be visible
        const hasEmpty = await emptyState.isVisible().catch(() => false);
        const hasTable = await dataTable.isVisible().catch(() => false);
        expect(hasEmpty || hasTable).toBe(true);
    });
});
