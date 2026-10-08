// The admin layout swaps the fixed sidebar for a slide-in drawer below this width (see AdminLayout/Sidebar).
export const DESKTOP_MIN_WIDTH = 1025;

export function isDesktopViewport(page) {
    return page.viewportSize().width >= DESKTOP_MIN_WIDTH;
}

// Phone-sized viewports are where the header search trigger collapses to an icon and tables stack.
export function isPhoneViewport(page) {
    return page.viewportSize().width < 640;
}

/**
 * Returns the locator holding the admin navigation, opening the drawer first when the
 * viewport is too narrow for the fixed sidebar.
 */
export async function openAdminNav(page) {
    if (isDesktopViewport(page)) {
        return page.getByTestId('admin-sidebar');
    }
    const drawer = page.getByTestId('admin-drawer');
    if (!(await drawer.isVisible())) {
        await page.getByTestId('admin-menu-toggle').click();
    }
    await drawer.waitFor({ state: 'visible' });
    return drawer;
}
