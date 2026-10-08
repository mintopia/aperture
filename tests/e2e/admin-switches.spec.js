import { test, expect } from './support/test.js';
import { sshProxyStubPort } from '../../playwright/env.js';
import { createSwitch, uniqueSwitch } from './support/switches.js';

const proxyUrl = `http://127.0.0.1:${sshProxyStubPort}`;

async function syncSwitch(page) {
    await page.getByTestId('action-sync').click();
    await expect(flash(page, 'Switch sync has been queued')).toBeVisible();
    await expect(page.getByTestId('sync-status')).toContainText('Completed');
}

function flash(page, text) {
    return page.getByTestId('flash-message-success').filter({ hasText: text });
}

async function proxyCommands(request, hostname) {
    const response = await request.get(`${proxyUrl}/__log`, { params: { hostname } });
    return (await response.json()).commands;
}

test.describe('Switch management', () => {
    test('admin creates a switch and sees it in the list', async ({ page }) => {
        const sw = uniqueSwitch('Create');
        await createSwitch(page, sw);
        await expect(flash(page, 'Switch created successfully')).toBeVisible();

        await page.goto('/admin/switches');
        await expect(page.getByTestId('switches-table-card')).toContainText(sw.name);
        await expect(page.getByTestId('switches-table-card')).toContainText(sw.hostname);
    });

    test('creating a switch with missing fields shows validation errors and saves nothing', async ({ page }) => {
        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-name').fill('E2E Incomplete');
        await page.getByTestId('action-save').click();

        await expect(page).toHaveURL(/\/admin\/switches\/create$/);
        await expect(page.getByTestId('form-field-hostname').getByTestId('form-field-error')).toBeVisible();
        await expect(page.getByTestId('form-field-type').getByTestId('form-field-error')).toBeVisible();
    });

    test('duplicate hostname is rejected', async ({ page }) => {
        const sw = uniqueSwitch('Duplicate');
        await createSwitch(page, sw);

        await page.goto('/admin/switches/create');
        await page.getByTestId('switch-name').fill('E2E Duplicate Again');
        await page.getByTestId('switch-hostname').fill(sw.hostname);
        await page.getByTestId('switch-type').selectOption('cisco');
        await page.getByTestId('switch-username').fill('e2e-user');
        await page.getByTestId('switch-password').fill('e2e-password');
        await page.getByTestId('action-save').click();

        await expect(page.getByTestId('form-field-hostname').getByTestId('form-field-error')).toContainText(
            /already been taken/i,
        );
    });

    for (const [label, size] of [
        ['phone', { width: 375, height: 800 }],
        ['small phone', { width: 320, height: 640 }],
        ['desktop', { width: 1280, height: 800 }],
    ]) {
        test(`switch page actions do not overlap or overflow on a ${label}`, async ({ page }) => {
            await page.setViewportSize(size);
            await createSwitch(page, uniqueSwitch('Layout'));

            const ids = ['action-test', 'action-sync', 'action-edit'];
            const boxes = [];
            for (const id of ids) {
                const locator = page.getByTestId(id);
                await expect(locator).toBeVisible();
                boxes.push(await locator.boundingBox());
            }

            for (const box of boxes) {
                expect(box.x).toBeGreaterThanOrEqual(0);
                expect(box.x + box.width).toBeLessThanOrEqual(size.width);
            }
            for (let i = 0; i < boxes.length; i++) {
                for (let j = i + 1; j < boxes.length; j++) {
                    const a = boxes[i];
                    const b = boxes[j];
                    const separated =
                        a.x + a.width <= b.x + 0.5 ||
                        b.x + b.width <= a.x + 0.5 ||
                        a.y + a.height <= b.y + 0.5 ||
                        b.y + b.height <= a.y + 0.5;
                    expect(separated, `${ids[i]} overlaps ${ids[j]}`).toBe(true);
                }
            }

            // The title sits above or beside the actions, never underneath them.
            const title = await page.getByTestId('page-title').boundingBox();
            const actions = await page.getByTestId('switch-show-actions').boundingBox();
            const titleClear = title.y + title.height <= actions.y + 0.5 || title.x + title.width <= actions.x + 0.5;
            expect(titleClear, 'page title overlaps the actions').toBe(true);

            const overflows = () =>
                page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
            expect(await overflows(), 'show page scrolls horizontally').toBe(false);

            await page.getByTestId('action-edit').click();
            await expect(page).toHaveURL(/\/admin\/switches\/\d+\/edit$/);
            await expect(page.getByTestId('switch-name')).toBeVisible();
            expect(await overflows(), 'edit page scrolls horizontally (long breadcrumbs?)').toBe(false);
        });
    }

    test('admin edits a switch and the change persists', async ({ page }) => {
        const sw = uniqueSwitch('Edit');
        await createSwitch(page, sw);

        await page.getByTestId('action-edit').click();
        await expect(page).toHaveURL(/\/admin\/switches\/\d+\/edit$/);
        await expect(page.getByTestId('switch-name')).toHaveValue(sw.name);

        const renamed = `${sw.name} renamed`;
        await page.getByTestId('switch-name').fill(renamed);
        await page.getByTestId('switch-timeout').fill('12');
        await page.getByTestId('action-save').click();
        await expect(flash(page, 'Switch updated successfully')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('switch-name')).toHaveValue(renamed);
        await expect(page.getByTestId('switch-timeout')).toHaveValue('12');
    });

    test('admin deletes a switch after confirming', async ({ page }) => {
        const sw = uniqueSwitch('Delete');
        await createSwitch(page, sw);
        await page.getByTestId('action-edit').click();

        await page.getByTestId('switch-delete').click();
        await expect(page.getByTestId('confirm-modal-title')).toContainText('Delete Switch');
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(page).toHaveURL(/\/admin\/switches$/);
        await expect(flash(page, 'Switch deleted successfully')).toBeVisible();
        await expect(page.getByText(sw.hostname)).toHaveCount(0);
    });

    test('cancelling the delete dialog keeps the switch', async ({ page }) => {
        const sw = uniqueSwitch('KeepMe');
        await createSwitch(page, sw);
        await page.getByTestId('action-edit').click();

        await page.getByTestId('switch-delete').click();
        await page.getByTestId('confirm-modal-cancel').click();
        await expect(page.getByTestId('confirm-modal')).toBeHidden();

        await page.goto('/admin/switches');
        await expect(page.getByTestId('switches-table-card')).toContainText(sw.hostname);
    });

    test('test connection reports success through the ssh-proxy', async ({ page }) => {
        await createSwitch(page, uniqueSwitch('Reachable'));

        await page.getByTestId('action-test').click();

        await expect(page.getByTestId('test-result')).toContainText('Connection successful');
    });

    test('test connection reports failure when the proxy cannot reach the switch', async ({ page }) => {
        await createSwitch(page, {
            name: `E2E Unreachable ${Date.now()}`,
            hostname: `unreachable-${Date.now()}.switch.test`,
        });

        await page.getByTestId('action-test').click();

        await expect(page.getByTestId('test-result')).toContainText('Connection test failed');
    });

    test('sync discovers ports and shows them on the switch page', async ({ page, request }) => {
        const sw = uniqueSwitch('Sync');
        await createSwitch(page, sw);
        await expect(page.getByText('No ports found')).toBeVisible();

        await syncSwitch(page);

        const rows = page.getByTestId('switch-ports-card');
        await expect(rows).toContainText('Gi1/0/1');
        await expect(rows).toContainText('e2e-uplink');
        await expect(rows).toContainText('Gi1/0/2');
        await expect(rows).toContainText('e2e-access');
        expect(await proxyCommands(request, sw.hostname)).toContain('show interface status');

        await page.goto('/admin/switches');
        await expect(page.getByTestId('switches-table-card')).toContainText(sw.name);
        await expect(page.getByTestId('switches-table-card').getByText('Completed').first()).toBeVisible();
    });

    test('admin shuts down and re-enables a port', async ({ page, request }) => {
        const sw = uniqueSwitch('Port');
        await createSwitch(page, sw);
        await syncSwitch(page);

        await page.getByTestId('switch-ports-card').getByText('Gi1/0/1', { exact: true }).click();
        await expect(page.getByTestId('page-title')).toHaveText('Gi1/0/1');
        const toggle = page.getByTestId('action-toggle');
        await expect(toggle).toHaveText('Shut');

        await toggle.click();
        await expect(page.getByTestId('confirm-modal-title')).toContainText('Shut Down Port');
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(flash(page, 'Port shutdown has been queued')).toBeVisible();
        await expect(toggle).toHaveText('Unshut');
        await expect.poll(() => proxyCommands(request, sw.hostname)).toContain('shutdown');

        await page.reload();
        await expect(page.getByTestId('action-toggle')).toHaveText('Unshut');

        await page.getByTestId('action-toggle').click();
        await expect(page.getByTestId('confirm-modal-title')).toContainText('Enable Port');
        await page.getByTestId('confirm-modal-confirm').click();

        await expect(flash(page, 'Port enable has been queued')).toBeVisible();
        await expect(page.getByTestId('action-toggle')).toHaveText('Shut');
        expect(await proxyCommands(request, sw.hostname)).toContain('no shutdown');
    });

    test('cancelling the port shutdown dialog sends nothing to the switch', async ({ page, request }) => {
        const sw = uniqueSwitch('PortCancel');
        await createSwitch(page, sw);
        await syncSwitch(page);
        await page.getByTestId('switch-ports-card').getByText('Gi1/0/2', { exact: true }).click();

        await page.getByTestId('action-toggle').click();
        await page.getByTestId('confirm-modal-cancel').click();

        await expect(page.getByTestId('confirm-modal')).toBeHidden();
        await expect(page.getByTestId('action-toggle')).toHaveText('Shut');
        expect(await proxyCommands(request, sw.hostname)).not.toContain('shutdown');
    });
});
