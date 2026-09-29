import { test, expect } from './support/test.js';
import { makePng, resetGeneralSettings, restoreDetectionSettings } from './support/admin-state.js';

test.describe.configure({ mode: 'serial' });

async function save(page) {
    const url = page.url();
    const put = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/admin/'));
    const refreshed = page.waitForResponse((r) => r.request().method() === 'GET' && r.url() === url);
    await page.getByTestId('action-save').click();
    await put;
    await refreshed;
}

async function openSettings(page) {
    await page.goto('/admin/content/settings', { waitUntil: 'networkidle' });
}

test.describe('Detection and captive API settings', () => {
    test.afterAll(() => restoreDetectionSettings());

    test('IPv6 detection settings validate, save and persist', async ({ page }) => {
        await page.goto('/admin/settings/ipv6-detection');
        await expect(page.getByTestId('ipv6-settings-form')).toBeVisible();

        await page.getByTestId('detection-endpoint-input').fill('http://insecure.e2e.invalid/{uuid}');
        await save(page);
        await expect(page.getByTestId('ipv6-settings-form')).toContainText(
            'detection endpoint field format is invalid',
        );

        await page.getByTestId('detection-endpoint-input').fill('https://ipv6.e2e.invalid/check/{uuid}');
        await page.getByTestId('jwks-url-input').fill('https://ipv6.e2e.invalid/.well-known/jwks.json');
        await page.getByTestId('jwt-audience-input').fill('e2e-audience');
        await page.getByTestId('jwt-issuer-input').fill('https://ipv6.e2e.invalid');
        await save(page);

        await page.reload();
        await expect(page.getByTestId('detection-endpoint-input')).toHaveValue('https://ipv6.e2e.invalid/check/{uuid}');
        await expect(page.getByTestId('jwks-url-input')).toHaveValue('https://ipv6.e2e.invalid/.well-known/jwks.json');
        await expect(page.getByTestId('jwt-audience-input')).toHaveValue('e2e-audience');
        await expect(page.getByTestId('jwt-issuer-input')).toHaveValue('https://ipv6.e2e.invalid');
    });

    test('DNS detection requires the {uuid} placeholder and persists valid settings', async ({ page }) => {
        await page.goto('/admin/settings/dns-detection');

        await page.getByTestId('input-dns-check-url').fill('http://dns.e2e.invalid/no-placeholder');
        await save(page);
        await expect(page.getByText('The URL must contain the {uuid} placeholder.')).toBeVisible();

        await page.getByTestId('input-dns-check-url').fill('http://dns.e2e.invalid/{uuid}/probe');
        await page.getByTestId('input-dns-warning-message').fill('Your DNS is not filtered (e2e)');
        await save(page);

        await page.reload();
        await expect(page.getByTestId('input-dns-check-url')).toHaveValue('http://dns.e2e.invalid/{uuid}/probe');
        await expect(page.getByTestId('input-dns-warning-message')).toHaveValue('Your DNS is not filtered (e2e)');
    });

    test('captive portal API settings persist and are served by the public endpoint', async ({ page }) => {
        await page.goto('/admin/settings/captive-portal-api');
        await expect(page.getByTestId('api-url-display')).toContainText('/api/captive-portal');

        await page.getByTestId('input-user-portal-url').fill('https://portal.e2e.invalid/login');
        await page.getByTestId('input-venue-info-url').fill('https://venue.e2e.invalid/about');
        await page.getByTestId('toggle-can-extend-session').check({ force: true });
        await expect(page.getByTestId('example-response')).toContainText('https://portal.e2e.invalid/login');
        await expect(page.getByTestId('example-response')).toContainText('"can-extend-session": true');
        await save(page);

        await page.reload();
        await expect(page.getByTestId('input-user-portal-url')).toHaveValue('https://portal.e2e.invalid/login');
        await expect(page.getByTestId('input-venue-info-url')).toHaveValue('https://venue.e2e.invalid/about');
        await expect(page.getByTestId('toggle-can-extend-session')).toBeChecked();

        const api = await page.request.get('/api/captive-portal');
        expect(api.ok()).toBe(true);
        expect(await api.json()).toMatchObject({
            'user-portal-url': 'https://portal.e2e.invalid/login',
            'venue-info-url': 'https://venue.e2e.invalid/about',
            'can-extend-session': true,
        });
    });

    test('captive portal API rejects malformed URLs', async ({ page }) => {
        await page.goto('/admin/settings/captive-portal-api');
        await page
            .getByTestId('input-user-portal-url')
            .evaluate((el) => el.closest('form').setAttribute('novalidate', ''));
        await page.getByTestId('input-user-portal-url').fill('not a url');
        await save(page);
        await expect(page.getByTestId('captive-portal-api-form')).toContainText(/valid URL/i);
    });
});

