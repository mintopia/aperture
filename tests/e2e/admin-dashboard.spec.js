import { test, expect } from '@playwright/test';

test.describe('Admin Dashboard (S4)', () => {
    test('renders stat cards', async ({ page }) => {
        await page.goto('/admin');
        const statCards = page.locator('[data-testid="stat-card"]');
        await expect(statCards.first()).toBeVisible();
    });

    test('sidebar has 172px width on desktop', async ({ page }) => {
        await page.goto('/admin');
        const sidebar = page.getByTestId('admin-sidebar');
        const box = await sidebar.boundingBox();
        expect(box.width).toBe(172);
    });

    test('sidebar collapses to horizontal nav at 1024px', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 768 });
        await page.goto('/admin');
        await expect(page.getByTestId('admin-nav-horizontal')).toBeVisible();
    });

    test('admin header is visible', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.getByTestId('admin-header')).toBeVisible();
    });
});

test.describe('Recent Activity widget', () => {
    test('widget is visible with heading and seeded entries', async ({ page }) => {
        await page.goto('/admin');

        const widget = page.getByTestId('recent-activity');
        await expect(widget).toBeVisible();
        await expect(widget.getByText('Recent Activity')).toBeVisible();

        // recentEvents is an Inertia deferred prop — wait for at least one entry to resolve
        const firstEntry = widget.getByTestId('recent-activity-entry').first();
        await expect(firstEntry).toBeVisible();

        // Each entry must carry a severity dot with a data-severity attribute
        const severityDot = widget.getByTestId('recent-activity-severity').first();
        await expect(severityDot).toBeVisible();
        const severity = await severityDot.getAttribute('data-severity');
        expect(['info', 'warning', 'critical']).toContain(severity);

        // Each entry must carry a timestamp element
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
