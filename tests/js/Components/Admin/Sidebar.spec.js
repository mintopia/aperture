import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const routeMap = {
    'admin.home': '/admin',
    'admin.users.index': '/admin/users',
    'admin.ips.index': '/admin/ips',
    'admin.switches.index': '/admin/switches',
    'admin.dhcp.index': '/admin/dhcp',
    'admin.macs.index': '/admin/macs',
    'admin.settings.integrations': '/admin/settings/integrations',
    'admin.settings.ipv6-detection': '/admin/settings/ipv6-detection',
    'admin.settings.dns-detection': '/admin/settings/dns-detection',
    'admin.settings.network': '/admin/settings/network',
    'admin.content.index': '/admin/content',
    'admin.content.pages.index': '/admin/content/pages',
    'admin.settings.theme': '/admin/settings/theme',
    'admin.content.settings': '/admin/content/settings',
    'admin.audit-log.index': '/admin/audit-log',
};

window.route = vi.fn((name) => routeMap[name] || `/${name}`);

let mqlListeners = [];
let mqlMatches = true;

const mockMql = {
    get matches() {
        return mqlMatches;
    },
    addEventListener: vi.fn((_event, cb) => {
        mqlListeners.push(cb);
    }),
    removeEventListener: vi.fn((_event, cb) => {
        mqlListeners = mqlListeners.filter((listener) => listener !== cb);
    }),
};

window.matchMedia = vi.fn(() => mockMql);

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/users' }),
}));

// Import Sidebar after mocks are set up (vi.mock is hoisted, but window mocks are not)
const { default: Sidebar } = await import('@/Components/Admin/Sidebar.vue');

function triggerBreakpointChange(matches) {
    mqlMatches = matches;
    mqlListeners.forEach((cb) => cb({ matches }));
}

beforeEach(() => {
    mqlListeners = [];
    mqlMatches = true;
    mockMql.addEventListener.mockClear();
    mockMql.removeEventListener.mockClear();
    window.matchMedia.mockClear();
    window.route.mockClear();
});

async function mountSidebar(desktop = true) {
    mqlMatches = desktop;
    const wrapper = mount(Sidebar);
    await nextTick();

    return wrapper;
}

