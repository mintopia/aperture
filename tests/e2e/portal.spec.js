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

    test('portal renders app logo', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('app-logo')).toBeVisible();
    });
});

test.describe('Portal User Menu', () => {
    test('user menu trigger is accessible', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('user-menu-trigger')).toBeVisible();
    });

    test('user menu opens and shows logout option', async ({ page }) => {
        await page.goto('/portal');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-dropdown')).toBeVisible();
        await expect(page.getByTestId('user-menu-logout')).toBeVisible();
    });

    test('user menu shows admin link for admin user', async ({ page }) => {
        await page.goto('/portal');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-admin')).toBeVisible();
    });
});

test.describe('Portal Footer', () => {
    test('footer is visible', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-footer')).toBeVisible();
    });

    test('footer contains Mintopia credit link', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('footer-heart')).toBeVisible();
        await expect(page.getByTestId('footer-mintopia')).toBeVisible();
    });
});
