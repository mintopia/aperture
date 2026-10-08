import { test, expect } from './support/test.js';
import { isPhoneViewport } from './support/viewport.js';

async function openSearch(page) {
    await page.goto('/admin');
    await page.getByTestId('global-search-trigger').click();
    await expect(page.getByTestId('global-search-dialog')).toBeVisible();
    await expect(page.getByTestId('global-search-input')).toBeFocused();
}

test.describe('Admin global search', () => {
    test('opens with the keyboard shortcut and closes with Escape', async ({ page }) => {
        await page.goto('/admin');
        await page.keyboard.press('Control+k');
        await expect(page.getByTestId('global-search-dialog')).toBeVisible();
        await expect(page.getByTestId('global-search-input')).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(page.getByTestId('global-search-overlay')).toBeHidden();
    });

    test('search can be opened from the header trigger without a keyboard', async ({ page }) => {
        await page.goto('/admin');

        const trigger = page.getByTestId('global-search-trigger');
        await expect(trigger).toBeVisible();
        await expect(trigger).toHaveAccessibleName('Search');
        if (isPhoneViewport(page)) {
            // Below the sm breakpoint the trigger collapses to an icon-only button.
            await expect(page.getByTestId('global-search-shortcut')).toBeHidden();
            await expect(page.getByTestId('global-search-trigger-icon')).toBeVisible();
        }

        await trigger.click();
        await expect(page.getByTestId('global-search-dialog')).toBeVisible();
        await expect(page.getByTestId('global-search-input')).toBeFocused();

        await page.getByTestId('global-search-input').fill('pw-net');
        const result = page.locator('[data-testid^="global-search-result-user-"]').filter({ hasText: 'pw-net-user' });
        await expect(result).toHaveCount(1);
        await result.click();
        await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
    });

    test('finds a user by nickname and navigates to their page', async ({ page }) => {
        await openSearch(page);
        await page.getByTestId('global-search-input').fill('pw-net');

        await expect(page.getByTestId('global-search-section-users')).toBeVisible();
        const result = page.locator('[data-testid^="global-search-result-user-"]').filter({ hasText: 'pw-net-user' });
        await expect(result).toHaveCount(1);
        await result.click();

        await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
        await expect(page.getByTestId('page-title')).toContainText('pw-net-user');
        await expect(page.getByTestId('global-search-overlay')).toBeHidden();
    });

    test('finds a user by email', async ({ page }) => {
        await openSearch(page);
        await page.getByTestId('global-search-input').fill('pw-edit-user@example');

        await expect(
            page.locator('[data-testid^="global-search-result-user-"]').filter({ hasText: 'pw-edit-user' }),
        ).toHaveCount(1);
    });

    test('finds an IP address and navigates to its page', async ({ page }) => {
        await openSearch(page);
        await page.getByTestId('global-search-input').fill('10.99.1.1');

        await expect(page.getByTestId('global-search-section-ips')).toBeVisible();
        const result = page.locator('[data-testid^="global-search-result-ip-"]').filter({ hasText: '10.99.1.10' });
        await expect(result).toHaveCount(1);
        await result.click();

        await expect(page).toHaveURL(/\/admin\/ips\/10\.99\.1\.10$/);
        await expect(page.getByTestId('page-title')).toHaveText('10.99.1.10');
    });

    test('shows an empty state when nothing matches', async ({ page }) => {
        await openSearch(page);
        await page.getByTestId('global-search-input').fill('no-such-thing-anywhere');

        await expect(page.getByTestId('global-search-empty')).toBeVisible();
        await expect(page.locator('[data-testid^="global-search-result-"]')).toHaveCount(0);
    });

    test('treats SQL wildcards literally instead of matching everything', async ({ page }) => {
        await openSearch(page);
        await page.getByTestId('global-search-input').fill('%%');

        await expect(page.getByTestId('global-search-empty')).toBeVisible();
        await expect(page.locator('[data-testid^="global-search-result-"]')).toHaveCount(0);
    });

    test('does not search until two characters are typed', async ({ page }) => {
        await openSearch(page);
        const requests = [];
        page.on('request', (r) => r.url().includes('/admin/search') && requests.push(r.url()));

        await page.getByTestId('global-search-input').fill('p');
        await page.getByTestId('global-search-input').fill('pw');

        await expect(page.locator('[data-testid^="global-search-result-user-"]').first()).toBeVisible();
        expect(requests).toHaveLength(1);
        expect(requests[0]).toContain('q=pw');
    });

    test('search endpoint rejects unauthenticated requests', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const response = await context.request.get('/admin/search?q=pw', {
            headers: { Accept: 'application/json' },
            maxRedirects: 0,
        });
        expect([302, 401, 403]).toContain(response.status());
        await context.close();
    });
});
