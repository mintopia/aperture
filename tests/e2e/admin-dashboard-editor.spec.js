import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const GRID = '[data-testid="editor-grid"]';

async function readLayout(page) {
    return page.evaluate(() => {
        const span = (value) => {
            const [start, rest] = value.split('/').map((part) => part.trim());
            return [Number(start), Number(rest.replace('span', ''))];
        };
        const layout = {};
        for (const el of document.querySelectorAll('[data-testid^="editor-block-"]')) {
            const [col, colSpan] = span(el.style.gridColumn);
            const [row, rowSpan] = span(el.style.gridRow);
            layout[el.dataset.testid.replace('editor-block-', '')] = { col, row, colSpan, rowSpan };
        }
        return layout;
    });
}

function overlaps(layout) {
    const cells = new Set();
    for (const { col, row, colSpan, rowSpan } of Object.values(layout)) {
        for (let c = col; c < col + colSpan; c++) {
            for (let r = row; r < row + rowSpan; r++) {
                const key = `${c},${r}`;
                if (cells.has(key)) return true;
                cells.add(key);
            }
        }
    }
    return false;
}

async function gridPoint(page, col, row) {
    const box = await page.locator(GRID).boundingBox();
    const maxRow = Math.max(...Object.values(await readLayout(page)).map((b) => b.row + b.rowSpan - 1));
    return {
        x: box.x + ((col - 0.5) * box.width) / 3,
        y: box.y + ((row - 0.5) * box.height) / maxRow,
    };
}

async function dragTo(page, id, col, row, { drop = true } = {}) {
    const handle = await page.getByTestId(`drag-handle-${id}`).boundingBox();
    await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
    await page.mouse.down();
    const target = await gridPoint(page, col, row);
    await page.mouse.move(target.x, target.y, { steps: 8 });
    await expect(page.getByTestId('drag-ghost')).toBeVisible();
    if (drop) await page.mouse.up();
}

async function resizeBy(page, id, dx, dy, { drop = true } = {}) {
    const handle = await page.getByTestId(`resize-handle-${id}`).boundingBox();
    const x = handle.x + handle.width / 2;
    const y = handle.y + handle.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + dx, y + dy, { steps: 8 });
    if (drop) await page.mouse.up();
}

async function cellWidth(page) {
    return (await page.locator(GRID).boundingBox()).width / 3;
}

