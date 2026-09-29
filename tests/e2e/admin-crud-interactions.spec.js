import { test, expect } from './support/test.js';
import { artisan } from './support/fixtures.js';

async function openUserEdit(page, nickname) {
    await page.goto(`/admin/users?search=${nickname}`);
    await page.getByTestId('data-table-row').filter({ hasText: nickname }).click();
    await page.getByTestId('action-edit').click();
    await expect(page.getByTestId('edit-user-form')).toBeVisible();
}

function uniqueAddress() {
    const suffix = Date.now() % 60000;
    return `10.98.${Math.floor(suffix / 250)}.${(suffix % 250) + 1}`;
}

test.describe('IP create', () => {
    test('creates an IP with internet and rate limit enabled and persists it', async ({ page }) => {
        const address = uniqueAddress();
        await page.goto('/admin/ips');
        await page.getByTestId('action-create-ip').click();
        await expect(page.getByTestId('ip-create-form')).toBeVisible();

        await page.getByTestId('input-address').fill(address);
        await page.getByTestId('input-comment').fill('created by e2e');
        await page.getByTestId('field-allow').check();
        await page.getByTestId('field-limit').check();
        await page.getByTestId('action-submit').click();

        await expect(page).toHaveURL(new RegExp(`/admin/ips/${address.replaceAll('.', '\\.')}$`));
        await expect(page.getByTestId('page-title')).toHaveText(address);
        await expect(page.getByTestId('action-revoke')).toBeVisible();
        await expect(page.getByTestId('action-disable-rate-limit')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('action-revoke')).toBeVisible();
        await expect(page.getByTestId('action-disable-rate-limit')).toBeVisible();

        await page.goto(`/admin/ips?address=${address}`);
        const row = page.getByTestId('data-table-row').filter({ hasText: address });
        await expect(row.getByTestId('ip-status')).toHaveText('Allowed');
    });

    test('creates an IP with defaults as blocked and not rate limited', async ({ page }) => {
        const address = uniqueAddress();
        await page.goto('/admin/ips/create');
        await page.getByTestId('input-address').fill(address);
        await page.getByTestId('action-submit').click();

        await expect(page.getByTestId('page-title')).toHaveText(address);
        await expect(page.getByTestId('action-grant')).toBeVisible();
        await expect(page.getByTestId('action-enable-rate-limit')).toBeVisible();
    });

    test('rejects an invalid address and keeps the form', async ({ page }) => {
        await page.goto('/admin/ips/create');
        await page.getByTestId('input-address').fill('not-an-ip');
        await page.getByTestId('action-submit').click();

        await expect(page.getByTestId('form-field-address').getByTestId('form-field-error')).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/ips\/create$/);
        await expect(page.getByTestId('input-address')).toHaveValue('not-an-ip');
    });

    test('rejects a duplicate address', async ({ page }) => {
        await page.goto('/admin/ips/create');
        await page.getByTestId('input-address').fill('10.99.1.10');
        await page.getByTestId('action-submit').click();

        await expect(page.getByTestId('form-field-address').getByTestId('form-field-error')).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/ips\/create$/);
    });

    test('rejects an over-long comment', async ({ page }) => {
        await page.goto('/admin/ips/create');
        await page.getByTestId('input-address').fill(uniqueAddress());
        await page.getByTestId('input-comment').fill('x'.repeat(101));
        await page.getByTestId('action-submit').click();

        await expect(page.getByTestId('form-field-comment').getByTestId('form-field-error')).toBeVisible();
    });
});

