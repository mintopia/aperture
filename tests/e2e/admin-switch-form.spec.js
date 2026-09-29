import { test, expect } from '@playwright/test';

test.describe('Switch form authentication method', () => {
    test('create form defaults to password and toggles to private key', async ({ page }) => {
        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-type').selectOption('cisco');

        await expect(page.getByTestId('switch-password')).toBeVisible();
        await expect(page.getByTestId('switch-private-key')).toHaveCount(0);
        await expect(page.getByTestId('switch-passphrase')).toHaveCount(0);

        await page.getByTestId('switch-auth-private-key').check({ force: true });

        await expect(page.getByTestId('switch-private-key')).toBeVisible();
        await expect(page.getByTestId('switch-passphrase')).toBeVisible();
        await expect(page.getByTestId('switch-password')).toHaveCount(0);
    });

    test('switching method clears the secret of the other method', async ({ page }) => {
        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-type').selectOption('cisco');

        await page.getByTestId('switch-password').fill('temp-password');
        await page.getByTestId('switch-auth-private-key').check({ force: true });
        await page.getByTestId('switch-private-key').fill('-----BEGIN OPENSSH PRIVATE KEY-----');
        await page.getByTestId('switch-auth-password').check({ force: true });

        await expect(page.getByTestId('switch-password')).toHaveValue('');

        await page.getByTestId('switch-auth-private-key').check({ force: true });
        await expect(page.getByTestId('switch-private-key')).toHaveValue('');
    });

    test('private key method requires a key', async ({ page }) => {
        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-name').fill('Key Auth Switch');
        await page.getByTestId('switch-hostname').fill('key-auth-switch.local');
        await page.getByTestId('switch-type').selectOption('cisco');
        await page.getByTestId('switch-username').fill('admin');
        await page.getByTestId('switch-auth-private-key').check({ force: true });
        await page.getByTestId('action-save').click();

        await expect(page).toHaveURL(/\/admin\/switches\/create/);
        await expect(page.getByTestId('switch-private-key')).toBeVisible();
    });

    test('edit form never prefills secrets and shows the host key panel', async ({ page }) => {
        await page.goto('/admin/switches/1/edit');

        await expect(page.getByTestId('switch-auth-password')).toBeChecked();
        await expect(page.getByTestId('switch-password')).toHaveValue('');
        await expect(page.getByTestId('host-key-panel')).toBeVisible();

        await page.getByTestId('switch-auth-private-key').check({ force: true });
        await expect(page.getByTestId('switch-private-key')).toHaveValue('');
        await expect(page.getByTestId('switch-passphrase')).toHaveValue('');
    });

    test('remove stored passphrase checkbox clears the passphrase and keeps the key', async ({ page }) => {
        const host = `pp-switch-${Date.now()}.local`;
        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-name').fill('Passphrase Switch');
        await page.getByTestId('switch-hostname').fill(host);
        await page.getByTestId('switch-type').selectOption('cisco');
        await page.getByTestId('switch-username').fill('admin');
        await page.getByTestId('switch-auth-private-key').check({ force: true });
        await page
            .getByTestId('switch-private-key')
            .fill('-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----');
        await page.getByTestId('switch-passphrase').fill('s3cret');
        await page.getByTestId('action-save').click();
        await expect(page).toHaveURL(/\/admin\/switches\/\d+$/);

        await page.goto(`${page.url()}/edit`);
        await expect(page.getByTestId('switch-auth-private-key')).toBeChecked();
        await expect(page.getByTestId('switch-clear-passphrase')).toBeVisible();
        await page.getByTestId('switch-clear-passphrase').check();
        await page.getByTestId('action-save').click();

        await expect(page.getByTestId('switch-clear-passphrase')).toHaveCount(0);
        await expect(page.getByTestId('switch-auth-private-key')).toBeChecked();
    });
});

test.describe('Switch timezone', () => {
    test('create defaults to UTC and edit persists the chosen timezone', async ({ page }) => {
        const host = `tz-switch-${Date.now()}.local`;
        await page.goto('/admin/switches/create');
        await expect(page.getByTestId('switch-timezone')).toHaveValue('UTC');

        await page.getByTestId('switch-name').fill('Timezone Switch');
        await page.getByTestId('switch-hostname').fill(host);
        await page.getByTestId('switch-type').selectOption('cisco');
        await page.getByTestId('switch-username').fill('admin');
        await page.getByTestId('switch-password').fill('secret');
        await page.getByTestId('switch-timezone').selectOption('Europe/London');
        await page.getByTestId('action-save').click();
        await expect(page).toHaveURL(/\/admin\/switches\/\d+$/);

        await page.goto(`${page.url()}/edit`);
        await expect(page.getByTestId('switch-timezone')).toHaveValue('Europe/London');

        await page.getByTestId('switch-timezone').selectOption('America/New_York');
        await page.getByTestId('action-save').click();
        await expect(page.getByTestId('flash-message-success')).toContainText('Switch updated successfully');

        await page.reload();
        await expect(page.getByTestId('switch-timezone')).toHaveValue('America/New_York');
    });
});
