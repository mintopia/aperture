import { test, expect } from '@playwright/test';

const email = 'playwright-passkey@example.test';
const password = 'playwright-passkey-password';

async function addVirtualAuthenticator(page) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });
    return { cdp, authenticatorId };
}

test('registers a passkey and signs back in with it', async ({ page }) => {
    const { cdp, authenticatorId } = await addVirtualAuthenticator(page);

    await page.goto('/login');
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(password);
    await page.getByTestId('login-submit').click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/account/settings');
    await page.getByTestId('verify-password').fill(password);
    await page.getByTestId('verify-submit').click();

    await expect(page.getByTestId('passkey-register')).toBeVisible();
    await page.getByTestId('passkey-register').click();
    await expect(page.getByTestId('passkey-list')).toBeVisible();
    await expect(page.getByTestId('passkey-error')).toHaveCount(0);

    await expect
        .poll(async () => (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.length)
        .toBe(1);

    await page.getByTestId('user-menu-trigger').click();
    await page.getByTestId('user-menu-logout').click();
    await expect(page.getByTestId('login-form')).toBeVisible();

    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-passkey').click();

    await expect(page).not.toHaveURL(/\/login/);
    await page.goto('/admin/switches');
    await expect(page).toHaveURL(/\/admin\/switches/);
});

test('passkey login without a registered credential shows an error', async ({ page }) => {
    await addVirtualAuthenticator(page);

    await page.goto('/login');
    await page.getByTestId('login-email').fill('nobody-registered@example.test');
    await page.getByTestId('login-passkey').click();

    await expect(page.getByTestId('passkey-error')).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
});