test.describe('General settings: custom CSS, logo and cover image', () => {
    test.beforeEach(() => resetGeneralSettings());
    test.afterAll(() => resetGeneralSettings());

    test('custom CSS is applied site-wide after saving and unsafe CSS is rejected', async ({ page }) => {
        await openSettings(page);
        await page.getByTestId('input-site-title').fill('E2E Site');

        await page.getByTestId('input-custom_css').fill('@import url("https://evil.e2e.invalid/x.css");');
        await save(page);
        await expect(page.getByText('contains potentially dangerous CSS patterns')).toBeVisible();

        await page.getByTestId('input-custom_css').fill(':root { --e2e-marker: applied; }');
        await save(page);

        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByTestId('input-custom_css')).toHaveValue(':root { --e2e-marker: applied; }');
        expect(
            await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--e2e-marker').trim(),
            ),
        ).toBe('applied');

        await page.goto('/admin');
        expect(
            await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--e2e-marker').trim(),
            ),
        ).toBe('applied');

        await openSettings(page);
        await page.getByTestId('input-custom_css').fill('');
        await save(page);
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByTestId('input-custom_css')).toHaveValue('');
        expect(
            await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--e2e-marker').trim(),
            ),
        ).toBe('');
    });

    test('logo upload persists across reload, rejects tiny images and can be removed', async ({ page }) => {
        await openSettings(page);
        await expect(page.getByTestId('logo-preview')).toHaveCount(0);

        await page
            .getByTestId('input-logo')
            .setInputFiles({ name: 'tiny.png', mimeType: 'image/png', buffer: makePng(32, 32) });
        await expect(page.getByText(/64/).first()).toBeVisible();
        await expect(page.getByTestId('logo-preview')).toHaveCount(0);

        const upload = page.waitForResponse(
            (r) => r.url().endsWith('/admin/content/settings/logo') && r.request().method() === 'POST',
        );
        await page
            .getByTestId('input-logo')
            .setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: makePng(128, 128) });
        await upload;
        await expect(page.getByTestId('logo-preview')).toBeVisible();

        await page.reload({ waitUntil: 'networkidle' });
        const preview = page.getByTestId('logo-preview');
        await expect(preview).toBeVisible();
        await expect(preview).toHaveAttribute('src', /storage\/branding\/logo\.png\?v=\d+/);
        await expect(page.locator('link[rel="icon"]').first()).toHaveAttribute('href', /branding|favicon/);

        await page.getByTestId('action-remove-logo').click();
        await expect(page.getByTestId('logo-preview')).toHaveCount(0);
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByTestId('logo-preview')).toHaveCount(0);
    });

    test('cover image upload persists across reload, rejects narrow images and can be removed', async ({ page }) => {
        await openSettings(page);
        await expect(page.getByTestId('cover-image-preview')).toHaveCount(0);

        await page
            .getByTestId('input-cover-image')
            .setInputFiles({ name: 'narrow.png', mimeType: 'image/png', buffer: makePng(300, 100) });
        await expect(page.getByText(/600/).first()).toBeVisible();
        await expect(page.getByTestId('cover-image-preview')).toHaveCount(0);

        const upload = page.waitForResponse(
            (r) => r.url().endsWith('/admin/content/settings/cover-image') && r.request().method() === 'POST',
        );
        await page
            .getByTestId('input-cover-image')
            .setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: makePng(800, 300, [40, 90, 200]) });
        await upload;
        await expect(page.getByTestId('cover-image-preview')).toBeVisible();

        await page.reload({ waitUntil: 'networkidle' });
        const preview = page.getByTestId('cover-image-preview');
        await expect(preview).toBeVisible();
        await expect(preview).toHaveAttribute('src', /storage\/(cover|branding)\/.+\?v=\d+/);

        await page.getByTestId('action-remove-cover-image').click();
        await expect(page.getByTestId('cover-image-preview')).toHaveCount(0);
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.getByTestId('cover-image-preview')).toHaveCount(0);
    });
});
