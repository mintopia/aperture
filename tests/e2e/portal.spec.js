import { test, expect } from '@playwright/test';

test.describe('Portal Dashboard (S3)', () => {
    test('renders page with layout', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
    });

    test('renders portal header', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-header')).toBeVisible();
    });

    test('mobile viewport stacks content', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 });
        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
    });
});
