import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { buildPlaywrightEnv, localFallbackUrls, resolveBaseUrl } from './playwright/env.js';

const baseURL = resolveBaseUrl();
const authFile = 'playwright/.auth/admin.json';
const shouldStartWebServer = localFallbackUrls.includes(baseURL);
const includeMobileProjects = process.env.PLAYWRIGHT_INCLUDE_MOBILE === '1';
const playwrightEnv = buildPlaywrightEnv(baseURL);
const firstRunURL = process.env.PLAYWRIGHT_FIRST_RUN_URL || 'http://127.0.0.1:8010';
const firstRunPort = new URL(firstRunURL).port || '8010';
const firstRunDatabase = process.env.PLAYWRIGHT_FIRST_RUN_DB || 'database/playwright-first-run.sqlite';
const passkeyURL = process.env.PLAYWRIGHT_PASSKEY_URL || 'http://localhost:8020';
const passkeyPort = new URL(passkeyURL).port || '8020';
const passkeyTest = /passkey\.spec\.js/;
const firstRunTest = /first-run\.spec\.js/;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [['html', { outputFolder: 'storage/playwright-report' }]],
    globalTimeout: 10 * 60 * 1000,
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    webServer: shouldStartWebServer
        ? [
              {
                  command: 'php artisan serve --host=127.0.0.1 --port=8000',
                  url: `${baseURL}/login`,
                  reuseExistingServer: true,
                  timeout: 120 * 1000,
                  env: {
                      ...process.env,
                      ...playwrightEnv,
                  },
              },
              {
                  command: `php artisan serve --host=127.0.0.1 --port=${passkeyPort}`,
                  url: `${passkeyURL}/login`,
                  reuseExistingServer: false,
                  timeout: 120 * 1000,
                  env: {
                      ...process.env,
                      ...playwrightEnv,
                      APP_URL: passkeyURL,
                  },
              },
              {
                  command: `touch ${firstRunDatabase} && php artisan migrate:fresh --force --no-interaction && php artisan serve --host=127.0.0.1 --port=${firstRunPort}`,
                  url: `${firstRunURL}/setup`,
                  reuseExistingServer: false,
                  timeout: 120 * 1000,
                  env: {
                      ...process.env,
                      ...playwrightEnv,
                      APP_URL: firstRunURL,
                      DB_CONNECTION: 'sqlite',
                      DB_DATABASE: path.resolve(process.cwd(), firstRunDatabase),
                  },
              },
          ]
        : undefined,
    projects: [
        {
            name: 'setup',
            testMatch: /.*\.setup\.js/,
        },
        {
            name: 'first-run',
            testMatch: firstRunTest,
            use: { ...devices['Desktop Chrome'], baseURL: firstRunURL },
        },
        {
            name: 'passkey',
            testMatch: passkeyTest,
            use: {
                ...devices['Desktop Chrome'],
                baseURL: passkeyURL,
                storageState: { cookies: [], origins: [] },
                launchOptions: { args: ['--host-resolver-rules=MAP localhost 127.0.0.1'] },
            },
            dependencies: ['setup'],
        },
        {
            name: 'chromium',
            testIgnore: [firstRunTest, passkeyTest],
            use: { ...devices['Desktop Chrome'], storageState: authFile },
            dependencies: ['setup'],
        },
        ...(includeMobileProjects
            ? [
                  { name: 'mobile', testIgnore: [firstRunTest, passkeyTest], use: { ...devices['iPhone 13'], storageState: authFile }, dependencies: ['setup'] },
                  { name: 'tablet', testIgnore: [firstRunTest, passkeyTest], use: { ...devices['iPad (gen 7)'], storageState: authFile }, dependencies: ['setup'] },
              ]
            : []),
    ],
});
