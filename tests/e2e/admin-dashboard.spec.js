import { test, expect } from '@playwright/test';

test.describe('Admin Dashboard (S4)', () => {
    test('renders stat cards', async ({ page }) => {
        await page.goto('/admin');
        const statCards = page.locator('[data-testid="stat-card"]');
        await expect(statCards.first()).toBeVisible();
    });

    test('sidebar has 172px width on desktop', async ({ page }) => {
        await page.goto('/admin');
        const sidebar = page.getByTestId('admin-sidebar');
        const box = await sidebar.boundingBox();
        expect(box.width).toBe(172);
    });

    test('sidebar collapses to horizontal nav at 1024px', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 768 });
        await page.goto('/admin');
        await expect(page.getByTestId('admin-nav-horizontal')).toBeVisible();
    });

    test('admin header is visible', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.getByTestId('admin-header')).toBeVisible();
    });
});
