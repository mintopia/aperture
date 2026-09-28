import { expect } from '@playwright/test';

export function uniqueSwitch(label) {
    const id = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    return { name: `E2E ${label} ${id}`, hostname: `e2e-${id}.switch.test` };
}

export async function createSwitch(page, { name, hostname }) {
    await page.goto('/admin/switches/create');
    await page.getByTestId('switch-name').fill(name);
    await page.getByTestId('switch-hostname').fill(hostname);
    await page.getByTestId('switch-type').selectOption('cisco');
    await page.getByTestId('switch-username').fill('e2e-user');
    await page.getByTestId('switch-password').fill('e2e-password');
    await page.getByTestId('switch-enable-password').fill('e2e-enable');
    await page.getByTestId('action-save').click();
    await expect(page).toHaveURL(/\/admin\/switches\/\d+$/);
    await expect(page.getByTestId('page-title')).toHaveText(name);
}
