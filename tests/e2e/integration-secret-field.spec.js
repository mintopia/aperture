import { test, expect } from './support/test.js';

test.describe('Integration write-only secret field', () => {
    test.describe.configure({ mode: 'serial' });

    const secret = 'playwright-secret-value';

    test('secret is never rendered and blank saves keep it', async ({ page }) => {
        await page.goto('/admin/settings/integrations/pihole');

        const input = page.getByTestId('field-input-password');
        await input.fill(secret);
        await page.getByTestId('action-save').click();
        await expect(page.getByTestId('field-secret-status-password')).toHaveText('A value is currently set.');

        await page.reload();
        await expect(input).toHaveValue('');
        await expect(page.getByTestId('field-secret-status-password')).toHaveText('A value is currently set.');
        await expect(input).toHaveAttribute('placeholder', /Leave blank to keep/);
        expect(await page.content()).not.toContain(secret);

        await page.getByTestId('field-input-endpoint').fill('https://pihole-secret.example.test');
        await page.getByTestId('action-save').click();
        await expect(page.getByTestId('form-field-error')).toHaveCount(0);

        await page.reload();
        await expect(page.getByTestId('field-secret-status-password')).toHaveText('A value is currently set.');
        await expect(input).toHaveValue('');
    });
});
