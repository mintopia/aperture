import crypto from 'node:crypto';
import http from 'node:http';
import { test, expect } from '@playwright/test';
import { artisan, prepareFixtures, saveLoginState } from './support/fixtures.js';

test.describe('Portal Dashboard (S3)', () => {
    test('renders page with layout', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
    });

    test('renders portal header', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-header')).toBeVisible();
    });

    test('mobile viewport stacks content', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 });
        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
    });

    test('portal renders app logo', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('app-logo')).toBeVisible();
    });
});

test.describe('Portal User Menu', () => {
    test('user menu trigger is accessible', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('user-menu-trigger')).toBeVisible();
    });

    test('user menu opens and shows logout option', async ({ page }) => {
        await page.goto('/portal');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-dropdown')).toBeVisible();
        await expect(page.getByTestId('user-menu-logout')).toBeVisible();
    });

    test('user menu shows admin link for admin user', async ({ page }) => {
        await page.goto('/portal');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-admin')).toBeVisible();
    });
});

test.describe('Portal Footer', () => {
    test('footer is visible', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('portal-footer')).toBeVisible();
    });

    test('footer contains Mintopia credit link', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('footer-heart')).toBeVisible();
        await expect(page.getByTestId('footer-mintopia')).toBeVisible();
    });
});

const attendee = { email: 'playwright-attendee@example.test', password: 'playwright-attendee-password' };
const dnsCheckUrl = 'http://dns-check.e2e.invalid/**';
const ipv6CheckUrl = 'http://ipv6-check.e2e.invalid/**';

const attendeeState = 'playwright/.auth/attendee.json';

async function mockDns(page, server) {
    await page.route(dnsCheckUrl, (route) => route.fulfill({ json: { server } }));
}

test.describe('Attendee portal', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: attendeeState });

    test.beforeAll(async ({ browser }) => {
        prepareFixtures();
        await saveLoginState(browser, attendee, attendeeState);
    });

    test.beforeEach(async ({ page }) => {
        await mockDns(page, 'event');
    });

    test('attendee dashboard greets user and hides admin link', async ({ page }) => {
        await page.goto('/portal');
        await expect(page.getByTestId('page-title')).toContainText('playwright-attendee');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-logout')).toBeVisible();
        await expect(page.getByTestId('user-menu-admin')).toHaveCount(0);
    });

    test.describe('ad-block toggle', () => {
        test.describe.configure({ mode: 'serial' });

        test.afterEach(async ({ page }) => {
            await page.goto('/portal');
            const toggle = page.getByTestId('dns-filter-toggle');
            await expect(toggle).toBeVisible();
            if ((await toggle.getAttribute('aria-checked')) === 'true') {
                const reset = page.waitForResponse((r) => r.url().endsWith('/portal/dns-filter/toggle'));
                await toggle.click();
                await reset;
            }
        });

        test('toggling persists across reloads', async ({ page }) => {
            await page.goto('/portal');
            const toggle = page.getByTestId('dns-filter-toggle');
            await expect(toggle).toHaveAttribute('aria-checked', 'false');

            const enable = page.waitForResponse((r) => r.url().endsWith('/portal/dns-filter/toggle'));
            await toggle.click();
            expect((await enable).status()).toBe(200);
            expect(await (await enable).json()).toEqual({ enabled: true });
            await expect(toggle).toHaveAttribute('aria-checked', 'true');

            await page.reload();
            await expect(page.getByTestId('dns-filter-toggle')).toHaveAttribute('aria-checked', 'true');

            const disable = page.waitForResponse((r) => r.url().endsWith('/portal/dns-filter/toggle'));
            await page.getByTestId('dns-filter-toggle').click();
            expect(await (await disable).json()).toEqual({ enabled: false });
            await page.reload();
            await expect(page.getByTestId('dns-filter-toggle')).toHaveAttribute('aria-checked', 'false');
        });

        test('failed toggle reverts the switch', async ({ page }) => {
            await page.route('**/portal/dns-filter/toggle', (route) => route.fulfill({ status: 500, json: {} }));
            await page.goto('/portal');
            const toggle = page.getByTestId('dns-filter-toggle');
            await toggle.click();
            await expect(toggle).toHaveAttribute('aria-checked', 'false');
        });
    });

    test.describe('bandwidth graph', () => {
        test('stats endpoint returns bandwidth payload', async ({ page }) => {
            const response = await page.request.get('/portal/stats/bandwidth?range=1h');
            expect(response.status()).toBe(200);
            expect(response.headers()['cache-control']).toContain('no-store');
            const body = await response.json();
            expect(Object.keys(body).sort()).toEqual([
                'download',
                'timestamps',
                'totalReceived',
                'totalSent',
                'upload',
            ]);
        });

        test('renders totals and chart from returned samples', async ({ page }) => {
            const now = Math.floor(Date.now() / 1000);
            await page.route('**/portal/stats/bandwidth*', (route) =>
                route.fulfill({
                    json: {
                        timestamps: [now - 120, now - 60, now],
                        download: [1000, 2000, 3000],
                        upload: [100, 200, 300],
                        totalReceived: 5 * 1024 * 1024,
                        totalSent: 2 * 1024 * 1024,
                    },
                }),
            );
            await page.goto('/portal');
            await expect(page.getByTestId('bandwidth-download')).toContainText('5');
            await expect(page.getByTestId('bandwidth-upload')).toContainText('2');
            await expect(page.getByTestId('chart-canvas')).toBeVisible();
        });

        test('shows empty chart state without samples and switches range', async ({ page }) => {
            await page.goto('/portal');
            await expect(page.getByTestId('chart-empty')).toBeVisible();

            const ranged = page.waitForRequest(
                (r) => r.url().includes('/portal/stats/bandwidth') && r.url().includes('range=24h'),
            );
            await page.getByTestId('bandwidth-range-24h').click();
            await ranged;
        });
    });

    test.describe('DNS warning', () => {
        test('is shown when the device uses foreign DNS', async ({ page }) => {
            await mockDns(page, 'other');
            await page.goto('/portal');
            await expect(page.getByTestId('block-dns-warning')).toContainText('Playwright DNS warning');
        });

        test('is hidden when the device uses event DNS', async ({ page }) => {
            const checked = page.waitForRequest((r) => r.url().includes('dns-check.e2e.invalid'));
            await page.goto('/portal');
            await checked;
            await expect(page.getByTestId('portal-layout')).toBeVisible();
            await expect(page.getByTestId('block-dns-warning')).toHaveCount(0);
        });
    });

    test.describe('status page', () => {
        test('/status reports client ip and internet state', async ({ page }) => {
            const response = await page.request.get('/status');
            expect(response.status()).toBe(200);
            const body = await response.json();
            expect(body.ip).toBe('127.0.0.1');
            expect(typeof body.internetEnabled).toBe('boolean');
        });

        test('root page shows the connection status and ip', async ({ page }) => {
            await page.route(ipv6CheckUrl, (route) => route.abort());
            await page.goto('/');
            await expect(page.getByTestId('portal-page')).toBeVisible();
            await expect(page.getByTestId('portal-ip')).toContainText('127.0.0.1');
        });

        test('root page warns about foreign DNS once connected', async ({ page }) => {
            await mockDns(page, 'other');
            await page.route(ipv6CheckUrl, (route) => route.abort());
            await page.goto('/');
            await expect(page.getByTestId('portal-dns-warning')).toBeVisible();
        });
    });
});

