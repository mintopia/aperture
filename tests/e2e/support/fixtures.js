import { execFile, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { promisify } from 'node:util';
import { buildPlaywrightEnv, resolveBaseUrl } from '../../../playwright/env.js';

export function artisan(...args) {
    execFileSync('php', ['artisan', ...args, '--no-interaction'], {
        cwd: process.cwd(),
        env: { ...process.env, ...buildPlaywrightEnv(resolveBaseUrl()) },
        stdio: 'pipe',
    });
}

// Non-blocking, so in-process HTTP stubs can answer while artisan runs.
export function artisanAsync(...args) {
    return promisify(execFile)('php', ['artisan', ...args, '--no-interaction'], {
        cwd: process.cwd(),
        env: { ...process.env, ...buildPlaywrightEnv(resolveBaseUrl()) },
    });
}

export function prepareFixtures() {
    artisan('aperture:e2e:prepare');
}

export async function saveLoginState(browser, { email, password }, file) {
    const context = await browser.newContext({ baseURL: resolveBaseUrl(), storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(password);
    const login = page.waitForResponse((r) => r.url().endsWith('/login') && r.request().method() === 'POST');
    await page.getByTestId('login-submit').click();
    await login;
    const state = await context.storageState();
    fs.mkdirSync(path.dirname(file), { recursive: true });
    const temp = `${file}.${process.pid}.tmp`;
    fs.writeFileSync(temp, JSON.stringify(state));
    fs.renameSync(temp, file);
    await context.close();
}
