import { test, expect } from './support/csp-guard.js';
import { startHttpStub } from './support/http-stub.js';
import { createSwitch, uniqueSwitch } from './support/switches.js';

test.describe.configure({ mode: 'default' });

async function openIntegration(page, service) {
    await page.goto('/admin/settings/integrations');
    await page.getByTestId(`integration-row-${service}`).click();
    await expect(page).toHaveURL(new RegExp(`/admin/settings/integrations/${service}$`));
    await expect(page.getByTestId('config-form')).toBeVisible();
}

async function saveConfig(page) {
    await page.getByTestId('action-save').click();
    await expect(
        page.getByTestId('flash-message-success').filter({ hasText: 'Integration settings updated' }),
    ).toBeVisible();
}

async function runTest(page) {
    await page.getByTestId('action-test-connection').click();
    const panel = page.getByTestId('test-result-panel');
    await expect(panel).toBeVisible();
    return panel;
}

test.describe('OPNsense integration', () => {
    test('saves its config and persists it across a reload', async ({ page }) => {
        await openIntegration(page, 'opnsense');
        await expect(page.getByTestId('page-title')).toContainText('OPNsense');

        await page.getByTestId('field-input-endpoint').fill('https://opnsense.e2e.test');
        await page.getByTestId('field-input-key').fill('e2e-key');
        await page.getByTestId('field-input-secret').fill('e2e-secret');
        const toggle = page.getByTestId('field-toggle-verify_ssl');
        const wasOn = (await toggle.getAttribute('aria-checked')) === 'true';
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-checked', String(!wasOn));
        await saveConfig(page);

        await page.reload();
        await expect(page.getByTestId('field-input-endpoint')).toHaveValue('https://opnsense.e2e.test');
        await expect(page.getByTestId('field-toggle-verify_ssl')).toHaveAttribute('aria-checked', String(!wasOn));
    });

    test('rejects a malformed endpoint', async ({ page }) => {
        await openIntegration(page, 'opnsense');
        await page.getByTestId('field-input-endpoint').fill('not a url');
        await page.getByTestId('action-save').click();

        await expect(page.getByTestId('form-field-endpoint').getByTestId('form-field-error')).toBeVisible();
    });

    test('test connection authenticates against the API with the key and secret', async ({ page }) => {
        const stub = await startHttpStub(({ path }) =>
            path === '/api/diagnostics/system/system_time'
                ? { json: { datetime: 'Mon Jan 1 00:00:00 UTC 2026' } }
                : { status: 404 },
        );

        try {
            await openIntegration(page, 'opnsense');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-key').fill('e2e-key');
            await page.getByTestId('field-input-secret').fill('e2e-secret');

            const panel = await runTest(page);

            await expect(panel).toContainText('Connected and authenticated successfully');
            await expect(page.getByTestId('test-request-detail')).toContainText(
                `${stub.url}/api/diagnostics/system/system_time`,
            );
            expect(stub.requests.at(-1).headers.authorization).toBe(
                `Basic ${Buffer.from('e2e-key:e2e-secret').toString('base64')}`,
            );
        } finally {
            await stub.close();
        }
    });

    test('test connection reports bad credentials', async ({ page }) => {
        const stub = await startHttpStub(() => ({ status: 401, json: { status: 'unauthorized' } }));

        try {
            await openIntegration(page, 'opnsense');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-key').fill('wrong');
            await page.getByTestId('field-input-secret').fill('wrong');

            await expect(await runTest(page)).toContainText(/connection failed.*401/i);
        } finally {
            await stub.close();
        }
    });
});