test.describe('Attendee IPv6 registration', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: attendeeState });

    const ipv6Address = '2001:db8::e2e';
    let jwksServer;
    let privateKey;

    const tinker = (code) => artisan('tinker', '--execute', code);

    function signToken(sid) {
        const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');
        const header = encode({ alg: 'RS256', typ: 'JWT', kid: 'e2e' });
        const payload = encode({ sub: ipv6Address, sid, exp: Math.floor(Date.now() / 1000) + 3600 });
        const signature = crypto
            .sign('RSA-SHA256', Buffer.from(`${header}.${payload}`), privateKey)
            .toString('base64url');
        return `${header}.${payload}.${signature}`;
    }

    test.beforeAll(async ({ browser }) => {
        const { publicKey, privateKey: signingKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
        const jwks = { keys: [{ ...publicKey.export({ format: 'jwk' }), kid: 'e2e', alg: 'RS256', use: 'sig' }] };
        privateKey = signingKey;

        jwksServer = http.createServer((_req, res) => {
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify(jwks));
        });
        await new Promise((resolve) => jwksServer.listen(0, '127.0.0.1', resolve));
        const { port } = jwksServer.address();
        prepareFixtures();
        await saveLoginState(browser, attendee, attendeeState);
        tinker(`App\\Models\\IntegrationConfig::setValue('ipv6', 'jwks_url', 'http://127.0.0.1:${port}/jwks.json');`);
    });

    test.afterAll(async () => {
        tinker("App\\Models\\IntegrationConfig::setValue('ipv6', 'jwks_url', '');");
        await new Promise((resolve) => jwksServer.close(resolve));
    });

    test.beforeEach(async ({ page }) => {
        await page.route(dnsCheckUrl, (route) => route.fulfill({ json: { server: 'event' } }));
    });

    test('dashboard detects and registers the device IPv6 address', async ({ page }) => {
        await page.route(ipv6CheckUrl, (route) =>
            route.fulfill({ json: { token: signToken(new URL(route.request().url()).searchParams.get('sid')) } }),
        );
        const registered = page.waitForResponse((r) => r.url().endsWith('/ipv6') && r.request().method() === 'POST');
        await page.goto('/portal');

        const response = await registered;
        expect(response.status()).toBe(200);
        expect((await response.json()).ip).toBe(ipv6Address);
        await expect(page.getByTestId('connection-strip-field-1')).toContainText(ipv6Address);
    });

    test('rejects a token that fails signature verification', async ({ page }) => {
        const [header, payload] = signToken('unbound').split('.');
        const response = await page.request.post('/ipv6', { data: { token: `${header}.${payload}.invalid` } });
        expect(response.status()).toBe(422);
        expect(await response.json()).toEqual({ error: 'Invalid token' });
    });

    test('rejects a validly signed token that is not bound to the session', async ({ page }) => {
        const response = await page.request.post('/ipv6', { data: { token: signToken('someone-elses-session') } });
        expect(response.status()).toBe(422);
    });

    test('requires a token', async ({ page }) => {
        const response = await page.request.post('/ipv6', { data: {}, headers: { Accept: 'application/json' } });
        expect(response.status()).toBe(422);
    });
});
