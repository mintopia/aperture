import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { test, expect } from './support/csp-guard.js';
import { buildPlaywrightEnv, resolveBaseUrl } from '../../playwright/env.js';

const adminEmail = 'first-run-admin@example.test';
const adminPassword = 'first-run-password-123';

test.describe('first-run setup', () => {
    test.describe.configure({ mode: 'serial' });

    // The journey creates the first admin, so each pass (e.g. --repeat-each) needs an empty install again.
    test.beforeAll(() => {
        execFileSync('php', ['artisan', 'migrate:fresh', '--force', '--no-interaction'], {
            cwd: process.cwd(),
            stdio: 'pipe',
            env: {
                ...process.env,
                ...buildPlaywrightEnv(resolveBaseUrl()),
                APP_URL: test.info().project.use.baseURL,
                DB_CONNECTION: 'sqlite',
                DB_DATABASE: path.resolve(
                    process.cwd(),
                    process.env.PLAYWRIGHT_FIRST_RUN_DB || 'database/playwright-first-run.sqlite',
                ),
            },
        });
    });

    test('a fresh install redirects every page to /setup', async ({ page }) => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/setup$/);
        await expect(page.getByTestId('setup-title')).toBeVisible();

        await page.goto('/login');
        await expect(page).toHaveURL(/\/setup$/);
    });

    test('rejects a mismatched password confirmation', async ({ page }) => {
        await page.goto('/setup');
        await page.getByTestId('setup-email').fill(adminEmail);
        await page.getByTestId('setup-password').fill(adminPassword);
        await page.getByTestId('setup-password-confirmation').fill('something-else-entirely');
        await page.getByTestId('setup-submit').click();

        await expect(page).toHaveURL(/\/setup$/);
        await expect(page.getByTestId('setup-form')).toContainText(/confirmation does not match/i);
    });

    test('completing the form creates the admin and signs them in', async ({ page }) => {
        await page.goto('/setup');
        await page.getByTestId('setup-email').fill(adminEmail);
        await page.getByTestId('setup-password').fill(adminPassword);
        await page.getByTestId('setup-password-confirmation').fill(adminPassword);
        await page.getByTestId('setup-submit').click();

        await expect(page).toHaveURL(/\/admin(\/|$)/);
        await expect(page.getByTestId('page-title')).toBeVisible();

        await page.goto('/admin/switches');
        await expect(page).toHaveURL(/\/admin\/switches/);
    });

    test('setup is closed once an admin exists', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/setup');
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByTestId('login-form')).toBeVisible();
    });
});
