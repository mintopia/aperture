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

test.describe('User Detail Navigation', () => {
    test('clicking a user row navigates to user detail', async ({ page }) => {
        await page.goto('/admin/users');
        const firstRow = page.locator('[data-testid="data-table-row"]').first();
        await expect(firstRow).toBeVisible();
        await firstRow.click();
        await expect(page).toHaveURL(/\/admin\/users\/\d+/);
    });

    test('user detail shows show layout container', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('user-show-layout')).toBeVisible();
    });

    test('user detail shows edit action button', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('action-edit')).toBeVisible();
    });

    test('user detail shows bandwidth section', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('user-bandwidth-section')).toBeVisible();
    });

    test('user detail shows devices section', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('user-devices-section')).toBeVisible();
    });

    test('user detail shows data/parameters section', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('user-data-section')).toBeVisible();
    });

    test('user detail shows audit section', async ({ page }) => {
        await page.goto('/admin/users/1');
        await expect(page.getByTestId('user-audit-section')).toBeVisible();
    });
});

test.describe('User Edit Page', () => {
    test('edit page renders form', async ({ page }) => {
        await page.goto('/admin/users/1/edit');
        await expect(page.getByTestId('edit-user-form')).toBeVisible();
    });

    test('edit page has nickname and email fields', async ({ page }) => {
        await page.goto('/admin/users/1/edit');
        await expect(page.getByTestId('edit-user-nickname')).toBeVisible();
        await expect(page.getByTestId('edit-user-email')).toBeVisible();
    });

    test('edit page has submit and cancel buttons', async ({ page }) => {
        await page.goto('/admin/users/1/edit');
        await expect(page.getByTestId('edit-user-submit')).toBeVisible();
        await expect(page.getByTestId('edit-user-cancel')).toBeVisible();
    });
});
