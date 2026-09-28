import { test, expect } from '@playwright/test';
import { artisanAsync } from './support/fixtures.js';
import { startHttpStub } from './support/http-stub.js';

test.describe.configure({ mode: 'serial' });

const LEASE_IP = '10.99.0.20';
const LEASE_MAC = '02:e2:e2:00:00:01';
const runId = Date.now().toString(36);

function keaStub(hostname) {
    return startHttpStub(({ body }) => {
        const entry = (args) => ({ json: [{ result: 0, arguments: args }] });

        switch (body.command) {
            case 'list-commands':
                return entry(['list-commands', 'config-get', 'lease4-get-page', 'statistic-get']);
            case 'config-get':
                return entry({
                    Dhcp4: {
                        subnet4: [{ id: 1, subnet: '10.99.0.0/24', pools: [{ pool: '10.99.0.10 - 10.99.0.50' }] }],
                    },
                });
            case 'lease4-get-page':
                if (body.arguments?.from !== 'start') return entry({ leases: [] });
                return entry({
                    leases: [
                        {
                            'ip-address': LEASE_IP,
                            'hw-address': LEASE_MAC,
                            hostname,
                            state: 0,
                            cltt: Math.floor(Date.now() / 1000),
                            'valid-lft': 3600,
                        },
                    ],
                });
            default:
                return entry({});
        }
    });
}

async function configureKea(page, endpoint) {
    await page.goto('/admin/settings/integrations/kea');
    await page.getByTestId('field-input-endpoint_v4').fill(endpoint);
    for (const field of ['username_v4', 'password_v4', 'endpoint_v6', 'username_v6', 'password_v6']) {
        await page.getByTestId(`field-input-${field}`).fill('');
    }
    await page.getByTestId('action-save').click();
    await expect(
        page.getByTestId('flash-message-success').filter({ hasText: 'Integration settings updated' }),
    ).toBeVisible();

    const dhcp = page.getByTestId('capability-dhcp');
    if (!(await dhcp.innerText()).includes('On')) {
        await dhcp.click();
    }
    await expect(dhcp).toContainText('On');
}

async function releaseDhcpCapability(page) {
    await page.goto('/admin/settings/integrations/kea');
    const dhcp = page.getByTestId('capability-dhcp');
    if ((await dhcp.innerText()).includes('On')) {
        await dhcp.click();
        await expect(dhcp).toContainText('Off');
    }
}

test.describe('DHCP data after an integration sync', () => {
    let stub;

    test.afterEach(async ({ page }) => {
        await stub?.close();
        stub = undefined;
        await releaseDhcpCapability(page);
    });

    test('leases and ranges from Kea appear in the admin DHCP pages after a sync', async ({ page }) => {
        const hostname = `e2e-laptop-${runId}`;
        stub = await keaStub(hostname);
        await configureKea(page, stub.url);

        await page.goto('/admin/dhcp/leases');
        await expect(page.getByText(hostname)).toHaveCount(0);

        await artisanAsync('dhcp:sync');

        await page.goto('/admin/dhcp/leases');
        const row = page.getByTestId('data-table-row').filter({ hasText: LEASE_IP });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(hostname);
        await expect(row).toContainText(new RegExp(LEASE_MAC.replaceAll(':', ':?'), 'i'));

        await page.goto('/admin/dhcp');
        await expect(page.getByText('10.99.0.0/24').first()).toBeVisible();
        await expect(page.getByText('10.99.0.10').first()).toBeVisible();
        await expect(page.getByText('10.99.0.50').first()).toBeVisible();

        const commands = stub.requests.map((r) => r.body.command);
        expect(commands).toContain('lease4-get-page');
        expect(commands).toContain('config-get');
    });

    test('a later sync updates the lease hostname in place', async ({ page }) => {
        const hostname = `e2e-renamed-${runId}`;
        stub = await keaStub(hostname);
        await configureKea(page, stub.url);

        await artisanAsync('dhcp:sync');

        await page.goto('/admin/dhcp/leases');
        const row = page.getByTestId('data-table-row').filter({ hasText: LEASE_IP });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(hostname);
        await expect(page.getByText(`e2e-laptop-${runId}`)).toHaveCount(0);
    });

    test('nothing is imported when the DHCP capability is not assigned', async ({ page }) => {
        const hostname = `e2e-ignored-${runId}`;
        stub = await keaStub(hostname);
        await configureKea(page, stub.url);
        await releaseDhcpCapability(page);
        const before = stub.requests.length;

        await artisanAsync('dhcp:sync');

        expect(stub.requests.length).toBe(before);
        await page.goto('/admin/dhcp/leases');
        await expect(page.getByText(hostname)).toHaveCount(0);
    });
});
