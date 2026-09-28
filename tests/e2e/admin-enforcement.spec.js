import { test, expect } from '@playwright/test';

async function openUser(page, nickname) {
    await page.goto(`/admin/users?search=${nickname}`);
    await page.getByTestId('data-table-row').filter({ hasText: nickname }).click();
    await expect(page.getByTestId('page-title')).toContainText(nickname);
}

async function confirmModal(page) {
    await expect(page.getByTestId('confirm-modal')).toBeVisible();
    await page.getByTestId('confirm-modal-confirm').click();
    await expect(page.getByTestId('confirm-modal')).toBeHidden();
}

async function toggleWithModal(page, from, to) {
    await page.getByTestId(from).click();
    await confirmModal(page);
    await expect(page.getByTestId(to)).toBeVisible();
    await page.reload();
    await expect(page.getByTestId(to)).toBeVisible();
    await expect(page.getByTestId(from)).toHaveCount(0);
}

test.describe('User enforcement actions', () => {
    test.describe.configure({ mode: 'serial' });

    test('block and unblock a user persists and shows in the user list', async ({ page }) => {
        await openUser(page, 'pw-block-user');

        await toggleWithModal(page, 'action-block', 'action-unblock');

        await page.goto('/admin/users?search=pw-block-user');
        const row = page.getByTestId('data-table-row').filter({ hasText: 'pw-block-user' });
        await expect(row.getByTestId('user-status')).toHaveText('Denied');

        await row.click();
        await toggleWithModal(page, 'action-unblock', 'action-block');

        await page.goto('/admin/users?search=pw-block-user');
        await expect(page.getByTestId('user-status')).toHaveText('Allowed');
    });

    test('cancelling the block modal changes nothing', async ({ page }) => {
        await openUser(page, 'pw-block-user');
        await page.getByTestId('action-block').click();
        await page.getByTestId('confirm-modal-cancel').click();
        await expect(page.getByTestId('confirm-modal')).toBeHidden();
        await page.reload();
        await expect(page.getByTestId('action-block')).toBeVisible();
    });

    test('enable and disable internet applies to every device of the user', async ({ page }) => {
        await openUser(page, 'pw-net-user');
        await expect(page.getByTestId('device-internet').filter({ hasText: 'Disabled' })).toHaveCount(2);

        await toggleWithModal(page, 'action-enable-internet', 'action-disable-internet');
        await expect(page.getByTestId('device-internet').filter({ hasText: 'Enabled' })).toHaveCount(2);

        await toggleWithModal(page, 'action-disable-internet', 'action-enable-internet');
        await expect(page.getByTestId('device-internet').filter({ hasText: 'Disabled' })).toHaveCount(2);
    });

    test('enable and disable rate limit applies to every device of the user', async ({ page }) => {
        await openUser(page, 'pw-net-user');
        const limited = page.getByTestId('device-rate-limit');
        const before = await limited.allInnerTexts();

        await toggleWithModal(page, 'action-enable-rate-limit', 'action-disable-rate-limit');
        const after = await limited.allInnerTexts();
        expect(after).toHaveLength(2);
        expect(after).not.toEqual(before);

        await toggleWithModal(page, 'action-disable-rate-limit', 'action-enable-rate-limit');
        expect(await limited.allInnerTexts()).toEqual(before);
    });
});

test.describe('IP enforcement actions', () => {
    test.describe.configure({ mode: 'serial' });

    const ip = '10.99.1.10';

    test('revoke and grant internet access persists and shows in the IP list', async ({ page }) => {
        await page.goto(`/admin/ips/${ip}`);
        await toggleWithModal(page, 'action-revoke', 'action-grant');

        await page.goto(`/admin/ips?address=${ip}`);
        await expect(page.getByTestId('data-table-row').filter({ hasText: ip }).getByTestId('ip-status')).toHaveText('Blocked');

        await page.goto(`/admin/ips/${ip}`);
        await toggleWithModal(page, 'action-grant', 'action-revoke');

        await page.goto(`/admin/ips?address=${ip}`);
        await expect(page.getByTestId('data-table-row').filter({ hasText: ip }).getByTestId('ip-status')).toHaveText('Allowed');
    });

    test('enable and disable rate limit persists', async ({ page }) => {
        await page.goto(`/admin/ips/${ip}`);
        await expect(page.getByTestId('action-enable-rate-limit')).toBeVisible();

        await page.getByTestId('action-enable-rate-limit').click();
        await expect(page.getByTestId('action-disable-rate-limit')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('action-disable-rate-limit')).toBeVisible();

        await page.getByTestId('action-disable-rate-limit').click();
        await expect(page.getByTestId('action-enable-rate-limit')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('action-enable-rate-limit')).toBeVisible();
    });

    test('enable and disable DNS filter persists', async ({ page }) => {
        await page.goto(`/admin/ips/${ip}`);
        await expect(page.getByTestId('action-enable-dns-filter')).toBeVisible();

        await page.getByTestId('action-enable-dns-filter').click();
        await expect(page.getByTestId('action-disable-dns-filter')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('action-disable-dns-filter')).toBeVisible();

        await page.getByTestId('action-disable-dns-filter').click();
        await expect(page.getByTestId('action-enable-dns-filter')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('action-enable-dns-filter')).toBeVisible();
    });

    test('enforcement changes are recorded in the IP audit section', async ({ page }) => {
        await page.goto(`/admin/ips/${ip}`);
        await expect(page.getByTestId('ip-audit-section')).toContainText('ip.internet_toggled');
        await expect(page.getByTestId('ip-audit-section')).toContainText('ip.dns_filter_toggled');
    });
});
