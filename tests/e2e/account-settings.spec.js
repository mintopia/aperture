import { test, expect } from './support/csp-guard.js';
import { prepareFixtures, saveLoginState } from './support/fixtures.js';

const email = 'playwright-account@example.test';
const originalPassword = 'playwright-attendee-password';
const changedPassword = 'changed-password-123';

test.describe('Account settings journey', () => {
    test.describe.configure({ mode: 'serial' });
    const stateFile = 'playwright/.auth/account.json';
    test.use({ storageState: stateFile });

    test.beforeAll(async ({ browser }) => {
        prepareFixtures();
        await saveLoginState(browser, { email, password: originalPassword }, stateFile);
    });

    async function login(page, password) {
        await page.goto('/login');
        await page.getByTestId('login-email').fill(email);
        await page.getByTestId('login-password').fill(password);
        const response = page.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST');
        await page.getByTestId('login-submit').click();
        await response;
    }

    async function openSettings(page) {
        await page.goto('/account/settings');
        await expect(page.getByTestId('settings-page')).toBeVisible();
    }

    async function verify(page, password = originalPassword) {
        const gate = page.getByTestId('verify-form');
        await expect(gate.or(page.getByTestId('password-section'))).toBeVisible();
        if (!(await gate.isVisible())) return;
        await page.getByTestId('verify-password').fill(password);
        await page.getByTestId('verify-submit').click();
        await expect(page.getByTestId('password-section')).toBeVisible();
    }

    async function setPassword(page, password, confirmation = password) {
        await page.getByTestId('password-new').fill(password);
        await page.getByTestId('password-confirm').fill(confirmation);
        await page.getByTestId('password-save').click();
    }

    test('requires verification before password management', async ({ page }) => {
        await openSettings(page);
        await expect(page.getByTestId('verify-form')).toBeVisible();
        await expect(page.getByTestId('password-section')).toHaveCount(0);
        await expect(page.getByTestId('passkey-section')).toHaveCount(0);
    });

    test('rejects an incorrect verification password', async ({ page }) => {
        await openSettings(page);
        await page.getByTestId('verify-password').fill('not-the-password');
        await page.getByTestId('verify-submit').click();
        await expect(page.getByTestId('form-field-verify-password')).toContainText('Incorrect password.');
        await expect(page.getByTestId('verify-form')).toBeVisible();
    });

    test('unlocks password and passkey sections after verification', async ({ page }) => {
        await openSettings(page);
        await verify(page);
        await expect(page.getByTestId('passkey-section')).toBeVisible();
        await expect(page.getByTestId('password-clear')).toBeVisible();
    });

    test('validates password confirmation on update', async ({ page }) => {
        await openSettings(page);
        await verify(page);
        await setPassword(page, changedPassword, 'something-else-123');
        await expect(page.getByTestId('form-field-password-new')).toContainText('confirmation');
        await setPassword(page, 'short');
        await expect(page.getByTestId('form-field-password-new')).toContainText('at least 8');
    });

    test('updates the password and signs in with the new one', async ({ page, context }) => {
        await openSettings(page);
        await verify(page);
        await setPassword(page, changedPassword);
        await expect(page.getByTestId('password-new')).toHaveValue('');

        try {
            await context.clearCookies();
            await login(page, changedPassword);
            await openSettings(page);
            await verify(page, changedPassword);
        } finally {
            if (await page.getByTestId('password-section').isVisible()) {
                await setPassword(page, originalPassword);
                await expect(page.getByTestId('password-new')).toHaveValue('');
            }
        }
    });

    test('clears the password then creates a new one', async ({ page, context }) => {
        await context.clearCookies();
        await login(page, originalPassword);
        await openSettings(page);
        await verify(page);

        await page.getByTestId('password-clear').click();
        await page.getByTestId('confirm-modal-confirm').click();
        await expect(page.getByTestId('confirm-modal')).toHaveCount(0);
        await expect(page.getByTestId('create-password-section')).toBeVisible();
        await expect(page.getByTestId('passkey-section')).toHaveCount(0);

        await page.getByTestId('create-password').fill(originalPassword);
        await page.getByTestId('create-password-confirm').fill('mismatch-password');
        await page.getByTestId('create-password-submit').click();
        await expect(page.getByTestId('form-field-create-password')).toContainText('confirmation');

        await page.getByTestId('create-password-confirm').fill(originalPassword);
        await page.getByTestId('create-password-submit').click();
        await expect(page.getByTestId('password-section')).toBeVisible();
        await expect(page.getByTestId('password-clear')).toBeVisible();
        await expect(page.getByTestId('passkey-section')).toBeVisible();
    });
});
