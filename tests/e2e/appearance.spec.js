import { test, expect } from './support/csp-guard.js';
import { artisan, saveLoginState } from './support/fixtures.js';

const html = (page) => page.locator('html');

// Chroma of a computed colour string, whatever notation the browser serialises it in.
function chromaOf(value) {
    const numbers = (text) => [...text.matchAll(/-?\d*\.?\d+(?:e-?\d+)?/g)].map((m) => Number(m[0]));
    if (value.startsWith('oklab(') || value.startsWith('oklch(')) {
        const [, a, b] = numbers(value.replace('/', ' '));
        return value.startsWith('oklch(') ? a : Math.hypot(a, b);
    }
    const channels = numbers(value.replace(/^[a-z-]+\(/, '').replace('srgb', ''))
        .slice(0, 3)
        .map((n) => (n > 1 || value.startsWith('rgb') ? n / 255 : n));

    return Math.max(...channels) - Math.min(...channels);
}

const washColour = (page) =>
    page
        .locator('.ambient-field > span')
        .first()
        .evaluate((el) => getComputedStyle(el).backgroundColor);
const pageColour = (page) => page.evaluate(() => getComputedStyle(document.body).backgroundColor);

async function setSlider(page, value) {
    await page.getByTestId('appearance-background-intensity').evaluate((el, v) => {
        el.value = String(v);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }, value);
}

async function openAppearance(page) {
    await page.goto('/account/settings');
    await expect(page.getByTestId('appearance-section')).toBeVisible();
}

test.describe('Appearance preferences (admin layout)', () => {
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
    });

    test('settings page shows every appearance control', async ({ page }) => {
        await openAppearance(page);
        for (const id of [
            'appearance-background-intensity',
            'appearance-reduce-transparency',
            'appearance-glass-sheen',
            'appearance-animated-background',
        ]) {
            await expect(page.getByTestId(id)).toBeVisible();
        }
        await expect(page.getByTestId('appearance-background-intensity')).toHaveValue('50');
        await expect(html(page)).toHaveCSS('--bg-intensity', '1');
    });

    test('background colour slider tints the page live and survives a reload', async ({ page }) => {
        await openAppearance(page);

        await setSlider(page, 0);
        const muted = chromaOf(await washColour(page));
        await expect(html(page)).toHaveCSS('--bg-intensity', '0.25');
        await expect(page.getByTestId('appearance-background-intensity-label')).toHaveText('Muted');

        await setSlider(page, 100);
        const vivid = chromaOf(await washColour(page));
        await expect(html(page)).toHaveCSS('--bg-intensity', '1.75');
        await expect(page.getByTestId('appearance-background-intensity-label')).toHaveText('Vivid');
        expect(vivid).toBeGreaterThan(muted * 2);

        await page.reload();
        await expect(page.getByTestId('appearance-background-intensity')).toHaveValue('100');
        await expect(html(page)).toHaveCSS('--bg-intensity', '1.75');
        expect(chromaOf(await washColour(page))).toBeCloseTo(vivid, 3);
    });

    test('the stored intensity is applied before the app boots', async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem('backgroundIntensity', '100');
            localStorage.setItem('glassSheen', '0');
            // Record the root state the moment the body is parsed, i.e. before the deferred app bundle runs.
            const observer = new MutationObserver(() => {
                if (document.body && !window.__prepaint) {
                    window.__prepaint = {
                        intensity: document.documentElement.style.getPropertyValue('--bg-intensity'),
                        sheen: document.documentElement.getAttribute('data-sheen'),
                        appMounted: !!document.querySelector('[data-testid="admin-layout"]'),
                    };
                    observer.disconnect();
                }
            });
            observer.observe(document, { childList: true, subtree: true });
        });
        await page.goto('/admin');
        await expect(page.getByTestId('admin-layout')).toBeVisible();

        const snapshot = await page.evaluate(() => window.__prepaint);
        expect(snapshot).toEqual({ intensity: '1.75', sheen: 'off', appMounted: false });
    });

    test('reduce transparency stays solid, keeps the slider tint, and mirrors the user menu', async ({ page }) => {
        await openAppearance(page);
        const toggle = page.getByTestId('appearance-reduce-transparency');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');

        await toggle.click();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        await expect(page.locator('.ambient-field')).toBeHidden();

        // Solid page colour, still tinted by the intensity slider.
        await setSlider(page, 0);
        const mutedPage = chromaOf(await pageColour(page));
        await setSlider(page, 100);
        const vividPage = chromaOf(await pageColour(page));
        expect(vividPage).toBeGreaterThan(mutedPage * 1.5);

        // Same preference in the user menu.
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-reduce-transparency')).toHaveAttribute('aria-checked', 'true');
        await page.getByTestId('user-menu-reduce-transparency').click();
        await expect(toggle).toHaveAttribute('aria-checked', 'false');
        await expect(html(page)).not.toHaveAttribute('data-transparency', /.+/);

        await toggle.click();
        await page.reload();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');
        await expect(page.getByTestId('appearance-reduce-transparency')).toHaveAttribute('aria-checked', 'true');
    });

    test('glass sheen toggle hides the highlight, persists, and defaults on', async ({ page }) => {
        await page.goto('/portal');
        const header = page.getByTestId('portal-header');
        const sheen = () => header.evaluate((el) => getComputedStyle(el, '::after').display);
        expect(await sheen()).not.toBe('none');

        await openAppearance(page);
        const toggle = page.getByTestId('appearance-glass-sheen');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        await toggle.click();
        await expect(html(page)).toHaveAttribute('data-sheen', 'off');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');

        await page.goto('/portal');
        await expect(html(page)).toHaveAttribute('data-sheen', 'off');
        expect(await sheen()).toBe('none');

        await openAppearance(page);
        await page.getByTestId('appearance-glass-sheen').click();
        await page.goto('/portal');
        await expect(html(page)).not.toHaveAttribute('data-sheen', /.+/);
        expect(await sheen()).not.toBe('none');
    });

    test('animated background toggle stops the drift and persists', async ({ page }) => {
        const drift = () =>
            page
                .locator('.ambient-field[data-variant="lens"] > span')
                .first()
                .evaluate((el) => getComputedStyle(el).animationName);

        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
        expect(await drift()).toBe('ambient-drift');

        await openAppearance(page);
        const toggle = page.getByTestId('appearance-animated-background');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        await toggle.click();
        await expect(html(page)).toHaveAttribute('data-animated-bg', 'off');

        await page.goto('/portal');
        await expect(html(page)).toHaveAttribute('data-animated-bg', 'off');
        expect(await drift()).toBe('none');
    });

    test('the captive page honours the stored preferences', async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem('backgroundIntensity', '0');
            localStorage.setItem('animatedBackground', '0');
            localStorage.setItem('glassSheen', '0');
            localStorage.setItem('reduceTransparency', '0');
        });
        await page.goto('/captive');
        await expect(page.getByTestId('captive-login')).toBeVisible();
        await expect(html(page)).toHaveCSS('--bg-intensity', '0.25');
        await expect(html(page)).toHaveAttribute('data-animated-bg', 'off');
        await expect(html(page)).toHaveAttribute('data-sheen', 'off');
        const animation = await page
            .locator('.ambient-field > span')
            .first()
            .evaluate((el) => getComputedStyle(el).animationName);
        expect(animation).toBe('none');
    });
});

