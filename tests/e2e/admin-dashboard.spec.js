import { test, expect } from '@playwright/test';

test.describe('Admin Dashboard (S4)', () => {
    test('renders stat cards', async ({ page }) => {
        await page.goto('/admin');
        const statCards = page.locator('[data-testid="stat-card"]');
        await expect(statCards.first()).toBeVisible();
    });

    test('sidebar has 220px width on desktop', async ({ page }) => {
        await page.goto('/admin');
        const sidebar = page.getByTestId('admin-sidebar');
        const box = await sidebar.boundingBox();
        expect(box.width).toBe(220);
    });

    test('sidebar collapses to a drawer at 1024px', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 768 });
        await page.goto('/admin');
        await expect(page.getByTestId('admin-sidebar')).toHaveCount(0);
        await page.getByTestId('admin-menu-toggle').click();
        await expect(page.getByTestId('admin-drawer')).toBeVisible();
    });

    test('admin header is visible', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.getByTestId('admin-header')).toBeVisible();
    });
});

test.describe('Native-platform refactor rendering', () => {
    test('every sidebar nav item renders an SVG icon', async ({ page }) => {
        await page.goto('/admin');
        const items = page.getByTestId('admin-sidebar').locator('nav a');
        const count = await items.count();
        expect(count).toBeGreaterThanOrEqual(9);
        for (let i = 0; i < count; i++) {
            await expect(items.nth(i).locator('svg path').first()).toBeAttached();
        }
    });

    test('dashboard stats show final values without animation', async ({ page }) => {
        await page.goto('/admin');
        const stats = page.getByTestId('dashboard-stats').getByTestId('stat-card');
        await expect(stats).toHaveCount(4);
        const first = await stats.first().innerText();
        await page.waitForTimeout(500);
        expect(await stats.first().innerText()).toBe(first);
        expect(first).toMatch(/\d/);
    });

    test('theme toggle switches data-mode and accent colours', async ({ page }) => {
        await page.goto('/admin');
        const html = page.locator('html');
        const before = await html.getAttribute('data-mode');
        const accentBefore = await html.evaluate((el) => el.style.getPropertyValue('--color-accent'));
        await page.getByRole('switch').first().click();
        const after = before === 'dark' ? 'light' : 'dark';
        await expect(html).toHaveAttribute('data-mode', after);
        const accentAfter = await html.evaluate((el) => el.style.getPropertyValue('--color-accent'));
        expect(accentAfter).not.toBe(accentBefore);
        expect(accentAfter).toContain('oklch');
    });

    test('bandwidth chart canvas renders', async ({ page }) => {
        const now = Math.floor(Date.now() / 1000);
        await page.route('**/admin/bandwidth*', (route) =>
            route.fulfill({
                json: {
                    timestamps: [now - 120, now - 60, now],
                    download: [1000, 2000, 3000],
                    upload: [100, 200, 300],
                    totalReceived: 5 * 1024 * 1024,
                    totalSent: 2 * 1024 * 1024,
                },
            }),
        );
        await page.goto('/admin');
        const chart = page.getByTestId('bandwidth-chart');
        await expect(chart).toBeVisible();
        await expect(chart.getByTestId('chart-canvas')).toBeVisible();
        const box = await chart.getByTestId('chart-canvas').boundingBox();
        expect(box.width).toBeGreaterThan(100);
        expect(box.height).toBeGreaterThan(50);
    });
});

test.describe('Recent Activity widget', () => {
    test('widget is visible with heading and seeded entries', async ({ page }) => {
        await page.goto('/admin');

        const widget = page.getByTestId('recent-activity');
        await expect(widget).toBeVisible();
        await expect(widget.getByText('Recent Activity')).toBeVisible();

        const firstEntry = widget.getByTestId('recent-activity-entry').first();
        await expect(firstEntry).toBeVisible();

        const severityDot = widget.getByTestId('recent-activity-severity').first();
        await expect(severityDot).toBeVisible();
        const severity = await severityDot.getAttribute('data-severity');
        expect(['info', 'warning', 'critical']).toContain(severity);

        await expect(widget.getByTestId('recent-activity-timestamp').first()).toBeVisible();
    });

    test('"View All →" link navigates to the audit log page', async ({ page }) => {
        await page.goto('/admin');

        const widget = page.getByTestId('recent-activity');
        await expect(widget).toBeVisible();

        const viewAllLink = widget.getByTestId('recent-activity-view-all');
        await expect(viewAllLink).toBeVisible();
        await viewAllLink.click();

        await expect(page).toHaveURL(/\/admin\/audit-log/);
        await expect(page.getByTestId('audit-log-index-layout')).toBeVisible();
    });
});