test.describe('User edit', () => {
    test.describe.configure({ mode: 'serial' });

    test('updates nickname and email, persists them, then restores', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await page.getByTestId('edit-user-nickname').fill('pw-edit-renamed');
        await page.getByTestId('edit-user-email').fill('pw-edit-renamed@example.test');
        await page.getByTestId('edit-user-submit').click();

        await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
        await expect(page.getByTestId('page-title')).toContainText('pw-edit-renamed');

        await page.getByTestId('action-edit').click();
        await expect(page.getByTestId('edit-user-nickname')).toHaveValue('pw-edit-renamed');
        await expect(page.getByTestId('edit-user-email')).toHaveValue('pw-edit-renamed@example.test');

        await page.getByTestId('edit-user-nickname').fill('pw-edit-user');
        await page.getByTestId('edit-user-email').fill('pw-edit-user@example.test');
        await page.getByTestId('edit-user-submit').click();
        await expect(page.getByTestId('page-title')).toContainText('pw-edit-user');
    });

    test('grants and revokes a role', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await expect(page.getByTestId('role-admin')).not.toBeChecked();
        await page.getByTestId('role-admin').check();
        await page.getByTestId('edit-user-submit').click();
        await expect(page.getByTestId('user-audit-section')).toContainText('user.role_changed');

        await page.getByTestId('action-edit').click();
        await expect(page.getByTestId('role-admin')).toBeChecked();

        await page.getByTestId('role-admin').uncheck();
        await page.getByTestId('edit-user-submit').click();
        await page.getByTestId('action-edit').click();
        await expect(page.getByTestId('role-admin')).not.toBeChecked();
    });

    test('sets a password and shows the indicator', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await expect(page.getByTestId('no-password-indicator')).toBeVisible();

        await page.getByTestId('edit-user-password').fill('e2e-password-1');
        await page.getByTestId('edit-user-password-confirm').fill('e2e-password-1');
        await page.getByTestId('edit-user-submit').click();
        await expect(page.getByTestId('action-edit')).toBeVisible();

        await page.getByTestId('action-edit').click();
        await expect(page.getByTestId('has-password-indicator')).toBeVisible();

        await page.getByTestId('edit-user-clear-password').check();
        await page.getByTestId('edit-user-submit').click();
        await page.getByTestId('action-edit').click();
        await expect(page.getByTestId('no-password-indicator')).toBeVisible();
    });

    test('shows a validation error for a mismatched password confirmation', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await page.getByTestId('edit-user-password').fill('e2e-password-1');
        await page.getByTestId('edit-user-password-confirm').fill('different-password');
        await page.getByTestId('edit-user-submit').click();

        await expect(page.getByTestId('form-field-error').first()).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/users\/\d+\/edit$/);
    });

    test('shows a validation error for a blank nickname', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await page.getByTestId('edit-user-nickname').fill('');
        await page.getByTestId('edit-user-submit').click();

        await expect(page.getByTestId('form-field-nickname').getByTestId('form-field-error')).toBeVisible();
    });

    test('cancel returns to the user page without saving', async ({ page }) => {
        await openUserEdit(page, 'pw-edit-user');
        await page.getByTestId('edit-user-nickname').fill('pw-never-saved');
        await page.getByTestId('edit-user-cancel').click();

        await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
        await expect(page.getByTestId('page-title')).toContainText('pw-edit-user');
    });
});

test.describe('User parameters', () => {
    test.describe.configure({ mode: 'serial' });

    test('adds, edits and deletes a parameter', async ({ page }) => {
        await page.goto('/admin/users?search=pw-param-user');
        await page.getByTestId('data-table-row').filter({ hasText: 'pw-param-user' }).click();
        const section = page.getByTestId('user-data-section');

        await page.getByTestId('parameter-add-btn').click();
        await page.getByTestId('parameter-key-input').fill('e2e_key');
        await page.getByTestId('parameter-value-input').fill('first');
        await page.getByTestId('confirm-modal-confirm').click();
        await expect(section.getByTestId('parameter-key')).toHaveText('e2e_key');
        await expect(section.getByTestId('parameter-value')).toContainText('first');

        await page.reload();
        await expect(section.getByTestId('parameter-key')).toHaveText('e2e_key');

        await section.getByTestId('parameter-edit-btn').click();
        await page.getByTestId('parameter-value-input').fill('second');
        await page.getByTestId('confirm-modal-confirm').click();
        await expect(section.getByTestId('parameter-value')).toContainText('second');

        await section.getByTestId('parameter-delete-btn').click();
        await page.getByTestId('confirm-modal-confirm').click();
        await expect(section.getByTestId('parameter-key')).toHaveCount(0);
        await page.reload();
        await expect(section.getByTestId('parameter-key')).toHaveCount(0);
    });

    test('rejects a parameter with an empty key', async ({ page }) => {
        await page.goto('/admin/users?search=pw-param-user');
        await page.getByTestId('data-table-row').filter({ hasText: 'pw-param-user' }).click();

        await page.getByTestId('parameter-add-btn').click();
        await page.getByTestId('parameter-value-input').fill('orphan');
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(page.getByTestId('form-field-parameter-key').getByTestId('form-field-error')).toBeVisible();
        await expect(page.getByTestId('confirm-modal')).toBeVisible();
    });
});

