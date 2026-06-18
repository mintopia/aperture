import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { test as setup, expect } from '@playwright/test';
import { buildPlaywrightEnv, resolveBaseUrl } from '../../playwright/env.js';

const authFile = path.resolve(process.cwd(), 'playwright/.auth/admin.json');
const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL || 'playwright-admin@example.test';
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'playwright-password';
const playwrightEnv = buildPlaywrightEnv(resolveBaseUrl());

function ensureSqliteDatabaseFileExists() {
    if (playwrightEnv.DB_CONNECTION !== 'sqlite' || playwrightEnv.DB_DATABASE === ':memory:') {
        return;
    }

    fs.mkdirSync(path.dirname(playwrightEnv.DB_DATABASE), { recursive: true });
    const descriptor = fs.openSync(playwrightEnv.DB_DATABASE, 'a');
    fs.closeSync(descriptor);
}

function runArtisan(command) {
    execSync(command, {
        cwd: process.cwd(),
        stdio: 'inherit',
        env: {
            ...process.env,
            ...playwrightEnv,
        },
    });
}

setup('prepare deterministic e2e fixtures and sign in as admin', async ({ request }) => {
    fs.mkdirSync(path.dirname(authFile), { recursive: true });
    ensureSqliteDatabaseFileExists();

    runArtisan('php artisan migrate:fresh --force --no-interaction');
    runArtisan(
        [
            'php artisan aperture:e2e:prepare',
            `--email=${adminEmail}`,
            `--password=${adminPassword}`,
            '--nickname=playwright-admin',
            '--no-interaction',
        ].join(' ')
    );

    const loginPage = await request.get('/login');
    expect(loginPage.ok()).toBeTruthy();

    const stateBeforeLogin = await request.storageState();
    const xsrfCookie = stateBeforeLogin.cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrfCookie).toBeDefined();

    const loginResponse = await request.post('/login', {
        form: {
            email: adminEmail,
            password: adminPassword,
        },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
        },
    });

    expect([200, 302, 303]).toContain(loginResponse.status());

    const adminSwitchesResponse = await request.get('/admin/switches');
    expect(adminSwitchesResponse.ok()).toBeTruthy();

    await request.storageState({ path: authFile });
});
