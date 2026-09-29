import { test, expect } from './support/csp-guard.js';

test.describe('Content Security Policy styles', () => {
    test('admin custom CSS and Vue style bindings render without CSP violations', async ({ page }) => {
        const violations = [];
        page.on('console', (msg) => {
            if (/Content Security Policy/i.test(msg.text())) {
                violations.push(msg.text());
            }
        });

        const response = await page.goto('/admin/content/settings');
        expect(response.headers()['content-security-policy']).not.toContain("'unsafe-inline'");

        await page.getByTestId('input-site-title').fill('Aperture');
        await page.getByTestId('input-custom_css').fill(':root { --e2e-csp-marker: applied; }');
        await Promise.all([
            page.waitForResponse((res) => res.request().method() !== 'GET' && res.url().includes('/admin/content/settings')),
            page.getByTestId('action-save').click(),
        ]);
        await page.reload();

        const marker = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--e2e-csp-marker').trim());
        expect(marker).toBe('applied');
        await expect(page.getByTestId('accent-hue-slider')).toBeVisible();
        expect(violations).toEqual([]);

        await page.getByTestId('input-custom_css').fill('');
        await Promise.all([
            page.waitForResponse((res) => res.request().method() !== 'GET' && res.url().includes('/admin/content/settings')),
            page.getByTestId('action-save').click(),
        ]);
    });

    test('Tiptap editor styles are applied under the nonce-based policy', async ({ page }) => {
        const violations = [];
        page.on('console', (msg) => {
            if (/Content Security Policy/i.test(msg.text())) {
                violations.push(msg.text());
            }
        });

        await page.goto('/admin/content/pages/create');
        await expect(page.getByTestId('markdown-editor')).toBeVisible();

        const tiptapStyleNonce = await page.evaluate(() => document.querySelector('style[data-tiptap-style]')?.nonce ?? '');
        expect(tiptapStyleNonce).not.toBe('');
        expect(violations).toEqual([]);
    });
});