test.describe('MAC address filters', () => {
    const rowFor = (page, mac) => page.getByTestId('data-table-row').filter({ hasText: mac });

    test('source filter narrows the list and is reflected in the URL', async ({ page }) => {
        await page.goto('/admin/macs');
        await expect(rowFor(page, '02:99:00:00:00:01')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:02')).toBeVisible();

        await page.getByTestId('filter-select-source').selectOption('static');

        await expect(page).toHaveURL(/source=static/);
        await expect(rowFor(page, '02:99:00:00:00:01')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:02')).toHaveCount(0);
        await expect(page.getByTestId('filter-pill-source')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('filter-select-source')).toHaveValue('static');
        await expect(rowFor(page, '02:99:00:00:00:02')).toHaveCount(0);
    });

    test('removing the source pill restores the full list', async ({ page }) => {
        await page.goto('/admin/macs?source=snmp');
        await expect(rowFor(page, '02:99:00:00:00:02')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:01')).toHaveCount(0);

        await page.getByTestId('filter-pill-remove-source').click();

        await expect(rowFor(page, '02:99:00:00:00:01')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:02')).toBeVisible();
    });

    test('search matches by MAC address, DHCP hostname and owner nickname', async ({ page }) => {
        await page.goto('/admin/macs');
        const search = page.getByTestId('filter-search-input');

        await search.fill('02:99:00:00:00:03');
        await expect(rowFor(page, '02:99:00:00:00:03')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:01')).toHaveCount(0);

        await search.fill('pw-console');
        await expect(rowFor(page, '02:99:00:00:00:02')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:03')).toHaveCount(0);

        await search.fill('pw-net-user');
        await expect(rowFor(page, '02:99:00:00:00:01')).toBeVisible();
        await expect(rowFor(page, '02:99:00:00:00:02')).toHaveCount(0);
    });

    test('search and source filter combine, and no match shows the empty state', async ({ page }) => {
        await page.goto('/admin/macs');
        await page.getByTestId('filter-select-source').selectOption('static');
        await page.getByTestId('filter-search-input').fill('pw-console');

        await expect(page.getByTestId('data-table-empty')).toBeVisible();
    });

    test('clicking a row opens the MAC detail page', async ({ page }) => {
        await page.goto('/admin/macs?search=02:99:00:00:00:01');
        await rowFor(page, '02:99:00:00:00:01').getByTestId('mac-address').click();

        await expect(page).toHaveURL(/\/admin\/macs\/02:99:00:00:00:01$/);
        await expect(page.getByTestId('page-title')).toContainText('02:99:00:00:00:01');
    });
});

test.describe('DHCP pages', () => {
    test.beforeEach(() => {
        artisan(
            'tinker',
            '--execute=\\App\\Models\\CapabilityAssignment::assign(\\App\\Enums\\Capability::Dhcp, "kea");',
        );
    });

    const leaseRow = (page, ip) => page.getByTestId('data-table-row').filter({ hasText: ip });

    test('ranges page lists configured ranges and links to leases', async ({ page }) => {
        await page.goto('/admin/dhcp');
        await expect(page.getByTestId('range-row-0-network')).toBeVisible();
        await expect(page.getByTestId('range-row-1-network')).toBeVisible();
        await expect(page.getByText('10.99.0.0/24').first()).toBeVisible();
        await expect(page.getByText('10.99.5.0/24').first()).toBeVisible();

        await page.getByTestId('view-leases-button').click();
        await expect(page).toHaveURL(/\/admin\/dhcp\/leases$/);
        await expect(page.getByTestId('page-title')).toHaveText('DHCP Leases');
        await expect(page.getByTestId('data-table-row')).toHaveCount(3);
    });

    test('range filter narrows leases and back link returns to ranges', async ({ page }) => {
        await page.goto('/admin/dhcp/leases');
        await page.getByTestId('filter-select-range').selectOption('10.99.5.0/24');

        await expect(page.getByTestId('data-table-row')).toHaveCount(1);
        await expect(leaseRow(page, '10.99.5.20')).toBeVisible();
        await expect(page.getByTestId('filter-count')).toContainText('1');

        await page.getByTestId('filter-pill-remove-range').click();
        await expect(page.getByTestId('data-table-row')).toHaveCount(3);

        await page.getByTestId('back-to-ranges-link').click();
        await expect(page).toHaveURL(/\/admin\/dhcp$/);
    });

    test('network query param preselects the range filter', async ({ page }) => {
        await page.goto('/admin/dhcp/leases?network=10.99.0.0%2F24');

        await expect(page.getByTestId('filter-select-range')).toHaveValue('10.99.0.0/24');
        await expect(page.getByTestId('data-table-row')).toHaveCount(2);
        await expect(leaseRow(page, '10.99.5.20')).toHaveCount(0);
    });

    test('search matches hostname, MAC and IP', async ({ page }) => {
        await page.goto('/admin/dhcp/leases');
        const search = page.getByTestId('filter-search-input');

        await search.fill('pw-console');
        await expect(page.getByTestId('data-table-row')).toHaveCount(1);
        await expect(leaseRow(page, '10.99.0.12')).toBeVisible();

        await search.fill('02:99:00:00:00:03');
        await expect(page.getByTestId('data-table-row')).toHaveCount(1);
        await expect(leaseRow(page, '10.99.5.20')).toBeVisible();

        await search.fill('10.99.0.11');
        await expect(page.getByTestId('data-table-row')).toHaveCount(1);
        await expect(leaseRow(page, 'pw-laptop')).toBeVisible();

        await search.fill('no-such-lease');
        await expect(page.getByTestId('data-table-empty')).toBeVisible();
    });

    test('sorting by IP toggles the row order', async ({ page }) => {
        await page.goto('/admin/dhcp/leases');
        const ips = page.getByTestId('data-table-row').locator('[data-testid$="-ip"]');
        await expect(ips.first()).toContainText('10.99.0.11');

        await page.getByTestId('sort-ip').click();
        await expect(ips.first()).toContainText('10.99.5.20');

        await page.getByTestId('sort-ip').click();
        await expect(ips.first()).toContainText('10.99.0.11');
    });
});
