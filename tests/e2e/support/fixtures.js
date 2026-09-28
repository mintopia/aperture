import { execFileSync } from 'node:child_process';
import { buildPlaywrightEnv, resolveBaseUrl } from '../../../playwright/env.js';

export function artisan(...args) {
    execFileSync('php', ['artisan', ...args, '--no-interaction'], {
        cwd: process.cwd(),
        env: { ...process.env, ...buildPlaywrightEnv(resolveBaseUrl()) },
        stdio: 'pipe',
    });
}

export function prepareFixtures() {
    artisan('aperture:e2e:prepare');
}

export async function saveLoginState(browser, { email, password }, file) {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(password);
    const login = page.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST');
    await page.getByTestId('login-submit').click();
    await login;
    await context.storageState({ path: file });
    await context.close();
}
