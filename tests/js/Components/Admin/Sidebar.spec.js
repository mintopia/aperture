import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi } from 'vitest';
import Sidebar from '@/Components/Admin/Sidebar.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/users' }),
}));

const navExpectations = [
    ['dashboard', '/admin'],
    ['users', '/admin/users'],
    ['ip-addresses', '/admin/ips'],
    ['switches', '/admin/switches'],
    ['dhcp', '/admin/dhcp'],
    ['integrations', '/admin/settings/integrations'],
    ['ipv6-detection', '/admin/settings/ipv6-detection'],
    ['dns-detection', '/admin/settings/dns-detection'],
    ['dashboard', '/admin/content'],
    ['pages', '/admin/content/pages'],
    ['theme', '/admin/settings/theme'],
    ['settings', '/admin/content/settings'],
];

function setViewport(width) {
    window.innerWidth = width;
    window.dispatchEvent(new Event('resize'));
}

async function mountSidebar(width = 1280) {
    setViewport(width);
    const wrapper = mount(Sidebar);
    await nextTick();

    return wrapper;
}

describe('Sidebar.vue', () => {
    it('renders section headers in the correct desktop order', async () => {
        const wrapper = await mountSidebar();
        const headers = wrapper.findAll('aside nav section > p').map((header) => header.text());

        expect(wrapper.get('[data-testid="admin-sidebar"]').exists()).toBe(true);
        expect(headers).toEqual(['MANAGEMENT', 'SERVICES', 'CONTENT']);
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
        ]);
    });

    it('renders Integrations, IPv6 Detection, and DNS Detection under SERVICES', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[1].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Integrations',
            'IPv6 Detection',
            'DNS Detection',
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

    it('renders all nav items with correct hrefs and preserved test ids', async () => {
        const wrapper = await mountSidebar();
        const allLinks = wrapper.findAll('[data-testid^="nav-"]');

        const hrefsByTestId = allLinks.map((link) => [link.attributes('data-testid'), link.attributes('href')]);

        expect(hrefsByTestId).toContainEqual(['nav-dashboard', '/admin']);
        expect(hrefsByTestId).toContainEqual(['nav-users', '/admin/users']);
        expect(hrefsByTestId).toContainEqual(['nav-ip-addresses', '/admin/ips']);
        expect(hrefsByTestId).toContainEqual(['nav-switches', '/admin/switches']);
        expect(hrefsByTestId).toContainEqual(['nav-dhcp', '/admin/dhcp']);
        expect(hrefsByTestId).toContainEqual(['nav-integrations', '/admin/settings/integrations']);
        expect(hrefsByTestId).toContainEqual(['nav-ipv6-detection', '/admin/settings/ipv6-detection']);
        expect(hrefsByTestId).toContainEqual(['nav-dns-detection', '/admin/settings/dns-detection']);
        expect(hrefsByTestId).toContainEqual(['nav-pages', '/admin/content/pages']);
        expect(hrefsByTestId).toContainEqual(['nav-theme', '/admin/settings/theme']);
        expect(hrefsByTestId).toContainEqual(['nav-settings', '/admin/content/settings']);
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
        const wrapper = await mountSidebar(768);
        const mobileNav = wrapper.get('[data-testid="admin-nav-horizontal"]');
        const items = mobileNav.findAll('[data-testid^="nav-"]').map((item) => item.text());

        expect(mobileNav.exists()).toBe(true);
        expect(items).toEqual([
            'Dashboard',
            'Users',
            'IP Addresses',
            'Switches',
            'DHCP',
            'Integrations',
            'IPv6 Detection',
            'DNS Detection',
            'Dashboard',
            'Pages',
            'Theme',
            'Settings',
        ]);
    });
});