test.describe('Dashboard editor', () => {
    let original;

    test.beforeEach(async ({ page }) => {
        await page.goto('/admin/content');
        await expect(page.locator(GRID)).toBeVisible();
        original = await readLayout(page);
        expect(Object.keys(original).length).toBeGreaterThanOrEqual(3);
    });

    test.afterEach(async ({ page }) => {
        await page.goto('/admin/content');
        await expect(page.locator(GRID)).toBeVisible();
        const keep = Object.keys(original).map(Number);
        const current = Object.keys(await readLayout(page)).map(Number);
        await page.evaluate(
            async ({ keep, current, original }) => {
                const send = (method, url, body) =>
                    fetch(url, {
                        method,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: body === undefined ? undefined : JSON.stringify(body),
                    });
                for (const id of current.filter((id) => !keep.includes(id))) {
                    await send('DELETE', `/admin/content/${id}`);
                }
                const blocks = keep.map((id) => ({
                    id,
                    grid_col: original[id].col,
                    grid_row: original[id].row,
                    col_span: original[id].colSpan,
                    row_span: original[id].rowSpan,
                }));
                await send('PUT', '/admin/content/layout', { blocks });
            },
            { keep, current, original },
        );
    });

    test('adds a block from the menu, edits it in the side panel and deletes it', async ({ page }) => {
        const before = Object.keys(await readLayout(page));

        await page.getByTestId('action-add-block').click();
        await expect(page.getByTestId('add-block-menu')).toBeVisible();
        await expect(page.getByTestId('add-block-connection_strip')).toHaveCount(0);
        await page.getByTestId('add-block-link_strip').click();

        await expect(page.getByTestId('editor-side-panel')).toBeVisible();
        await expect(page.getByTestId('panel-title-input')).toHaveValue('Link Strip');
        await expect(page.locator('[data-testid^="editor-block-"]')).toHaveCount(before.length + 1);
        await expect(page.getByTestId('action-save-layout')).toBeDisabled();

        const layout = await readLayout(page);
        const [newId] = Object.keys(layout).filter((id) => !before.includes(id));
        expect(layout[newId]).toMatchObject({ colSpan: 1, rowSpan: 1 });
        expect(overlaps(layout)).toBe(false);

        await page.getByTestId('panel-title-input').fill('E2E Links');
        await page.getByTestId('panel-save').click();
        await expect(page.getByTestId('editor-side-panel')).toHaveCount(0);
        await expect(page.getByTestId(`editor-block-${newId}`)).toContainText('E2E Links');

        await page.reload();
        await expect(page.getByTestId(`editor-block-${newId}`)).toContainText('E2E Links');

        page.once('dialog', (dialog) => dialog.accept());
        await page.getByTestId(`editor-block-${newId}`).click({ position: { x: 20, y: 40 } });
        await page.getByTestId('panel-delete').click();
        await expect(page.getByTestId(`editor-block-${newId}`)).toHaveCount(0);

        await page.reload();
        await expect(page.locator('[data-testid^="editor-block-"]')).toHaveCount(before.length);
    });

    test('singleton block types disappear from the add menu once placed', async ({ page }) => {
        await page.getByTestId('action-add-block').click();
        for (const type of ['connection_strip', 'bandwidth', 'dns_filter']) {
            await expect(page.getByTestId(`add-block-${type}`)).toHaveCount(0);
        }
        await expect(page.getByTestId('add-block-custom_markdown')).toBeVisible();
    });

    test('dragging a block reflows the blocks it lands on and persists after save', async ({ page }) => {
        const [first, , third] = Object.keys(original).sort((a, b) => Number(a) - Number(b));
        await expect(page.getByTestId('action-save-layout')).toBeDisabled();

        await dragTo(page, third, 3, 1);

        await expect(page.getByTestId('drag-ghost')).toHaveCount(0);
        await expect(page.getByTestId('action-save-layout')).toBeEnabled();
        const moved = await readLayout(page);
        expect(moved[third]).toMatchObject({ col: 3, row: 1 });
        expect(moved[first].row).toBeGreaterThan(original[first].row);
        expect(overlaps(moved)).toBe(false);

        const saved = page.waitForResponse(
            (r) => r.url().endsWith('/admin/content/layout') && r.request().method() === 'PUT',
        );
        await page.getByTestId('action-save-layout').click();
        expect((await saved).ok()).toBe(true);
        await expect(page.getByTestId('action-save-layout')).toBeDisabled();

        await page.reload();
        await expect(page.locator(GRID)).toBeVisible();
        expect(await readLayout(page)).toEqual(moved);
    });

    test('an unsaved drag is discarded on reload', async ({ page }) => {
        const [, , third] = Object.keys(original).sort((a, b) => Number(a) - Number(b));
        await dragTo(page, third, 3, 1);
        await expect(page.getByTestId('action-save-layout')).toBeEnabled();

        await page.reload();
        await expect(page.locator(GRID)).toBeVisible();
        expect(await readLayout(page)).toEqual(original);
    });

    test('escape while dragging cancels and restores the original layout', async ({ page }) => {
        const [, , third] = Object.keys(original).sort((a, b) => Number(a) - Number(b));

        await page.locator(GRID).focus();
        await dragTo(page, third, 3, 1, { drop: false });
        await page.keyboard.press('Escape');

        await expect(page.getByTestId('drag-ghost')).toHaveCount(0);
        await page.mouse.up();
        expect(await readLayout(page)).toEqual(original);
        await expect(page.getByTestId('action-save-layout')).toBeDisabled();
    });

    test('resizing a block taller pushes the blocks beneath it down', async ({ page }) => {
        const [first, second, third] = Object.keys(original).sort((a, b) => Number(a) - Number(b));

        await resizeBy(page, first, 0, 80 + 12);

        await expect(page.getByTestId(`editor-block-${first}`)).toContainText(
            `${original[first].colSpan}×${original[first].rowSpan + 1}`,
        );
        await expect(page.getByTestId('action-save-layout')).toBeEnabled();
        const resized = await readLayout(page);
        expect(resized[first].rowSpan).toBe(original[first].rowSpan + 1);
        expect(resized[second].row).toBeGreaterThan(original[second].row);
        expect(resized[third].row).toBeGreaterThan(original[third].row);
        expect(overlaps(resized)).toBe(false);

        const saved = page.waitForResponse(
            (r) => r.url().endsWith('/admin/content/layout') && r.request().method() === 'PUT',
        );
        await page.getByTestId('action-save-layout').click();
        expect((await saved).ok()).toBe(true);

        await page.reload();
        await expect(page.locator(GRID)).toBeVisible();
        expect(await readLayout(page)).toEqual(resized);
    });

    test('resizing a block wider stops at the grid edge and never below one column', async ({ page }) => {
        const [first, second] = Object.keys(original).sort((a, b) => Number(a) - Number(b));
        const width = await cellWidth(page);

        await resizeBy(page, second, width * 3, 0);
        let layout = await readLayout(page);
        expect(layout[second].col + layout[second].colSpan - 1).toBe(3);
        expect(overlaps(layout)).toBe(false);

        await resizeBy(page, first, -width * 5, 0);
        layout = await readLayout(page);
        expect(layout[first].colSpan).toBe(1);
        expect(overlaps(layout)).toBe(false);
    });

    test('escape while resizing restores the original size', async ({ page }) => {
        const [first] = Object.keys(original).sort((a, b) => Number(a) - Number(b));

        await page.locator(GRID).focus();
        await resizeBy(page, first, 0, 92, { drop: false });
        await expect(page.getByTestId(`editor-block-${first}`)).toContainText(
            `${original[first].colSpan}×${original[first].rowSpan + 1}`,
        );
        await page.keyboard.press('Escape');

        await page.mouse.up();
        expect(await readLayout(page)).toEqual(original);
        await expect(page.getByTestId('action-save-layout')).toBeDisabled();
    });
});
