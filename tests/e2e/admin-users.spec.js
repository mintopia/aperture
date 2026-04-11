import { test, expect } from '@playwright/test';

test.describe('User List', () => {
    test('renders user table', async ({ page }) => {
        await page.goto('/admin/users');
        await expect(page.getByTestId('data-table')).toBeVisible();
    });

    test('status pills are visible', async ({ page }) => {
        await page.goto('/admin/users');
        const pills = page.locator('[data-testid="status-pill"]');
        await expect(pills.first()).toBeVisible();
    });

    test('clickable rows have pointer cursor', async ({ page }) => {
        await page.goto('/admin/users');
        const row = page.locator('[data-testid="data-table-row"]').first();
        await row.hover();
        const cursor = await row.evaluate(el => getComputedStyle(el).cursor);
        expect(cursor).toBe('pointer');
    });
});

test.describe('User Detail (S5)', () => {
    test('renders metadata strip', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('metadata-strip')).toBeVisible();
    });

    test('page title shows user name', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('page-title')).toBeVisible();
    });
});
