import { test, expect } from './support/csp-guard.js';
import { prepareFixtures } from './support/fixtures.js';

test.describe('Public content pages', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        prepareFixtures();
    });

    test('renders a page without authentication', async ({ page }) => {
        await page.goto('/content/playwright-page');
        await expect(page.getByTestId('public-page')).toBeVisible();
        await expect(page.getByTestId('page-title')).toHaveText('Playwright Page');
        await expect(page.getByTestId('page-content').locator('strong')).toHaveText('e2e');
    });

    test('sanitizes script tags in page content', async ({ page }) => {
        await page.goto('/content/playwright-page');
        await expect(page.getByTestId('page-content')).toBeVisible();
        await expect(page.getByTestId('page-content').locator('script')).toHaveCount(0);
        expect(await page.evaluate(() => window.__pwInjected)).toBeUndefined();
    });

    test('returns 404 for an unknown slug', async ({ page }) => {
        const response = await page.goto('/content/does-not-exist');
        expect(response?.status()).toBe(404);
        await expect(page.getByTestId('public-page')).toHaveCount(0);
    });
});
