import { test, expect } from './support/csp-guard.js';

const html = (page) => page.locator('html');

async function openUserMenu(page) {
    await page.getByTestId('user-menu-trigger').click();
    await expect(page.getByTestId('user-menu-dropdown')).toBeVisible();
}

test.describe('Reduce transparency', () => {
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
    });

    test('admin toggles Reduce transparency, it survives a reload and can be turned off', async ({ page }) => {
        await page.goto('/portal');
        await expect(html(page)).not.toHaveAttribute('data-transparency', /.+/);

        await openUserMenu(page);
        const item = page.getByTestId('user-menu-reduce-transparency');
        await expect(item).toHaveAttribute('role', 'menuitemcheckbox');
        await expect(item).toHaveAttribute('aria-checked', 'false');

        await item.click();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');
        await expect(item).toHaveAttribute('aria-checked', 'true');

        // The pre-paint script must re-apply the preference before Vue boots.
        await page.reload();
        await expect(page.getByTestId('portal-layout')).toBeVisible();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');

        await openUserMenu(page);
        const reloaded = page.getByTestId('user-menu-reduce-transparency');
        await expect(reloaded).toHaveAttribute('aria-checked', 'true');
        await reloaded.click();
        await expect(html(page)).not.toHaveAttribute('data-transparency', /.+/);
        await expect(reloaded).toHaveAttribute('aria-checked', 'false');

        await page.reload();
        await expect(page.getByTestId('portal-layout')).toBeVisible();
        await expect(html(page)).not.toHaveAttribute('data-transparency', /.+/);
    });

    test('admin layout still renders with reduced transparency', async ({ page }) => {
        await page.goto('/portal');
        await openUserMenu(page);
        await page.getByTestId('user-menu-reduce-transparency').click();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');

        await page.goto('/admin');
        await expect(page.getByTestId('admin-layout')).toBeVisible();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');
        await expect(page.getByTestId('admin-header')).toBeVisible();

        const viewport = page.viewportSize();
        if (viewport && viewport.width >= 1025) {
            await expect(page.getByTestId('admin-sidebar')).toBeVisible();
        } else {
            await expect(page.getByTestId('admin-menu-toggle')).toBeVisible();
        }
    });

    test('admin layout renders with default transparency', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.getByTestId('admin-header')).toBeVisible();
        await expect(html(page)).not.toHaveAttribute('data-transparency', /.+/);
    });
});
