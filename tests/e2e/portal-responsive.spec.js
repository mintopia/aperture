import { test, expect } from './support/csp-guard.js';

test.describe('Portal block grid on a narrow viewport', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('block wrappers do not overlap horizontally and are wide enough', async ({ page }) => {
        await page.goto('/portal');
        const grid = page.getByTestId('block-grid');
        await expect(grid).toBeVisible();

        const wrappers = grid.locator('> [data-testid^="block-"][data-testid$="-wrapper"]');
        const count = await wrappers.count();
        expect(count).toBeGreaterThan(0);

        const gridBox = await grid.boundingBox();
        const boxes = [];
        for (let i = 0; i < count; i++) {
            const box = await wrappers.nth(i).boundingBox();
            expect(box.width).toBeGreaterThanOrEqual(300);
            expect(box.x).toBeGreaterThanOrEqual(gridBox.x - 1);
            expect(box.x + box.width).toBeLessThanOrEqual(gridBox.x + gridBox.width + 1);
            boxes.push(box);
        }

        for (let i = 0; i < boxes.length; i++) {
            for (let j = i + 1; j < boxes.length; j++) {
                const a = boxes[i];
                const b = boxes[j];
                const overlapX = a.x < b.x + b.width - 1 && b.x < a.x + a.width - 1;
                const overlapY = a.y < b.y + b.height - 1 && b.y < a.y + a.height - 1;
                expect(overlapX && overlapY, `blocks ${i} and ${j} overlap`).toBe(false);
            }
        }
    });

    test('connection strip fields stay inside their wrapper', async ({ page }) => {
        await page.goto('/portal');
        const wrapper = page.getByTestId('block-connection_strip-wrapper');
        if ((await wrapper.count()) === 0) {
            test.skip(true, 'No connection strip block in the portal fixtures');
        }
        await expect(wrapper).toBeVisible();
        const wrapperBox = await wrapper.boundingBox();

        const fields = wrapper.locator('[data-testid^="connection-strip-field-"]');
        const count = await fields.count();
        expect(count).toBeGreaterThan(0);
        for (let i = 0; i < count; i++) {
            const box = await fields.nth(i).boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(wrapperBox.x - 1);
            expect(box.x + box.width).toBeLessThanOrEqual(wrapperBox.x + wrapperBox.width + 1);
        }
    });
});
