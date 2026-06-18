import { defineConfig, devices } from '@playwright/test';
import { buildPlaywrightEnv, localFallbackUrls, resolveBaseUrl } from './playwright/env.js';

const baseURL = resolveBaseUrl();
const authFile = 'playwright/.auth/admin.json';
const shouldStartWebServer = localFallbackUrls.includes(baseURL);
const includeMobileProjects = process.env.PLAYWRIGHT_INCLUDE_MOBILE === '1';
const playwrightEnv = buildPlaywrightEnv(baseURL);

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
        ? {
              command: 'php artisan serve --host=127.0.0.1 --port=8000',
              url: `${baseURL}/login`,
              reuseExistingServer: true,
              timeout: 120 * 1000,
              env: {
                  ...process.env,
                  ...playwrightEnv,
              },
          }
        : undefined,
    projects: [
        {
            name: 'setup',
            testMatch: /.*\.setup\.js/,
        },
        { name: 'chromium', use: { ...devices['Desktop Chrome'], storageState: authFile }, dependencies: ['setup'] },
        ...(includeMobileProjects
            ? [
                  { name: 'mobile', use: { ...devices['iPhone 13'], storageState: authFile }, dependencies: ['setup'] },
                  { name: 'tablet', use: { ...devices['iPad (gen 7)'], storageState: authFile }, dependencies: ['setup'] },
              ]
            : []),
    ],
});
