import { test, expect } from '@playwright/test';

test.describe('Captive Portal Login (S1)', () => {
    test('renders QR code and device code', async ({ page }) => {
        await page.goto('/captive');
        await expect(page.getByTestId('captive-qr')).toBeVisible();
        await expect(page.getByTestId('captive-user-code')).toBeVisible();
    });

    test('QR code has primary border styling', async ({ page }) => {
        await page.goto('/captive');
        const qr = page.getByTestId('captive-qr');
        await expect(qr).toHaveCSS('border-style', 'solid');
    });

    test('mobile viewport shows responsive layout', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 });
        await page.goto('/captive');
        await expect(page.getByTestId('captive-qr')).toBeVisible();
    });
});

test.describe('Activating Interstitial (S2)', () => {
    test('renders step indicators', async ({ page }) => {
        await page.goto('/captive/interstitial');
        await expect(page.getByTestId('interstitial-spinner')).toBeVisible();
    });
});
