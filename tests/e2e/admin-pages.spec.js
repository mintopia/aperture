import { test, expect } from '@playwright/test';

test.describe('IP Pages', () => {
    test('IP list renders data table', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('page-title')).toContainText('IP Addresses');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });

    test('IP list has a filter bar with search input', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('ip-filter-bar')).toBeVisible();
        await expect(page.getByTestId('filter-search-input')).toBeVisible();
    });

    test('IP list renders the data table', async ({ page }) => {
        await page.goto('/admin/ips');
        await expect(page.getByTestId('data-table')).toBeVisible();
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

test.describe('Switch Pages', () => {
    test('switch list renders', async ({ page }) => {
        await page.goto('/admin/switches');
        await expect(page.getByTestId('page-title')).toContainText('Switches');
        await expect(page.getByTestId('switches-table-card')).toBeVisible();
        await expect(page.getByTestId('switches-summary')).toBeVisible();
    });

    test('switch list shows the seeded switch with a status', async ({ page }) => {
        await page.goto('/admin/switches');
        await expect(page.getByTestId('switch-name-1')).toHaveText('Playwright Switch');
        await expect(page.locator('[data-testid^="switch-status-"]').first()).toBeVisible();
    });

    test('switch detail and seeded port page render', async ({ page }) => {
        await page.goto('/admin/switches');
        await expect(page.getByText('Playwright Switch')).toBeVisible();

        await page.getByText('Playwright Switch').click();
        await expect(page.getByTestId('page-title')).toContainText('Playwright Switch');
        await expect(page.getByTestId('data-table')).toBeVisible();

        await page.getByTestId('data-table-row').first().click();
        await expect(page.getByTestId('page-title')).toContainText('Gi1/0/1');
        await expect(page.getByTestId('action-toggle')).toBeVisible();
        await expect(page.getByTestId('breadcrumb-link').filter({ hasText: 'Playwright Switch' })).toBeVisible();
    });
});

test.describe('DHCP Pages', () => {
    test('DHCP index renders the ranges section', async ({ page }) => {
        await page.goto('/admin/dhcp');
        await expect(page.getByTestId('page-title')).toContainText('DHCP');
        await expect(page.getByText('Configured Ranges')).toBeVisible();
    });

    test('DHCP index has link to leases page', async ({ page }) => {
        await page.goto('/admin/dhcp');
        await expect(page.getByTestId('view-leases-button')).toHaveAttribute('href', /leases/);
    });

    test('DHCP leases page renders', async ({ page }) => {
        await page.goto('/admin/dhcp/leases');
        await expect(page.getByTestId('page-title')).toContainText('DHCP Leases');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });
});

test.describe('Content Page', () => {
    test('content page renders the grid editor', async ({ page }) => {
        await page.goto('/admin/content');
        await expect(page.getByTestId('page-title')).toContainText('Grid Editor');
    });
});
