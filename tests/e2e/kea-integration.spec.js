import http from 'node:http';
import { test, expect } from '@playwright/test';

/**
 * The Laravel backend, not the browser, makes the outbound "Test Connection"
 * request, so the stub binds to 127.0.0.1 where the app server can reach it.
 */
function startKeaStub(commands) {
    return new Promise((resolve) => {
        const server = http.createServer((req, res) => {
            req.on('data', () => {});
            req.on('end', () => {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify([{ result: 0, arguments: commands }]));
            });
        });
        server.listen(0, '127.0.0.1', () => {
            const { port } = server.address();
            resolve({ server, url: `http://127.0.0.1:${port}` });
        });
    });
}

function closeStub(stub) {
    return new Promise((resolve) => stub.server.close(resolve));
}

test.describe('Kea Integration (IPv4)', () => {
    // Tests share one persistent 'kea' integration row and depend on order.
    test.describe.configure({ mode: 'serial' });

    test('is reachable from the integrations list and shows its config page', async ({ page }) => {
        await page.goto('/admin/settings/integrations');
        await expect(page.getByTestId('integrations-table')).toBeVisible();
        const row = page.getByTestId('integration-row-kea');
        await expect(row).toBeVisible();

        await row.click();

        await expect(page).toHaveURL(/\/admin\/settings\/integrations\/kea$/);
        await expect(page.getByTestId('page-title')).toContainText('Kea');
        await expect(page.getByTestId('config-form')).toBeVisible();
        await expect(page.getByTestId('field-input-endpoint_v4')).toBeVisible();
        await expect(page.getByTestId('field-input-username_v4')).toBeVisible();
        await expect(page.getByTestId('field-input-password_v4')).toBeVisible();
        await expect(page.getByTestId('field-toggle-verify_ssl')).toBeVisible();
    });

    test('reports "no endpoint configured" when the IPv4 Endpoint is blank', async ({ page }) => {
        // Must run before any test below saves an endpoint.
        await page.goto('/admin/settings/integrations/kea');

        await page.getByTestId('action-test-connection').click();

        const panel = page.getByTestId('test-result-panel');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText(/no ipv4 endpoint configured/i);
    });

    test('saving the IPv4 endpoint persists across a reload', async ({ page }) => {
        await page.goto('/admin/settings/integrations/kea');

        await page.getByTestId('field-input-endpoint_v4').fill('https://kea.example.test:8000');
        await page.getByTestId('action-save').click();

        await expect(page.getByTestId('form-field-error')).toHaveCount(0);

        await page.reload();
        await expect(page.getByTestId('field-input-endpoint_v4')).toHaveValue('https://kea.example.test:8000');
    });

    test('setting a username without a password fails validation and does not save', async ({ page }) => {
        await page.goto('/admin/settings/integrations/kea');

        await expect(page.getByTestId('field-input-username_v4')).toHaveValue('');

        await page.getByTestId('field-input-username_v4').fill('kea-admin');
        await page.getByTestId('field-input-password_v4').fill('');
        await page.getByTestId('action-save').click();

        const error = page.getByTestId('form-field-error');
        await expect(error).toBeVisible();
        await expect(error).toContainText(/password/i);

        await page.reload();
        await expect(page.getByTestId('field-input-username_v4')).toHaveValue('');
    });

    test('setting a password without a username fails validation and does not save', async ({ page }) => {
        await page.goto('/admin/settings/integrations/kea');

        await expect(page.getByTestId('field-input-password_v4')).toHaveValue('');

        await page.getByTestId('field-input-username_v4').fill('');
        await page.getByTestId('field-input-password_v4').fill('kea-secret');
        await page.getByTestId('action-save').click();

        const error = page.getByTestId('form-field-error');
        await expect(error).toBeVisible();
        await expect(error).toContainText(/username/i);

        await page.reload();
        await expect(page.getByTestId('field-input-password_v4')).toHaveValue('');
    });

    test('toggles the DHCP and IP-MAC capability assignment', async ({ page }) => {
        await page.goto('/admin/settings/integrations/kea');

        const dhcp = page.getByTestId('capability-dhcp');
        const ipMac = page.getByTestId('capability-ip-mac');
        await expect(dhcp).toBeVisible();
        await expect(ipMac).toBeVisible();

        const dhcpWasOn = (await dhcp.innerText()).includes('On');
        await dhcp.click();
        await expect(dhcp).toContainText(dhcpWasOn ? 'Off' : 'On');

        const ipMacWasOn = (await ipMac.innerText()).includes('On');
        await ipMac.click();
        await expect(ipMac).toContainText(ipMacWasOn ? 'Off' : 'On');
    });

    test('test connection succeeds against a stubbed Kea endpoint', async ({ page }) => {
        const stub = await startKeaStub(['list-commands', 'config-get', 'lease4-get-page', 'statistic-get']);

        try {
            await page.goto('/admin/settings/integrations/kea');
            await page.getByTestId('field-input-endpoint_v4').fill(stub.url);
            await page.getByTestId('action-test-connection').click();

            const panel = page.getByTestId('test-result-panel');
            await expect(panel).toBeVisible();
            await expect(panel).toContainText(/connected to the ipv4 endpoint successfully/i);
            await expect(page.getByTestId('test-request-detail')).toContainText(stub.url);
        } finally {
            await closeStub(stub);
        }
    });

    test('test connection reports a missing lease_cmds hook', async ({ page }) => {
        const stub = await startKeaStub(['list-commands', 'config-get']);

        try {
            await page.goto('/admin/settings/integrations/kea');
            await page.getByTestId('field-input-endpoint_v4').fill(stub.url);
            await page.getByTestId('action-test-connection').click();

            const panel = page.getByTestId('test-result-panel');
            await expect(panel).toBeVisible();
            await expect(panel).toContainText(/lease_cmds hook appears to be missing/i);
            await expect(panel).toContainText(/lease4-get-page/);
        } finally {
            await closeStub(stub);
        }
    });
});