test.describe('VyOS integration', () => {
    test('saves its config and persists it across a reload', async ({ page }) => {
        await openIntegration(page, 'vyos');
        await expect(page.getByTestId('page-title')).toContainText('VyOS');

        await page.getByTestId('field-input-endpoint').fill('https://vyos.e2e.test');
        await page.getByTestId('field-input-api_key').fill('e2e-vyos-key');
        await page.getByTestId('field-input-pool_size').fill('100');
        await saveConfig(page);

        await page.reload();
        await expect(page.getByTestId('field-input-endpoint')).toHaveValue('https://vyos.e2e.test');
        await expect(page.getByTestId('field-input-pool_size')).toHaveValue('100');
    });

    test('rejects a non-numeric pool size', async ({ page }) => {
        await openIntegration(page, 'vyos');
        await page.getByTestId('field-input-pool_size').fill('lots');
        await page.getByTestId('action-save').click();

        await expect(page.getByTestId('form-field-pool_size').getByTestId('form-field-error')).toBeVisible();
    });

    test('test connection sends the API key and reports success', async ({ page }) => {
        const stub = await startHttpStub(() => ({ json: { success: true, data: 'VyOS 1.4', error: null } }));

        try {
            await openIntegration(page, 'vyos');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-api_key').fill('e2e-vyos-key');

            const panel = await runTest(page);

            await expect(panel).toContainText('Connected and authenticated successfully');
            await expect(page.getByTestId('test-request-detail')).toContainText(`POST ${stub.url}/show`);
            expect(new URLSearchParams(stub.requests.at(-1).raw).get('key')).toBe('e2e-vyos-key');
        } finally {
            await stub.close();
        }
    });

    test('test connection surfaces an API-level rejection', async ({ page }) => {
        const stub = await startHttpStub(() => ({ json: { success: false, error: 'Invalid API key' } }));

        try {
            await openIntegration(page, 'vyos');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-api_key').fill('bad');

            await expect(await runTest(page)).toContainText('VyOS API error: Invalid API key');
        } finally {
            await stub.close();
        }
    });
});

test.describe('LibreNMS integration', () => {
    test('saves its config and persists it across a reload', async ({ page }) => {
        await openIntegration(page, 'librenms');
        await expect(page.getByTestId('page-title')).toContainText('LibreNMS');

        await page.getByTestId('field-input-endpoint').fill('https://librenms.e2e.test');
        await page.getByTestId('field-input-api_key').fill('e2e-librenms-token');
        await saveConfig(page);

        await page.reload();
        await expect(page.getByTestId('field-input-endpoint')).toHaveValue('https://librenms.e2e.test');
    });

    test('test connection sends the API token and reports success', async ({ page }) => {
        const stub = await startHttpStub(() => ({ json: { status: 'ok', message: 'API v0' } }));

        try {
            await openIntegration(page, 'librenms');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-api_key').fill('e2e-librenms-token');

            const panel = await runTest(page);

            await expect(panel).toContainText('Connected successfully');
            await expect(page.getByTestId('test-request-detail')).toContainText(`GET ${stub.url}/api/v0`);
            expect(stub.requests.at(-1).headers['x-auth-token']).toBe('e2e-librenms-token');
        } finally {
            await stub.close();
        }
    });

    test('test connection reports a rejected token', async ({ page }) => {
        const stub = await startHttpStub(() => ({ status: 401, json: { message: 'Unauthenticated.' } }));

        try {
            await openIntegration(page, 'librenms');
            await page.getByTestId('field-input-endpoint').fill(stub.url);
            await page.getByTestId('field-input-api_key').fill('bad');

            await expect(await runTest(page)).toContainText(/connection failed.*401/i);
        } finally {
            await stub.close();
        }
    });
});

test.describe('Cisco integration', () => {
    test('test connection asks the operator to pick a switch first', async ({ page }) => {
        await openIntegration(page, 'cisco');
        await page.getByTestId('field-switch_id').selectOption('');
        await saveConfig(page);

        await expect(await runTest(page)).toContainText('No switch configured');
    });

    test('selected switch is saved and test connection queries DHCP pools over the ssh-proxy', async ({ page }) => {
        const sw = uniqueSwitch('CiscoDhcp');
        await createSwitch(page, sw);

        await openIntegration(page, 'cisco');
        await page.getByTestId('field-switch_id').selectOption({ label: sw.name });
        await saveConfig(page);

        await page.reload();
        await expect(page.getByTestId('field-switch_id').locator('option:checked')).toHaveText(sw.name);

        const panel = await runTest(page);
        await expect(panel).toContainText(`Connected to ${sw.hostname}`);
        await expect(panel).toContainText('DHCP pools accessible');
        await expect(page.getByTestId('test-request-detail')).toContainText(`SSH ${sw.hostname}`);
    });

    test('test connection fails when the ssh-proxy cannot reach the switch', async ({ page }) => {
        const id = Date.now();
        const sw = { name: `E2E Cisco Unreachable ${id}`, hostname: `unreachable-${id}.switch.test` };
        await createSwitch(page, sw);

        await openIntegration(page, 'cisco');
        await page.getByTestId('field-switch_id').selectOption({ label: sw.name });

        await expect(await runTest(page)).toContainText(/connection failed/i);
    });
});
