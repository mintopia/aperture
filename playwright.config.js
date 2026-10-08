import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import { buildPlaywrightEnv, localFallbackUrls, resolveBaseUrl, sshProxyStubPort } from './playwright/env.js';

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
// These specs mutate global state (the DHCP capability assignment, Kea integration config, synced leases)
// that other specs read, so they run in their own projects after every parallel project has finished.
const dhcpSyncTest = /admin-dhcp-sync\.spec\.js/;
const keaIntegrationTest = /kea-integration\.spec\.js/;
const parallelProjects = ['chromium', ...(includeMobileProjects ? ['mobile', 'tablet'] : [])];
const sharedStateTests = [dhcpSyncTest, keaIntegrationTest];

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [['html', { outputFolder: 'storage/playwright-report', open: 'never' }], ...(process.env.CI ? [['list']] : [])],
    globalTimeout: (Number(process.env.PLAYWRIGHT_GLOBAL_TIMEOUT_MINUTES) || (process.env.CI ? 25 : 10)) * 60 * 1000,
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
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
              {
                  command: 'node tests/e2e/support/ssh-proxy-stub.js',
                  url: `http://127.0.0.1:${sshProxyStubPort}/health`,
                  reuseExistingServer: true,
                  timeout: 30 * 1000,
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
            // One install database: concurrent copies (e.g. --repeat-each) would race on it.
            workers: 1,
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
            testIgnore: [firstRunTest, passkeyTest, ...sharedStateTests],
            use: { ...devices['Desktop Chrome'], storageState: authFile },
            dependencies: ['setup'],
        },
        {
            name: 'shared-state-dhcp-sync',
            workers: 1,
            testMatch: dhcpSyncTest,
            use: { ...devices['Desktop Chrome'], storageState: authFile },
            dependencies: parallelProjects,
        },
        {
            name: 'shared-state-kea',
            workers: 1,
            testMatch: keaIntegrationTest,
            use: { ...devices['Desktop Chrome'], storageState: authFile },
            dependencies: ['shared-state-dhcp-sync'],
        },
        ...(includeMobileProjects
            ? [
                  { name: 'mobile', testIgnore: [firstRunTest, passkeyTest, ...sharedStateTests], use: { ...devices['iPhone 13'], storageState: authFile }, dependencies: ['setup'] },
                  { name: 'tablet', testIgnore: [firstRunTest, passkeyTest, ...sharedStateTests], use: { ...devices['iPad (gen 7)'], storageState: authFile }, dependencies: ['setup'] },
              ]
            : []),
    ],
});