describe('Sidebar.vue', () => {
    it('renders section headers in the correct desktop order', async () => {
        const wrapper = await mountSidebar();
        const headers = wrapper.findAll('aside nav section > p').map((header) => header.text());

        expect(wrapper.get('[data-testid="admin-sidebar"]').exists()).toBe(true);
        expect(headers).toEqual(['MANAGEMENT', 'SERVICES', 'CONTENT', 'SYSTEM']);
    });

    it('does not render OVERVIEW or TOOLS groups', async () => {
        const wrapper = await mountSidebar();
        const headers = wrapper.findAll('aside nav section > p').map((header) => header.text());

        expect(headers).not.toContain('OVERVIEW');
        expect(headers).not.toContain('TOOLS');
    });

    it('renders Dashboard, Users, IP Addresses, Switches, and DHCP under MANAGEMENT', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[0].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Dashboard',
            'Users',
            'IP Addresses',
            'Switches',
            'DHCP',
            'MAC Addresses',
        ]);
    });

    it('renders Integrations, IPv6 Detection, and DNS Detection under SERVICES', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[1].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Integrations',
            'IPv6 Detection',
            'DNS Detection',
            'Network',
        ]);
    });

    it('renders Dashboard, Pages, Theme, and Settings under CONTENT', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[2].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Dashboard',
            'Pages',
            'Theme',
            'Settings',
        ]);
    });

    it('renders Audit Log under SYSTEM', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[3].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual(['Audit Log']);
    });

    it('renders all nav items with correct hrefs from named routes', async () => {
        const wrapper = await mountSidebar();
        const allLinks = wrapper.findAll('[data-testid^="nav-"]');

        const hrefsByTestId = allLinks.map((link) => [link.attributes('data-testid'), link.attributes('href')]);

        expect(hrefsByTestId).toContainEqual(['nav-dashboard', '/admin']);
        expect(hrefsByTestId).toContainEqual(['nav-users', '/admin/users']);
        expect(hrefsByTestId).toContainEqual(['nav-ip-addresses', '/admin/ips']);
        expect(hrefsByTestId).toContainEqual(['nav-switches', '/admin/switches']);
        expect(hrefsByTestId).toContainEqual(['nav-dhcp', '/admin/dhcp']);
        expect(hrefsByTestId).toContainEqual(['nav-mac-addresses', '/admin/macs']);
        expect(hrefsByTestId).toContainEqual(['nav-integrations', '/admin/settings/integrations']);
        expect(hrefsByTestId).toContainEqual(['nav-ipv6-detection', '/admin/settings/ipv6-detection']);
        expect(hrefsByTestId).toContainEqual(['nav-dns-detection', '/admin/settings/dns-detection']);
        expect(hrefsByTestId).toContainEqual(['nav-network', '/admin/settings/network']);
        expect(hrefsByTestId).toContainEqual(['nav-pages', '/admin/content/pages']);
        expect(hrefsByTestId).toContainEqual(['nav-theme', '/admin/settings/theme']);
        expect(hrefsByTestId).toContainEqual(['nav-settings', '/admin/content/settings']);
        expect(hrefsByTestId).toContainEqual(['nav-audit-log', '/admin/audit-log']);
    });

    it('calls route() with correct named route identifiers', async () => {
        await mountSidebar();

        expect(window.route).toHaveBeenCalledWith('admin.home');
        expect(window.route).toHaveBeenCalledWith('admin.users.index');
        expect(window.route).toHaveBeenCalledWith('admin.ips.index');
        expect(window.route).toHaveBeenCalledWith('admin.switches.index');
        expect(window.route).toHaveBeenCalledWith('admin.dhcp.index');
        expect(window.route).toHaveBeenCalledWith('admin.macs.index');
        expect(window.route).toHaveBeenCalledWith('admin.settings.integrations');
        expect(window.route).toHaveBeenCalledWith('admin.settings.ipv6-detection');
        expect(window.route).toHaveBeenCalledWith('admin.settings.dns-detection');
        expect(window.route).toHaveBeenCalledWith('admin.settings.network');
        expect(window.route).toHaveBeenCalledWith('admin.content.index');
        expect(window.route).toHaveBeenCalledWith('admin.content.pages.index');
        expect(window.route).toHaveBeenCalledWith('admin.settings.theme');
        expect(window.route).toHaveBeenCalledWith('admin.content.settings');
        expect(window.route).toHaveBeenCalledWith('admin.audit-log.index');
    });

    it('renders SVG icon components with aria-hidden instead of v-html', async () => {
        const wrapper = await mountSidebar();
        const svgs = wrapper.findAll('[data-testid^="nav-"] svg');

        expect(svgs.length).toBe(15);
        svgs.forEach((svg) => {
            expect(svg.attributes('aria-hidden')).toBe('true');
            expect(svg.attributes('stroke')).toBe('currentColor');
        });
    });

    it('does not use v-html for icon rendering', async () => {
        const wrapper = await mountSidebar();

        expect(wrapper.html()).not.toContain('v-html');
    });

    it('applies active styling with accent background and primary text', async () => {
        const wrapper = await mountSidebar();
        const activeItem = wrapper.get('[data-testid="nav-users"]');

        expect(activeItem.classes()).toContain('bg-[var(--color-accent-dim)]');
        expect(activeItem.classes()).toContain('text-[var(--color-primary)]');
        expect(activeItem.classes()).toContain('font-semibold');
    });

    it('applies secondary text color to non-active items', async () => {
        const wrapper = await mountSidebar();
        // Find the first Dashboard link (nav-dashboard in MANAGEMENT group)
        const inactiveItem = wrapper.findAll('[data-testid="nav-dashboard"]')[0];

        expect(inactiveItem.classes()).toContain('text-[var(--color-text-secondary)]');
    });

    it('renders the Aperture branded header on desktop', async () => {
        const wrapper = await mountSidebar();

        expect(wrapper.text()).toContain('Aperture');
    });

    it('renders grouped horizontal navigation on mobile and includes all items', async () => {
        const wrapper = await mountSidebar(false);
        const mobileNav = wrapper.get('[data-testid="admin-nav-horizontal"]');
        const items = mobileNav.findAll('[data-testid^="nav-"]').map((item) => item.text());

        expect(mobileNav.exists()).toBe(true);
        expect(items).toEqual([
            'Dashboard',
            'Users',
            'IP Addresses',
            'Switches',
            'DHCP',
            'MAC Addresses',
            'Integrations',
            'IPv6 Detection',
            'DNS Detection',
            'Network',
            'Dashboard',
            'Pages',
            'Theme',
            'Settings',
            'Audit Log',
        ]);
    });

    it('uses matchMedia instead of resize listener for breakpoint detection', async () => {
        await mountSidebar();

        expect(window.matchMedia).toHaveBeenCalledWith('(min-width: 1025px)');
    });

    it('responds to matchMedia change events', async () => {
        const wrapper = await mountSidebar(true);

        expect(wrapper.find('[data-testid="admin-sidebar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-nav-horizontal"]').exists()).toBe(false);

        triggerBreakpointChange(false);
        await nextTick();

        expect(wrapper.find('[data-testid="admin-sidebar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="admin-nav-horizontal"]').exists()).toBe(true);
    });

    it('cleans up matchMedia listener on unmount', async () => {
        const wrapper = await mountSidebar();

        expect(mockMql.addEventListener).toHaveBeenCalledWith('change', expect.any(Function));

        wrapper.unmount();

        expect(mockMql.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function));
    });
});