test.describe('Appearance preferences and OS reduced motion', () => {
    test.use({ reducedMotion: 'reduce' });

    test('background is still by default, and an explicit choice overrides the OS setting', async ({ page }) => {
        const drift = () =>
            page
                .locator('.ambient-field[data-variant="lens"] > span')
                .first()
                .evaluate((el) => getComputedStyle(el).animationName);

        await page.goto('/portal');
        await expect(page.getByTestId('portal-layout')).toBeVisible();
        await expect(html(page)).toHaveAttribute('data-animated-bg', 'off');
        expect(await drift()).toBe('none');

        await openAppearance(page);
        const toggle = page.getByTestId('appearance-animated-background');
        await expect(toggle).toHaveAttribute('aria-checked', 'false');
        await toggle.click();
        await expect(html(page)).not.toHaveAttribute('data-animated-bg', /.+/);

        await page.goto('/portal');
        await expect(html(page)).not.toHaveAttribute('data-animated-bg', /.+/);
        expect(await drift()).toBe('ambient-drift');
    });
});

test.describe('Appearance preferences (portal layout)', () => {
    test.describe.configure({ mode: 'serial' });

    let stateFile;
    // Playwright requires fixture functions to destructure their first argument.
    // eslint-disable-next-line no-empty-pattern
    test.use({ storageState: async ({}, use) => use(stateFile) });

    test.beforeAll(async ({ browser }, testInfo) => {
        const owner = `${testInfo.project.name}-${testInfo.repeatEachIndex}`;
        const email = `playwright-appearance-${owner}@example.test`;
        stateFile = `playwright/.auth/appearance-${owner}.json`;
        artisan('aperture:e2e:prepare', `--account=${email}`);
        await saveLoginState(browser, { email, password: 'playwright-attendee-password' }, stateFile);
    });

    test('attendees reach Appearance from the user menu and adjust every setting', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.goto('/portal');
        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu-settings')).toHaveCount(0);
        await page.getByTestId('user-menu-appearance').click();

        await expect(page.getByTestId('settings-page')).toBeVisible();
        await expect(page.getByTestId('portal-layout')).toBeVisible();
        await expect(page.getByTestId('appearance-section')).toBeVisible();

        await setSlider(page, 100);
        await expect(html(page)).toHaveCSS('--bg-intensity', '1.75');
        await page.getByTestId('appearance-glass-sheen').click();
        await expect(html(page)).toHaveAttribute('data-sheen', 'off');
        await page.getByTestId('appearance-animated-background').click();
        await expect(html(page)).toHaveAttribute('data-animated-bg', 'off');
        await page.getByTestId('appearance-reduce-transparency').click();
        await expect(html(page)).toHaveAttribute('data-transparency', 'reduced');

        await page.reload();
        await expect(page.getByTestId('appearance-background-intensity')).toHaveValue('100');
        await expect(page.getByTestId('appearance-glass-sheen')).toHaveAttribute('aria-checked', 'false');
        await expect(page.getByTestId('appearance-animated-background')).toHaveAttribute('aria-checked', 'false');
        await expect(page.getByTestId('appearance-reduce-transparency')).toHaveAttribute('aria-checked', 'true');
    });
});
